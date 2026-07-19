<?php

namespace App\Game;

/**
 * Orquesta un combate dentro del maze (docs/DECISIONES.md 018, implementa 016):
 * deriva el monstruo de la celda como función del seed, resuelve cada acción
 * reusando CombatResolver (la autoridad del daño, 012), y genera el drop al
 * matarlo. Es lógica pura y determinista: opera sobre los blobs de estado
 * (arrays), no sabe de HTTP ni de la base, y se testea sin levantarla
 * (CLAUDE.md, excepción de app/Game/).
 *
 * El cliente no tiene ninguna verdad de combate (axioma 4): manda la acción y
 * el servidor devuelve el estado nuevo. La semilla de combate se deriva acá de
 * (seed, celda, índice del encuentro) y nunca viaja al cliente.
 *
 * Todos los números son de arranque (tuning, se ajustan jugando). La rueda
 * elemental es la del CombatResolver (placeholder, ❓ docs/DISENO.md §3).
 */
final class MazeCombate
{
    /**
     * Arquetipos de monstruo por elemento (DECISIÓN 029). El arquetipo es la
     * FORMA; el nivel del bicho (por distancia) es el TIER que escala todo.
     * `vida`/`defensa` son las bases a nivel 1 (se escalan por factor de nivel);
     * `coefPeso` es el peso POR NIVEL (peso = round(coefPeso × nivel)): tierra
     * golpea pesado y caro de frenar (1.25), aire es una brisa (0.75), fuego y
     * agua en el medio (1.0). `coefDestreza` (2 − coefPeso) es la INVERSA del
     * peso: cuánto cuesta escaparle (DECISIÓN 030) — el gólem lento es barato de
     * esquivar (0.75), la sílfide veloz es cara (1.25). `dificultad` ya solo pesa
     * el multi-drop (027). Números de arranque.
     */
    private const ARQUETIPOS = [
        'fuego' => ['nombre' => 'Espectro ígneo', 'vida' => 45, 'defensa' => 18, 'coefPeso' => 1.0, 'coefDestreza' => 1.0, 'dificultad' => 3],
        'agua' => ['nombre' => 'Ondina', 'vida' => 60, 'defensa' => 22, 'coefPeso' => 1.0, 'coefDestreza' => 1.0, 'dificultad' => 3],
        'tierra' => ['nombre' => 'Gólem de tierra', 'vida' => 58, 'defensa' => 30, 'coefPeso' => 1.25, 'coefDestreza' => 0.75, 'dificultad' => 4],
        'aire' => ['nombre' => 'Sílfide del aire', 'vida' => 45, 'defensa' => 16, 'coefPeso' => 0.75, 'coefDestreza' => 1.25, 'dificultad' => 2],
    ];

    /**
     * Hoja de personaje inicial: nivel 1 y una gema n3 de cada elemento
     * (4 × 3 = 12 = cap a nivel 1). El nivel es la fuente de verdad de la
     * progresión (024); cap y defensa los deriva Talisman::recomputar.
     */
    public static function talismanInicial(): array
    {
        return Talisman::recomputar([
            'nivel' => 1,
            'vida' => 40,
            'vidaMax' => 40,
            'cap' => 0,     // lo fija recomputar desde el nivel
            'defensa' => 0, // idem
            'esencia' => 0,
            'gemas' => [
                ['id' => 1, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 18, 'fieldeada' => true],
                ['id' => 2, 'elemento' => 'agua', 'nivel' => 3, 'carga' => 18, 'fieldeada' => true],
                ['id' => 3, 'elemento' => 'tierra', 'nivel' => 3, 'carga' => 18, 'fieldeada' => true],
                ['id' => 4, 'elemento' => 'aire', 'nivel' => 3, 'carga' => 18, 'fieldeada' => true],
            ],
            'proximoId' => 5,
            'bichosCaidos' => 0,
            'gemasJuntadas' => 0,
        ]);
    }

    /**
     * Arranca un combate en la celda (x,y). El elemento del monstruo sale del
     * encuentro (o, para un encuentro de ambiente sin elemento, se sortea). La
     * distancia a la entrada `$t` (0.0..1.0, MapaBuilder::dificultadCelda) fija
     * el NIVEL entero del bicho (1 en la entrada, 7 en el fondo, DECISIÓN 029):
     * el tier único del que sale todo. Vida y defensa escalan por
     * `factor = 1 + (nivel−1)/6` (1.0 a N1, 2.0 a N7) sobre las bases del
     * arquetipo; la prob de la celda suma un plus de vida (colmena caliente =
     * bicho más duro). El peso del golpe sale de `coefPeso × nivel` (029): así
     * el costo de frenarlo crece con la profundidad y no queda ridículo frente a
     * la carga de las gemas grandes. El costo de ESCAPARLE sale de `coefDestreza
     * × nivel` (030): la inversa del peso, así el pesado es barato de esquivar y
     * el liviano caro. `t` se guarda para el drop (027).
     *
     * @param  float  $t  Distancia normalizada a la entrada.
     * @return array combate: {x,y,t,monstruo,turno,entrante,semilla,paso,resultado}
     */
    public static function iniciar(int $seed, int $x, int $y, ?string $elem, int $prob, int $indice, float $t = 0.0): array
    {
        $semilla = ($seed ^ ($x * 73856093) ^ ($y * 19349663) ^ ($indice * 83492791)) & 0xFFFFFFFF;
        $prng = new Prng($semilla);

        // El dado de disparo del encuentro (cliente, DECISIÓN 022) se juega el PRIMER
        // output de esta MISMA semilla. Si el elemento saliera de él, quedaría atado a
        // la condición de disparo: en una celda de ambiente (prob=1) el encuentro solo
        // salta cuando first%100==0, y como 100=4×25 eso fuerza first%4==0 → siempre
        // fuego (DECISIÓN 031). Se quema el primer output y el elemento sale del segundo.
        $prng->next();
        $elem ??= EncuentroBuilder::ELEMENTOS[$prng->randBelow(count(EncuentroBuilder::ELEMENTOS))];
        $base = self::ARQUETIPOS[$elem];

        $nivel = max(1, min(7, (int) round(1 + $t * 6)));
        $factor = 1 + ($nivel - 1) / 6; // 1.0 a N1, 2.0 a N7. Números de arranque.
        $vida = (int) round(($base['vida'] + $prob) * $factor);

        return [
            'x' => $x,
            'y' => $y,
            't' => $t,
            'monstruo' => [
                'nombre' => $base['nombre'],
                'elemento' => $elem,
                'nivel' => $nivel,
                'vida' => $vida,
                'vidaMax' => $vida,
                'defensa' => (int) round($base['defensa'] * $factor),
                'peso' => (int) round($base['coefPeso'] * $nivel),
                'escape' => max(1, (int) round($base['coefDestreza'] * $nivel)),
                'dificultad' => $base['dificultad'],
            ],
            'turno' => 'tuTurno',
            'entrante' => null,
            'semilla' => $semilla,
            'paso' => 1, // el 0 lo consumió la derivación del monstruo
            'resultado' => null,
        ];
    }

    /** Cuántas llaves (guardianes de llave) tiene el maze: índices 0..2 (DECISIÓN 032). */
    public const CANT_LLAVES = 3;

    /** El índice del guardián de la salida (no deja llave: vencerlo es la victoria final). */
    public const INDICE_SALIDA = 3;

    /**
     * Niveles fijos de los guardianes por índice (DECISIÓN 032): las tres llaves
     * a 4/6/8 y la salida a 10 — todos por encima del techo 1..7 de gemas y
     * monstruos de ambiente, en rampa creciente. La salida a N10 es el pico
     * deliberado: se gana por matchup + carga, no out-levelándolo. Números de
     * arranque, subidos desde 3/5/7/9 para endurecer los bosses (tuning).
     */
    private const NIVELES_GUARDIAN = [4, 6, 8, 10];

    /**
     * Arma el guardián telegrafiado de una llave (índice 0..2) o de la salida
     * (índice 3), DECISIÓN 032. A diferencia de un encuentro de ambiente, el
     * nivel es FIJO por índice (no sale de la distancia, 029) y NO hay escape
     * (`escape` null, y `resolver` rechaza 'escapar'): o lo matás o te mata. El
     * arquetipo es elemental escalado a ese nivel, telegrafiado; el elemento sale
     * del seed (determinista, pero solo lo consume el servidor — axioma 4). La
     * semilla de combate se deriva de (seed, índice) y nunca viaja al cliente. Al
     * morir (victoriaBoss) otorga la llave; perder = derrota = mapa nuevo, sin red.
     *
     * @return array combate, misma forma que iniciar() con boss:true e indice.
     */
    public static function guardian(int $seed, int $indice, int $x, int $y): array
    {
        $semilla = ($seed ^ ($indice * 0x9E3779B1) ^ 0x6A09E667) & 0xFFFFFFFF;
        $prng = new Prng($semilla);

        $elem = EncuentroBuilder::ELEMENTOS[$prng->randBelow(count(EncuentroBuilder::ELEMENTOS))];
        $base = self::ARQUETIPOS[$elem];

        $nivel = self::NIVELES_GUARDIAN[$indice];
        $factor = 1 + ($nivel - 1) / 6; // misma rampa que un bicho de ese nivel (029)
        $vida = (int) round($base['vida'] * $factor);

        return [
            'x' => $x,
            'y' => $y,
            't' => 1.0,
            'monstruo' => [
                'nombre' => $base['nombre'],
                'elemento' => $elem,
                'nivel' => $nivel,
                'vida' => $vida,
                'vidaMax' => $vida,
                'defensa' => (int) round($base['defensa'] * $factor),
                'peso' => (int) round($base['coefPeso'] * $nivel),
                'escape' => null,
                'boss' => true,
                'indice' => $indice,
                'dificultad' => $base['dificultad'],
            ],
            'turno' => 'tuTurno',
            'entrante' => null,
            'semilla' => $semilla,
            'paso' => 1, // el 0 lo consumió la derivación del elemento
            'resultado' => null,
        ];
    }

    /**
     * Resuelve una acción de combate y devuelve el estado nuevo. Acciones:
     * 'atacar' (gemaId), 'bloquear' (gemaId) y 'escapar'. El combate vuelve null
     * cuando termina; `resultado` dice cómo ('victoria' | 'derrota' | 'huida').
     * La defensa es una sola acción (029): el golpe entrante SIEMPRE se paga por
     * el talismán —carga primero, el déficit a vida ×3— así que no hay 'comer' ni
     * bloqueo rechazado. Escapar (030) solo en tu turno: pagás esencia (el costo
     * de escape del bicho) y cerrás el combate sin botín; la colmena sigue viva.
     *
     * @return array{
     *     combate: array|null, talisman: array, resultado: string|null,
     *     drop: array|null, error: string|null, log: list<array{txt:string,tipo:string}>
     *
     * `tipo` es la categoría de la línea para la bitácora del cliente: 'ataque',
     * 'critico', 'bloqueo', 'arremete', 'botin', 'llave', 'huida' o 'derrota'.
     * }
     */
    public static function resolver(array $combate, array $talisman, string $accion, ?int $gemaId): array
    {
        $log = [];
        $error = fn (string $m) => [
            'combate' => $combate, 'talisman' => $talisman, 'resultado' => null,
            'drop' => null, 'error' => $m, 'log' => [],
        ];

        if ($accion === 'atacar') {
            if ($combate['turno'] !== 'tuTurno') {
                return $error('no es tu turno');
            }
            $g = self::gema($talisman, $gemaId, true);
            if ($g === null) {
                return $error('gema inválida');
            }

            $r = self::golpe($combate, $g['nivel'], $g['elemento'], $combate['monstruo']['defensa'], $combate['monstruo']['elemento'], $talisman['ataqueMult'] ?? 0);

            if ($g['carga'] >= $r['costoEsencia']) {
                self::gastarGema($talisman, $gemaId, $r['costoEsencia']);
                $log[] = $r['critico']
                    ? ['txt' => "★ ¡CRÍTICO! Tu {$g['elemento']} (nv.{$g['nivel']}) desgarra por {$r['dano']} — " . self::matchupFrase($r['matchup']) . ". −{$r['costoEsencia']} esencia.", 'tipo' => 'critico']
                    : ['txt' => "Canalizás {$g['elemento']} (nv.{$g['nivel']}): {$r['dano']} de daño, " . self::matchupFrase($r['matchup']) . ". −{$r['costoEsencia']} esencia.", 'tipo' => 'ataque'];
            } else {
                // Carga insuficiente: se gasta la que haya y el faltante se paga
                // con vida a la penalidad de la 021 (cubre la gema extinta y el
                // pago parcial en una sola regla).
                $faltante = $r['costoEsencia'] - $g['carga'];
                $costoVida = (new CombatResolver(new Prng(0)))->costoVida($faltante);
                if ($g['carga'] > 0) {
                    self::gastarGema($talisman, $gemaId, $g['carga']);
                }
                $talisman['vida'] = max(0, $talisman['vida'] - $costoVida);
                $detalle = $g['carga'] > 0 ? "{$g['carga']} de esencia y {$costoVida} de vida" : "{$costoVida} de vida";
                $log[] = $r['critico']
                    ? ['txt' => "★ ¡CRÍTICO a pulso! Tu {$g['elemento']} (nv.{$g['nivel']}) seca golpea por {$r['dano']} — " . self::matchupFrase($r['matchup']) . ". Lo pagás con {$detalle}.", 'tipo' => 'critico']
                    : ['txt' => "Fuerzas {$g['elemento']} (nv.{$g['nivel']}) sin carga: {$r['dano']} de daño, " . self::matchupFrase($r['matchup']) . ". Lo pagás con {$detalle}.", 'tipo' => 'ataque'];
            }

            $combate['monstruo']['vida'] = max(0, $combate['monstruo']['vida'] - $r['dano']);

            if ($combate['monstruo']['vida'] <= 0) {
                return self::victoria($combate, $talisman, $log);
            }
            if ($talisman['vida'] <= 0) {
                return self::derrota($combate, $talisman, $log);
            }

            return self::golpeMonstruo($combate, $talisman, $log);
        }

        if ($accion === 'bloquear') {
            if ($combate['turno'] !== 'defensa' || $combate['entrante'] === null) {
                return $error('no hay golpe entrante');
            }
            $g = self::gema($talisman, $gemaId, true);
            if ($g === null) {
                return $error('gema inválida');
            }
            $e = $combate['entrante'];
            // El costo de bloqueo es determinista (peso × elemento, sin azar): un
            // recurso a presupuestar, no una tirada. La carga paga primero; lo que
            // falte se paga con vida ×3, igual que castear una gema seca (029).
            $resolver = new CombatResolver(new Prng(0));
            $costo = $resolver->costoBloqueo($e['peso'], $g['elemento'], $e['elemento']);
            $cargaPaga = min($g['carga'], $costo);
            $deficit = $costo - $cargaPaga;
            $costoVida = $resolver->costoVida($deficit);

            if ($cargaPaga > 0) {
                self::gastarGema($talisman, $gemaId, $cargaPaga);
            }
            $talisman['vida'] = max(0, $talisman['vida'] - $costoVida);

            if ($deficit === 0) {
                $log[] = ['txt' => "Alzás {$g['elemento']} y frenás el golpe en seco. −{$costo} esencia.", 'tipo' => 'bloqueo'];
            } else {
                $detalle = $cargaPaga > 0 ? "{$cargaPaga} de esencia y {$costoVida} de vida" : "{$costoVida} de vida";
                $log[] = ['txt' => "Alzás {$g['elemento']}, pero la carga no alcanza: absorbés el golpe con {$detalle}.", 'tipo' => 'bloqueo'];
            }

            if ($talisman['vida'] <= 0) {
                return self::derrota($combate, $talisman, $log);
            }
            $combate['turno'] = 'tuTurno';
            $combate['entrante'] = null;

            return self::estado($combate, $talisman, $log);
        }

        if ($accion === 'escapar') {
            if ($combate['turno'] !== 'tuTurno') {
                return $error('no es tu turno');
            }
            if ($combate['monstruo']['boss'] ?? false) {
                return $error('no se puede escapar de un guardián');
            }
            $costo = $combate['monstruo']['escape'];
            if ($talisman['esencia'] < $costo) {
                return $error('esencia insuficiente para escapar');
            }
            $talisman['esencia'] -= $costo;
            $log[] = ['txt' => "Te zafás de {$combate['monstruo']['nombre']} y desaparecés en la oscuridad. −{$costo} esencia.", 'tipo' => 'huida'];

            return self::huida($talisman, $log);
        }

        return $error('acción desconocida');
    }

    /**
     * Un golpe contra un objetivo (consume un paso de la semilla de combate).
     * `$bonusAtaque` es el acople gema→ataque de la hoja del atacante (024): lo
     * pasa el mago (su ataqueMult), el monstruo golpea con el default 0.
     */
    private static function golpe(array &$combate, int $nivel, string $elemAtacante, int $defensa, string $elemDefensor, float $bonusAtaque = 0.0): array
    {
        $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);

        return (new CombatResolver($prng))->golpe($nivel, $elemAtacante, $defensa, $elemDefensor, $bonusAtaque);
    }

    /**
     * El monstruo arremete: pasa a defensa y fija el entrante. Ya no lleva daño
     * propio (029) — la amenaza ES su peso (por nivel), y el daño real se decide
     * al bloquear según lo que la carga no cubra. Determinista, sin tirada.
     */
    private static function golpeMonstruo(array $combate, array $talisman, array $log): array
    {
        $m = $combate['monstruo'];
        $combate['turno'] = 'defensa';
        $combate['entrante'] = ['elemento' => $m['elemento'], 'peso' => $m['peso']];
        $log[] = ['txt' => "{$m['nombre']} arremete ({$m['elemento']}, peso {$m['peso']}) — bloqueá con una gema o lo pagás con vida.", 'tipo' => 'arremete'];

        return self::estado($combate, $talisman, $log);
    }

    /**
     * Muerte del monstruo: genera el botín (una o más piedras, según la
     * dificultad), lo mete al inventario y cierra el combate. La cantidad sale
     * del mismo PRNG de combate (determinista, replayable): un bicho más difícil
     * es más probable que suelte una segunda piedra.
     */
    private static function victoria(array $combate, array $talisman, array $log): array
    {
        if ($combate['monstruo']['boss'] ?? false) {
            return self::victoriaBoss($combate, $talisman, $log);
        }

        $dificultad = $combate['monstruo']['dificultad'];
        $t = $combate['t'] ?? 0.0;
        $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);
        $cantidad = 1 + ($prng->randBelow(4) < $dificultad ? 1 : 0);

        $drops = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $gema = self::drop($prng, $t, $talisman['proximoId'], $combate['monstruo']['elemento']);
            $talisman['gemas'][] = $gema;
            $talisman['proximoId']++;
            $talisman['gemasJuntadas']++;
            $drops[] = $gema;
        }
        $talisman['bichosCaidos']++;

        $lista = implode(', ', array_map(fn ($g) => "{$g['elemento']} nv.{$g['nivel']}", $drops));
        $log[] = ['txt' => "☠ Cae {$combate['monstruo']['nombre']}. Entre los restos hallás {$lista} → al inventario.", 'tipo' => 'botin'];

        return [
            'combate' => null, 'talisman' => $talisman, 'resultado' => 'victoria',
            'drop' => $drops, 'error' => null, 'log' => $log,
        ];
    }

    /**
     * Muerte de un guardián (DECISIÓN 032): otorga la llave de su índice y suelta
     * UNA gema garantizada (§6, los bosses garantizan gema importante), de nivel
     * atado al del guardián y topeado en 7 (el techo de gema del jugador). El
     * índice viaja en `llave` para que el controlador registre la llave — o, en el
     * índice de la salida (3), la victoria final. El elemento del botín se pesa por
     * la afinidad del guardián (026), como cualquier drop.
     */
    private static function victoriaBoss(array $combate, array $talisman, array $log): array
    {
        $m = $combate['monstruo'];
        $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);

        $nivel = min($m['nivel'], 7);
        $gema = [
            'id' => $talisman['proximoId'],
            'elemento' => self::elementoDrop($prng, $m['elemento']),
            'nivel' => $nivel,
            'carga' => $nivel * Talisman::CARGA_POR_NIVEL,
            'fieldeada' => false,
        ];
        $talisman['gemas'][] = $gema;
        $talisman['proximoId']++;
        $talisman['gemasJuntadas']++;
        $talisman['bichosCaidos']++;

        $esSalida = $m['indice'] === count(self::NIVELES_GUARDIAN) - 1;
        $premio = $esSalida
            ? 'El camino a la salida queda libre'
            : 'La llave es tuya';
        $log[] = [
            'txt' => "🗝 Cae {$m['nombre']}. {$premio}, y de su cuerpo brota {$gema['elemento']} nv.{$gema['nivel']} → al inventario.",
            'tipo' => 'llave',
        ];

        return [
            'combate' => null, 'talisman' => $talisman, 'resultado' => 'victoria',
            'drop' => [$gema], 'llave' => $m['indice'], 'error' => null, 'log' => $log,
        ];
    }

    /**
     * Escape (030): cierra el combate como huida. Sin botín y sin terminar la
     * partida — la colmena queda viva y el mago sigue en pie con menos esencia.
     */
    private static function huida(array $talisman, array $log): array
    {
        return [
            'combate' => null, 'talisman' => $talisman, 'resultado' => 'huida',
            'drop' => null, 'error' => null, 'log' => $log,
        ];
    }

    /** Vida en 0: cierra el combate como derrota. */
    private static function derrota(array $combate, array $talisman, array $log): array
    {
        $log[] = ['txt' => '✖ Tu vida se apaga. Caés en el laberinto y la oscuridad te reclama.', 'tipo' => 'derrota'];

        return [
            'combate' => null, 'talisman' => $talisman, 'resultado' => 'derrota',
            'drop' => null, 'error' => null, 'log' => $log,
        ];
    }

    /** Empaqueta el estado sin cerrar el combate. */
    private static function estado(array $combate, array $talisman, array $log): array
    {
        return [
            'combate' => $combate, 'talisman' => $talisman, 'resultado' => null,
            'drop' => null, 'error' => null, 'log' => $log,
        ];
    }

    /**
     * Traduce el matchup crudo del CombatResolver ('ventaja'/'reves'/'neutral')
     * a la frase que lee el jugador en la bitácora. Solo cosmético: el número de
     * daño ya viene resuelto con el multiplicador aplicado.
     */
    private static function matchupFrase(string $matchup): string
    {
        return match ($matchup) {
            'ventaja' => 'con ventaja elemental',
            'reves' => 'a la contra, con desventaja',
            default => 'sin ventaja de elemento',
        };
    }

    /**
     * Gema del botín: elemento pesado por la afinidad del monstruo (026), nivel
     * deslizado por la distancia a la entrada (DECISIÓN 027 punto 2). La carga
     * nace llena (nivel × 6) para que el neto por pelea sea positivo (si no, es
     * espiral de muerte). Números de arranque.
     */
    private static function drop(Prng $prng, float $t, int $id, string $elemMonstruo): array
    {
        $elemento = self::elementoDrop($prng, $elemMonstruo);
        $nivel = self::nivelDrop($prng, $t);

        return ['id' => $id, 'elemento' => $elemento, 'nivel' => $nivel, 'carga' => $nivel * Talisman::CARGA_POR_NIVEL, 'fieldeada' => false];
    }

    /**
     * Nivel de una piedra dropeada según la distancia a la entrada `$t`
     * (0.0..1.0, DECISIÓN 027 punto 2). Distribución "tienda de campaña" sobre
     * los niveles 1..7, con el centro corriendo de 2.5 (entrada → N2/N3) a 5.5
     * (salida → pico N5/N6). La pendiente 14 deja un N7 de ~14% en el fondo del
     * maze (≤15% buscado) y colas suaves en el resto. Recorre 1..7 en orden fijo
     * y camina una tirada sobre el acumulado — determinista, replayable. Números
     * de arranque (tuning, se ajustan jugando).
     */
    private static function nivelDrop(Prng $prng, float $t): int
    {
        $centro = 2.5 + 3.0 * $t;

        $pesos = [];
        $suma = 0;
        for ($n = 1; $n <= 7; $n++) {
            $peso = (int) max(0, round(30 - 14 * abs($n - $centro)));
            $pesos[$n] = $peso;
            $suma += $peso;
        }

        $tirada = $prng->randBelow($suma);
        $acum = 0;
        for ($n = 1; $n <= 7; $n++) {
            $acum += $pesos[$n];
            if ($tirada < $acum) {
                return $n;
            }
        }

        return 7; // inalcanzable: la tirada < suma siempre resuelve antes
    }

    /**
     * Sortea el elemento de un drop pesado por la rueda (026), recorriendo
     * EncuentroBuilder::ELEMENTOS en orden fijo (determinista, replayable). Pesos
     * sobre 100: 60 el mismo elemento del monstruo, 25 el que ese elemento vence,
     * 5 el que lo vence, 10 el cruzado neutral. Reusa CombatResolver::matchup como
     * única fuente de la rueda: una colmena rinde sobre todo su propio elemento y
     * casi nunca el que la derrota, en vez de un farmeo uniforme.
     */
    private static function elementoDrop(Prng $prng, string $elemMonstruo): string
    {
        $tirada = $prng->randBelow(100);
        $acum = 0;
        foreach (EncuentroBuilder::ELEMENTOS as $c) {
            if ($c === $elemMonstruo) {
                $peso = 60;
            } else {
                $peso = match (CombatResolver::matchup($elemMonstruo, $c)) {
                    'ventaja' => 25, // el monstruo vence a $c
                    'reves' => 5,    // $c vence al monstruo
                    default => 10,   // cruzado neutral
                };
            }
            $acum += $peso;
            if ($tirada < $acum) {
                return $c;
            }
        }

        return $elemMonstruo; // los pesos suman 100: el loop siempre resuelve antes
    }

    /** Busca una gema por id; si $soloFieldeada, solo entre las del talismán. */
    private static function gema(array $talisman, ?int $id, bool $soloFieldeada): ?array
    {
        foreach ($talisman['gemas'] as $g) {
            if ($g['id'] === $id && (! $soloFieldeada || $g['fieldeada'])) {
                return $g;
            }
        }

        return null;
    }

    /** Descuenta carga de una gema fieldeada, in place. */
    private static function gastarGema(array &$talisman, int $id, int $costo): void
    {
        foreach ($talisman['gemas'] as &$g) {
            if ($g['id'] === $id) {
                $g['carga'] = max(0, $g['carga'] - $costo);

                return;
            }
        }
    }
}
