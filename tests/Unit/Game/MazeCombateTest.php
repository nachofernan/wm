<?php

use App\Game\MazeCombate;

/** Talismán con una gema sobrada, para forzar escenarios deterministas. */
function talismanConGema(array $gema): array
{
    $t = MazeCombate::talismanInicial();
    $t['gemas'] = [$gema];

    return $t;
}

test('iniciar deriva un monstruo del elemento del encuentro, con vida escalada por la prob', function () {
    $c = MazeCombate::iniciar(42, 23, 4, 'agua', 11, 0);

    expect($c['monstruo']['elemento'])->toBe('agua');
    expect($c['monstruo']['vida'])->toBe(60 + 11); // arquetipo agua + prob
    expect($c['monstruo']['vidaMax'])->toBe(71);
    expect($c['turno'])->toBe('tuTurno');
    expect($c['resultado'])->toBeNull();
});

test('la distancia fija el nivel del bicho (N1 entrada, N7 fondo) y de ahí escala vida/defensa/peso (029)', function () {
    $entrada = MazeCombate::iniciar(42, 23, 4, 'agua', 11, 0, 0.0);
    $salida = MazeCombate::iniciar(42, 23, 4, 'agua', 11, 0, 1.0);

    // Mismo elemento/forma; el nivel salta de 1 a 7 con la distancia.
    expect($entrada['monstruo']['elemento'])->toBe($salida['monstruo']['elemento']);
    expect($entrada['monstruo']['nivel'])->toBe(1);
    expect($salida['monstruo']['nivel'])->toBe(7);

    // Vida y defensa escalan ×2 (factor 1.0 a N1, 2.0 a N7).
    expect($entrada['monstruo']['vida'])->toBe(60 + 11);
    expect($salida['monstruo']['vida'])->toBe((60 + 11) * 2);
    expect($salida['monstruo']['defensa'])->toBe($entrada['monstruo']['defensa'] * 2);

    // Peso = coefPeso × nivel: agua es 1.0, así que N1→1 y N7→7.
    expect($entrada['monstruo']['peso'])->toBe(1);
    expect($salida['monstruo']['peso'])->toBe(7);
    expect($salida['t'])->toBe(1.0);
});

test('el peso del golpe sale de coefPeso × nivel: tierra pesa más que aire al mismo nivel (029)', function () {
    // A t=0.5 el nivel es round(1 + 3) = 4. tierra 1.25×4=5, aire 0.75×4=3, agua 1.0×4=4.
    $tierra = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0, 0.5);
    $aire = MazeCombate::iniciar(1, 0, 0, 'aire', 0, 0, 0.5);
    $agua = MazeCombate::iniciar(1, 0, 0, 'agua', 0, 0, 0.5);

    expect($tierra['monstruo']['nivel'])->toBe(4);
    expect($tierra['monstruo']['peso'])->toBe(5);
    expect($aire['monstruo']['peso'])->toBe(3);
    expect($agua['monstruo']['peso'])->toBe(4);
});

test('el loot se desliza con la distancia: bajo en la entrada, alto en la salida, N7 raro (027)', function () {
    // Mato bichos sobre 200 seeds fijos cerca de la entrada (t=0) y en el fondo
    // (t=1) y comparo los niveles dropeados. La ventana no se solapa por diseño:
    // entrada N1..N4, salida N4..N7. Números de arranque, pero el sesgo es firme.
    $recolectar = function (float $t): array {
        $niveles = [];
        for ($seed = 0; $seed < 200; $seed++) {
            // Gema y vida sobradas: mato a golpes hasta la victoria sin depender
            // de un one-shot (a t=1 el bicho tiene ~el doble de vida).
            $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'carga' => 999999, 'fieldeada' => true]);
            $talisman['vida'] = 999999;
            $combate = MazeCombate::iniciar($seed, 0, 0, 'aire', 0, 0, $t);

            $r = ['combate' => $combate, 'talisman' => $talisman, 'resultado' => null];
            while ($r['resultado'] === null) {
                // Atacar en tu turno; en defensa, bloquear con la misma gema
                // sobrada (carga de sobra → sin costo de vida). Ya no hay comer.
                $accion = $r['combate']['turno'] === 'tuTurno' ? 'atacar' : 'bloquear';
                $r = MazeCombate::resolver($r['combate'], $r['talisman'], $accion, 99);
            }

            expect($r['resultado'])->toBe('victoria');
            foreach ($r['drop'] as $d) {
                $niveles[] = $d['nivel'];
            }
        }

        return $niveles;
    };

    $entrada = $recolectar(0.0);
    $salida = $recolectar(1.0);

    expect(min($entrada))->toBeGreaterThanOrEqual(1);
    expect(max($entrada))->toBeLessThanOrEqual(4); // en la entrada nunca N5+
    expect(min($salida))->toBeGreaterThanOrEqual(4); // en el fondo nunca N3-
    expect(max($salida))->toBe(7);

    // El promedio del fondo es netamente mayor que el de la entrada.
    expect(array_sum($salida) / count($salida))->toBeGreaterThan(array_sum($entrada) / count($entrada) + 1.5);

    // N7 es la cola rara aun en el fondo del maze (≤15% buscado, margen a 20%).
    $septimos = count(array_filter($salida, fn ($n) => $n === 7));
    expect($septimos / count($salida))->toBeLessThan(0.20);
    expect($septimos)->toBeGreaterThan(0); // pero pasa
});

test('iniciar con encuentro de ambiente (sin elemento) sortea uno determinista', function () {
    $a = MazeCombate::iniciar(7, 5, 5, null, 1, 0);
    $b = MazeCombate::iniciar(7, 5, 5, null, 1, 0);

    expect($a['monstruo']['elemento'])->toBeIn(['fuego', 'agua', 'tierra', 'aire']);
    expect($a['monstruo']['elemento'])->toBe($b['monstruo']['elemento']); // determinista
});

test('el elemento de un encuentro de ambiente no queda atado al dado de disparo: no es siempre fuego (031)', function () {
    // Regresión del sesgo de fuego. El dado de disparo (cliente, 022) y el sorteo
    // de elemento (servidor) comparten semilla. Si el elemento saliera del PRIMER
    // output —el mismo que el dado— una celda de ambiente (prob=1) dispararía solo
    // con first%100==0, que por 100=4×25 fuerza first%4==0 → siempre fuego. Acá
    // sorteo el elemento SOLO en las semillas que de hecho disparan y exijo que
    // aparezcan los cuatro sin que ninguno acapare.
    $conteo = ['fuego' => 0, 'agua' => 0, 'tierra' => 0, 'aire' => 0];

    foreach ([42, 1, 7, 12345, 999] as $seed) {
        for ($x = 0; $x < 30; $x++) {
            for ($y = 0; $y < 30; $y++) {
                for ($pasos = 0; $pasos < 200; $pasos++) {
                    $semilla = ($seed ^ ($x * 73856093) ^ ($y * 19349663) ^ ($pasos * 83492791)) & 0xFFFFFFFF;
                    if ((new App\Game\Prng($semilla))->next() % 100 >= 1) {
                        continue; // el encuentro de ambiente no dispara en esta semilla
                    }
                    $c = MazeCombate::iniciar($seed, $x, $y, null, 1, $pasos);
                    $conteo[$c['monstruo']['elemento']]++;
                }
            }
        }
    }

    // Antes del fix: fuego 100% / resto 0. Ahora los cuatro salen y el reparto es
    // ~uniforme (margen amplio: es muestreo, no una prueba de uniformidad estricta).
    $total = array_sum($conteo);
    expect($total)->toBeGreaterThan(1000); // hubo encuentros de sobra
    foreach ($conteo as $n) {
        expect($n)->toBeGreaterThan(0);       // ningún elemento queda en cero
        expect($n / $total)->toBeLessThan(0.40); // ninguno domina como antes fuego
    }
});

test('atacar baja la vida del monstruo, gasta carga y pasa a defensa', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'carga' => 20, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70);
    expect($r['talisman']['gemas'][0]['carga'])->toBe(15); // −5 (nivel)
    expect($r['combate']['turno'])->toBe('defensa');
    expect($r['combate']['entrante'])->not->toBeNull();
});

test('atacar con carga insuficiente paga el faltante con vida (3:1) y vacía la gema', function () {
    // Gema nivel 5 con 2 de carga: castear cuesta 5, faltan 3 → 9 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'carga' => 2, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(0);   // se drenó lo que tenía
    expect($r['talisman']['vida'])->toBe(40 - 9);             // 3 faltantes × 3
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70); // el golpe salió igual
});

test('atacar con una gema extinta paga nivel × 3 de vida', function () {
    // Gema nivel 4 en 0: faltante = 4 → 12 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 0, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['vida'])->toBe(40 - 12);
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70);
});

test('atacar fuera de turno es un error', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'carga' => 20, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);
    $combate['turno'] = 'defensa';

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->not->toBeNull();
});

test('un golpe letal mata al monstruo, cierra el combate y dropea una gema', function () {
    // Gema sobrada vs sílfide (aire, vida 45): un golpe con ventaja la parte.
    $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'carga' => 999, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'aire', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 99);

    expect($r['resultado'])->toBe('victoria');
    expect($r['combate'])->toBeNull();
    // Multi-drop: una o más piedras según la dificultad del bicho.
    expect($r['drop'])->toBeArray()->not->toBeEmpty();
    expect(count($r['talisman']['gemas']))->toBeGreaterThanOrEqual(2); // la original + el/los drops
    expect($r['talisman']['bichosCaidos'])->toBe(1);
    expect($r['talisman']['gemasJuntadas'])->toBe(count($r['drop']));
});

test('golpe mártir (034): si el golpe letal te desangra a 0, sobrevivís clavado en 1, no en 0', function () {
    // Gema descomunal sin carga: castear cuesta nivel × 3 de vida (900), que barre
    // la vida entera → sin la regla quedarías en 0. Pero el golpe mata al bicho, así
    // que caés de pie con 1 de vida. La victoria es normal (dropea igual).
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 300, 'carga' => 0, 'fieldeada' => true]);
    $talisman['vida'] = 40;
    $combate = MazeCombate::iniciar(1, 0, 0, 'aire', 0, 0); // sílfide N1, muere de un golpe

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['resultado'])->toBe('victoria');
    expect($r['combate'])->toBeNull();
    expect($r['talisman']['vida'])->toBe(1); // golpe mártir: 1, no 0
    expect($r['drop'])->toBeArray()->not->toBeEmpty(); // victoria normal: hay botín
});

test('el golpe mártir vale igual para un guardián: caés en 1 y la llave igual es tuya (034)', function () {
    // Mismo escenario contra un boss: la regla vive antes del despacho a victoriaBoss,
    // así que el guardián cae, otorga la llave, y vos quedás en 1 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 300, 'carga' => 0, 'fieldeada' => true]);
    $talisman['vida'] = 40;
    $combate = MazeCombate::guardian(1, 0, 0, 0); // guardián de llave, índice 0

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['resultado'])->toBe('victoria');
    expect($r['llave'])->toBe(0);            // el despacho a boss no se rompe
    expect($r['talisman']['vida'])->toBe(1); // caés de pie, no en 0
});

test('una victoria con carga de sobra no dispara el golpe mártir: la vida queda intacta (034)', function () {
    // Carga sobrada: el golpe no cuesta vida, así que matar al bicho no toca la vida
    // (no es un "caés en 1": seguís con la que tenías). Control del caso normal.
    $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'carga' => 999, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'aire', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 99);

    expect($r['resultado'])->toBe('victoria');
    expect($r['talisman']['vida'])->toBe(40); // ni rozó la vida
});

/** Fija un golpe entrante a mano sobre un combate (turno defensa, DECISIÓN 029). */
function conEntrante(array $combate, string $elemento, int $peso): array
{
    $combate['turno'] = 'defensa';
    $combate['entrante'] = ['elemento' => $elemento, 'peso' => $peso];

    return $combate;
}

test('bloquear con el elemento que le gana y carga de sobra frena el golpe sin tocar la vida (029)', function () {
    // Golpe tierra peso 4. Bloqueo con aire (aire le gana a tierra → ×0.5) → 2 ⚡.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'aire', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true]);
    $combate = conEntrante(MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0), 'tierra', 4);

    $r = MazeCombate::resolver($combate, $talisman, 'bloquear', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(8);  // 10 − 2
    expect($r['talisman']['vida'])->toBe(40);              // vida intacta
    expect($r['combate']['turno'])->toBe('tuTurno');
    expect($r['combate']['entrante'])->toBeNull();
});

test('bloquear sin carga para todo gasta la que hay y paga el déficit con vida ×3 (029)', function () {
    // Golpe tierra peso 4, gema fuego (neutro → ×1 = 4 ⚡) con solo 1 de carga:
    // paga 1 ⚡ y el déficit 3 va a vida × 3 = 9.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 1, 'fieldeada' => true]);
    $combate = conEntrante(MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0), 'tierra', 4);

    $r = MazeCombate::resolver($combate, $talisman, 'bloquear', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(0);
    expect($r['talisman']['vida'])->toBe(40 - 9);
    expect($r['combate']['turno'])->toBe('tuTurno');
});

test('bloquear con una gema seca ya no es error: paga todo el golpe con vida (029)', function () {
    // Golpe tierra peso 4, gema agua seca (agua pierde contra tierra → ×2 = 8 ⚡),
    // 0 de carga → 8 × 3 = 24 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'carga' => 0, 'fieldeada' => true]);
    $combate = conEntrante(MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0), 'tierra', 4);

    $r = MazeCombate::resolver($combate, $talisman, 'bloquear', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(0);
    expect($r['talisman']['vida'])->toBe(40 - 24);
    expect($r['combate']['turno'])->toBe('tuTurno');
});

test('un bloqueo que no alcanza a pagarse con vida termina en derrota (029)', function () {
    // Vida 5 contra un déficit de 24 → cae.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'carga' => 0, 'fieldeada' => true]);
    $talisman['vida'] = 5;
    $combate = conEntrante(MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0), 'tierra', 4);

    $r = MazeCombate::resolver($combate, $talisman, 'bloquear', 1);

    expect($r['resultado'])->toBe('derrota');
    expect($r['combate'])->toBeNull();
    expect($r['talisman']['vida'])->toBe(0);
});

test('el costo de escape sale de coefDestreza × nivel: la inversa del peso (030)', function () {
    // A t=0.5 el nivel es 4. destreza = 2 − coefPeso: tierra 0.75×4=3 (barato de
    // esquivar), aire 1.25×4=5 (caro), agua 1.0×4=4.
    $tierra = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0, 0.5);
    $aire = MazeCombate::iniciar(1, 0, 0, 'aire', 0, 0, 0.5);
    $agua = MazeCombate::iniciar(1, 0, 0, 'agua', 0, 0, 0.5);

    expect($tierra['monstruo']['escape'])->toBe(3);
    expect($aire['monstruo']['escape'])->toBe(5);
    expect($agua['monstruo']['escape'])->toBe(4);
});

test('escapar en tu turno paga la esencia y cierra el combate como huida, sin botín (030)', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true]);
    $talisman['esencia'] = 10;
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0, 0.5); // tierra N4 → escape 3

    $r = MazeCombate::resolver($combate, $talisman, 'escapar', null);

    expect($r['error'])->toBeNull();
    expect($r['resultado'])->toBe('huida');
    expect($r['combate'])->toBeNull();
    expect($r['talisman']['esencia'])->toBe(7); // 10 − 3
    expect($r['drop'])->toBeNull();
    expect($r['talisman']['bichosCaidos'])->toBe(0); // no cuenta como caído
});

test('escapar sin esencia suficiente es un error y deja el combate abierto (030)', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true]);
    $talisman['esencia'] = 2; // escape cuesta 3
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0, 0.5);

    $r = MazeCombate::resolver($combate, $talisman, 'escapar', null);

    expect($r['error'])->not->toBeNull();
    expect($r['resultado'])->toBeNull();
    expect($r['talisman']['esencia'])->toBe(2); // no se descontó
});

test('escapar fuera de tu turno (en la ventana de defensa) es un error (030)', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true]);
    $talisman['esencia'] = 10;
    $combate = conEntrante(MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0, 0.5), 'tierra', 5);

    $r = MazeCombate::resolver($combate, $talisman, 'escapar', null);

    expect($r['error'])->not->toBeNull();
});

test('los drops se pesan por la afinidad del monstruo (026): una colmena de fuego rinde sobre todo fuego y casi nunca agua', function () {
    // Mato un espectro de fuego sobre 300 seeds fijos y cuento los elementos
    // dropeados. Rueda: fuego vence a aire (25), cruza con tierra (10) y pierde
    // contra agua (5); su mismo elemento pesa 60. El sesgo tiene que verse.
    $conteo = ['fuego' => 0, 'agua' => 0, 'tierra' => 0, 'aire' => 0];

    for ($seed = 0; $seed < 300; $seed++) {
        $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'carga' => 999, 'fieldeada' => true]);
        $combate = MazeCombate::iniciar($seed, 0, 0, 'fuego', 0, 0);
        $r = MazeCombate::resolver($combate, $talisman, 'atacar', 99);

        expect($r['resultado'])->toBe('victoria');
        foreach ($r['drop'] as $d) {
            $conteo[$d['elemento']]++;
        }
    }

    // El propio elemento del bicho es la mayoría; el que lo vence (agua), lo más raro.
    expect($conteo['fuego'])->toBe(max($conteo));
    expect($conteo['agua'])->toBe(min($conteo));
    expect($conteo['fuego'])->toBeGreaterThan($conteo['agua'] * 3);
});

test('el guardián tiene nivel fijo por índice: 4/6/8 las llaves, 10 la salida (032)', function () {
    // El nivel NO sale de la distancia (029): es fijo por índice. Todos rompen el
    // techo 1..7 a propósito, y la salida (3) es el pico.
    expect(MazeCombate::guardian(42, 0, 5, 5)['monstruo']['nivel'])->toBe(4);
    expect(MazeCombate::guardian(42, 1, 5, 5)['monstruo']['nivel'])->toBe(6);
    expect(MazeCombate::guardian(42, 2, 5, 5)['monstruo']['nivel'])->toBe(8);
    expect(MazeCombate::guardian(42, 3, 5, 5)['monstruo']['nivel'])->toBe(10);
});

test('el guardián es telegrafiado y determinista: mismo seed/índice → mismo bicho (032)', function () {
    $a = MazeCombate::guardian(7, 1, 10, 10);
    $b = MazeCombate::guardian(7, 1, 10, 10);

    expect($a['monstruo']['elemento'])->toBeIn(['fuego', 'agua', 'tierra', 'aire']);
    expect($a['monstruo'])->toBe($b['monstruo']); // telegrafía honesta: reproducible
    expect($a['monstruo']['boss'])->toBeTrue();
    expect($a['monstruo']['indice'])->toBe(1);
    expect($a['monstruo']['escape'])->toBeNull();
    expect($a['turno'])->toBe('tuTurno');
});

test('no se puede escapar de un guardián (032)', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true]);
    $talisman['esencia'] = 999; // esencia de sobra: el rechazo no es por costo
    $combate = MazeCombate::guardian(1, 0, 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'escapar', null);

    expect($r['error'])->not->toBeNull();
    expect($r['resultado'])->toBeNull();
    expect($r['talisman']['esencia'])->toBe(999); // no se descontó nada
});

test('matar al guardián otorga la llave de su índice y suelta una gema garantizada (032)', function () {
    // Gema sobrada: parto al guardián de un golpe y compruebo la llave y el botín.
    $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 40, 'carga' => 999, 'fieldeada' => true]);
    $combate = MazeCombate::guardian(1, 2, 0, 0); // índice 2 (tercera llave)

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 99);

    expect($r['resultado'])->toBe('victoria');
    expect($r['combate'])->toBeNull();
    expect($r['llave'])->toBe(2);            // otorga la llave del índice
    expect($r['drop'])->toHaveCount(1);      // una sola gema (no multi-drop de ambiente)
    expect($r['talisman']['bichosCaidos'])->toBe(1);
});

test('el botín del guardián de la salida (N10) se topea al techo de gema del jugador (7) (032)', function () {
    // N10 tiene 2.5× la vida base, así que peleo hasta matarlo (no one-shot):
    // atacar en tu turno, bloquear con la misma gema sobrada en defensa.
    $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 40, 'carga' => 999999, 'fieldeada' => true]);
    $talisman['vida'] = 999999;
    $combate = MazeCombate::guardian(1, 3, 0, 0); // salida, N10

    $r = ['combate' => $combate, 'talisman' => $talisman, 'resultado' => null];
    while ($r['resultado'] === null) {
        $accion = $r['combate']['turno'] === 'tuTurno' ? 'atacar' : 'bloquear';
        $r = MazeCombate::resolver($r['combate'], $r['talisman'], $accion, 99);
    }

    expect($r['resultado'])->toBe('victoria');
    expect($r['llave'])->toBe(3);                    // señal de victoria final
    expect($r['drop'][0]['nivel'])->toBeLessThanOrEqual(7); // N10 → gema ≤ 7
});
