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
