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
     * Arquetipos de monstruo por elemento. Tomados de los rivales prototipados
     * en /pelea y /mago. Números de arranque.
     */
    private const ARQUETIPOS = [
        'fuego' => ['nombre' => 'Espectro ígneo', 'vida' => 45, 'defensa' => 18, 'nivelAtaque' => 7, 'peso' => 3, 'dificultad' => 3],
        'agua' => ['nombre' => 'Ondina', 'vida' => 60, 'defensa' => 22, 'nivelAtaque' => 5, 'peso' => 2, 'dificultad' => 3],
        'tierra' => ['nombre' => 'Gólem de tierra', 'vida' => 70, 'defensa' => 30, 'nivelAtaque' => 6, 'peso' => 2, 'dificultad' => 4],
        'aire' => ['nombre' => 'Sílfide del aire', 'vida' => 45, 'defensa' => 12, 'nivelAtaque' => 5, 'peso' => 1, 'dificultad' => 2],
    ];

    /** Hoja de personaje inicial (DECISIONES 010/011): Fuego n5, Agua n4, Tierra n3. */
    public static function talismanInicial(): array
    {
        return [
            'vida' => 40,
            'vidaMax' => 40,
            'defensa' => 8,
            'cap' => 12,
            'esencia' => 0,
            'gemas' => [
                ['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'esencia' => 20, 'fieldeada' => true],
                ['id' => 2, 'elemento' => 'agua', 'nivel' => 4, 'esencia' => 15, 'fieldeada' => true],
                ['id' => 3, 'elemento' => 'tierra', 'nivel' => 3, 'esencia' => 20, 'fieldeada' => true],
            ],
            'proximoId' => 4,
            'bichosCaidos' => 0,
            'gemasJuntadas' => 0,
        ];
    }

    /**
     * Arranca un combate en la celda (x,y). El elemento del monstruo sale del
     * encuentro (o, para un encuentro de ambiente sin elemento, se sortea). La
     * vida escala con la probabilidad de la celda: una colmena más caliente
     * suelta bichos más duros.
     *
     * @return array combate: {x,y,monstruo,turno,entrante,semilla,paso,resultado}
     */
    public static function iniciar(int $seed, int $x, int $y, ?string $elem, int $prob, int $indice): array
    {
        $semilla = ($seed ^ ($x * 73856093) ^ ($y * 19349663) ^ ($indice * 83492791)) & 0xFFFFFFFF;
        $prng = new Prng($semilla);

        $elem ??= EncuentroBuilder::ELEMENTOS[$prng->randBelow(count(EncuentroBuilder::ELEMENTOS))];
        $base = self::ARQUETIPOS[$elem];
        $vida = $base['vida'] + $prob; // colmena caliente = bicho más duro (arranque)

        return [
            'x' => $x,
            'y' => $y,
            'monstruo' => [
                'nombre' => $base['nombre'],
                'elemento' => $elem,
                'vida' => $vida,
                'vidaMax' => $vida,
                'defensa' => $base['defensa'],
                'nivelAtaque' => $base['nivelAtaque'],
                'peso' => $base['peso'],
                'dificultad' => $base['dificultad'],
            ],
            'turno' => 'tuTurno',
            'entrante' => null,
            'semilla' => $semilla,
            'paso' => 1, // el 0 lo consumió la derivación del monstruo
            'resultado' => null,
        ];
    }

    /**
     * Resuelve una acción de combate y devuelve el estado nuevo. Acciones:
     * 'atacar' (gemaId), 'comer', 'bloquear' (gemaId). El combate vuelve null
     * cuando termina; `resultado` dice cómo.
     *
     * @return array{
     *     combate: array|null, talisman: array, resultado: string|null,
     *     drop: array|null, error: string|null, log: list<array{txt:string,crit:bool}>
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

            $r = self::golpe($combate, $g['nivel'], $g['elemento'], $combate['monstruo']['defensa'], $combate['monstruo']['elemento']);

            if ($g['esencia'] >= $r['costoEsencia']) {
                self::gastarGema($talisman, $gemaId, $r['costoEsencia']);
                $combate['monstruo']['vida'] = max(0, $combate['monstruo']['vida'] - $r['dano']);
                $log[] = ['txt' => "atacás con {$g['elemento']} n{$g['nivel']} — {$r['dano']} de daño ({$r['matchup']}, −{$r['costoEsencia']} es.)", 'crit' => $r['critico']];
            } elseif ($g['esencia'] === 0) {
                $costoVida = (new CombatResolver(new Prng(0)))->costoUltimoSacudon($g['nivel']);
                $talisman['vida'] = max(0, $talisman['vida'] - $costoVida);
                $combate['monstruo']['vida'] = max(0, $combate['monstruo']['vida'] - $r['dano']);
                $log[] = ['txt' => "ÚLTIMO SACUDÓN con {$g['elemento']} extinta — {$r['dano']} de daño, pagás {$costoVida} de vida", 'crit' => $r['critico']];
            } else {
                return $error("{$g['elemento']} tiene {$g['esencia']} de esencia; castear cuesta {$r['costoEsencia']}");
            }

            if ($combate['monstruo']['vida'] <= 0) {
                return self::victoria($combate, $talisman, $log);
            }
            if ($talisman['vida'] <= 0) {
                return self::derrota($combate, $talisman, $log);
            }

            return self::golpeMonstruo($combate, $talisman, $log);
        }

        if ($accion === 'comer') {
            if ($combate['turno'] !== 'defensa' || $combate['entrante'] === null) {
                return $error('no hay golpe entrante');
            }
            $e = $combate['entrante'];
            $talisman['vida'] = max(0, $talisman['vida'] - $e['dano']);
            $log[] = ['txt' => "comés el golpe — {$e['dano']} a la vida", 'crit' => false];

            if ($talisman['vida'] <= 0) {
                return self::derrota($combate, $talisman, $log);
            }
            $combate['turno'] = 'tuTurno';
            $combate['entrante'] = null;

            return self::estado($combate, $talisman, $log);
        }

        if ($accion === 'bloquear') {
            if ($combate['turno'] !== 'defensa' || $combate['entrante'] === null) {
                return $error('no hay golpe entrante');
            }
            $g = self::gema($talisman, $gemaId, true);
            if ($g === null || $g['esencia'] <= 0) {
                return $error('gema inválida o inerte');
            }
            $e = $combate['entrante'];
            $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);
            $costo = (new CombatResolver($prng))->costoBloqueo($e['peso'], $g['elemento'], $e['elemento']);

            if ($g['esencia'] < $costo) {
                return $error("{$g['elemento']} tiene {$g['esencia']} de esencia; bloquear cuesta {$costo}");
            }
            self::gastarGema($talisman, $gemaId, $costo);
            $log[] = ['txt' => "bloqueás con {$g['elemento']} — golpe anulado (−{$costo} es.)", 'crit' => false];
            $combate['turno'] = 'tuTurno';
            $combate['entrante'] = null;

            return self::estado($combate, $talisman, $log);
        }

        return $error('acción desconocida');
    }

    /** Un golpe del mago contra el monstruo (consume un paso de la semilla de combate). */
    private static function golpe(array &$combate, int $nivel, string $elemAtacante, int $defensa, string $elemDefensor): array
    {
        $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);

        return (new CombatResolver($prng))->golpe($nivel, $elemAtacante, $defensa, $elemDefensor);
    }

    /** El monstruo devuelve el golpe: fija el entrante y pasa a defensa. */
    private static function golpeMonstruo(array $combate, array $talisman, array $log): array
    {
        $m = $combate['monstruo'];
        $r = self::golpe($combate, $m['nivelAtaque'], $m['elemento'], $talisman['defensa'], 'ninguno');
        $combate['turno'] = 'defensa';
        $combate['entrante'] = ['dano' => $r['dano'], 'elemento' => $m['elemento'], 'peso' => $m['peso'], 'critico' => $r['critico']];
        $log[] = ['txt' => "{$m['nombre']} lanza {$m['elemento']} — {$r['dano']} entrantes".($r['critico'] ? ' ¡CRÍTICO!' : ''), 'crit' => $r['critico']];

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
        $dificultad = $combate['monstruo']['dificultad'];
        $prng = new Prng(($combate['semilla'] + $combate['paso']++) & 0xFFFFFFFF);
        $cantidad = 1 + ($prng->randBelow(4) < $dificultad ? 1 : 0);

        $drops = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $gema = self::drop($prng, $dificultad, $talisman['proximoId']);
            $talisman['gemas'][] = $gema;
            $talisman['proximoId']++;
            $talisman['gemasJuntadas']++;
            $drops[] = $gema;
        }
        $talisman['bichosCaidos']++;

        $lista = implode(', ', array_map(fn ($g) => "{$g['elemento']} n{$g['nivel']}", $drops));
        $log[] = ['txt' => "¡cae {$combate['monstruo']['nombre']}! dropea {$lista} → al inventario", 'crit' => false];

        return [
            'combate' => null, 'talisman' => $talisman, 'resultado' => 'victoria',
            'drop' => $drops, 'error' => null, 'log' => $log,
        ];
    }

    /** Vida en 0: cierra el combate como derrota. */
    private static function derrota(array $combate, array $talisman, array $log): array
    {
        $log[] = ['txt' => '— derrota: vida en 0 —', 'crit' => false];

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
     * Gema del botín: elemento y nivel derivados del PRNG de combate, escalados
     * por la dificultad del monstruo. Esencia generosa para que el neto por
     * pelea sea positivo (si no, es espiral de muerte). Números de arranque.
     */
    private static function drop(Prng $prng, int $dificultad, int $id): array
    {
        $elemento = EncuentroBuilder::ELEMENTOS[$prng->randBelow(count(EncuentroBuilder::ELEMENTOS))];
        $nivel = $dificultad + $prng->randBelow(3); // dificultad .. +2

        return ['id' => $id, 'elemento' => $elemento, 'nivel' => $nivel, 'esencia' => $nivel * 6, 'fieldeada' => false];
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

    /** Descuenta esencia de una gema fieldeada, in place. */
    private static function gastarGema(array &$talisman, int $id, int $costo): void
    {
        foreach ($talisman['gemas'] as &$g) {
            if ($g['id'] === $id) {
                $g['esencia'] = max(0, $g['esencia'] - $costo);

                return;
            }
        }
    }
}
