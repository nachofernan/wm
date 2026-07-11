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

test('iniciar con encuentro de ambiente (sin elemento) sortea uno determinista', function () {
    $a = MazeCombate::iniciar(7, 5, 5, null, 1, 0);
    $b = MazeCombate::iniciar(7, 5, 5, null, 1, 0);

    expect($a['monstruo']['elemento'])->toBeIn(['fuego', 'agua', 'tierra', 'aire']);
    expect($a['monstruo']['elemento'])->toBe($b['monstruo']['elemento']); // determinista
});

test('atacar baja la vida del monstruo, gasta esencia y pasa a defensa', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'esencia' => 20, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70);
    expect($r['talisman']['gemas'][0]['esencia'])->toBe(15); // −5 (nivel)
    expect($r['combate']['turno'])->toBe('defensa');
    expect($r['combate']['entrante'])->not->toBeNull();
});

test('atacar con esencia insuficiente paga el faltante con vida (3:1) y vacía la gema', function () {
    // Gema nivel 5 con 2 de esencia: castear cuesta 5, faltan 3 → 9 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'esencia' => 2, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['esencia'])->toBe(0);   // se drenó lo que tenía
    expect($r['talisman']['vida'])->toBe(40 - 9);             // 3 faltantes × 3
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70); // el golpe salió igual
});

test('atacar con una gema extinta paga nivel × 3 de vida', function () {
    // Gema nivel 4 en 0: faltante = 4 → 12 de vida.
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 4, 'esencia' => 0, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['vida'])->toBe(40 - 12);
    expect($r['combate']['monstruo']['vida'])->toBeLessThan(70);
});

test('atacar fuera de turno es un error', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 5, 'esencia' => 20, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);
    $combate['turno'] = 'defensa';

    $r = MazeCombate::resolver($combate, $talisman, 'atacar', 1);

    expect($r['error'])->not->toBeNull();
});

test('un golpe letal mata al monstruo, cierra el combate y dropea una gema', function () {
    // Gema sobrada vs sílfide (aire, vida 45): un golpe con ventaja la parte.
    $talisman = talismanConGema(['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'esencia' => 999, 'fieldeada' => true]);
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

test('comer un golpe entrante baja la vida y vuelve a tu turno', function () {
    $talisman = MazeCombate::talismanInicial();
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);
    $combate['turno'] = 'defensa';
    $combate['entrante'] = ['dano' => 7, 'elemento' => 'tierra', 'peso' => 2, 'critico' => false];

    $r = MazeCombate::resolver($combate, $talisman, 'comer', null);

    expect($r['talisman']['vida'])->toBe(33); // 40 − 7
    expect($r['combate']['turno'])->toBe('tuTurno');
    expect($r['combate']['entrante'])->toBeNull();
});

test('comer el golpe fatal termina en derrota', function () {
    $talisman = MazeCombate::talismanInicial();
    $talisman['vida'] = 5;
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);
    $combate['turno'] = 'defensa';
    $combate['entrante'] = ['dano' => 9, 'elemento' => 'tierra', 'peso' => 2, 'critico' => false];

    $r = MazeCombate::resolver($combate, $talisman, 'comer', null);

    expect($r['resultado'])->toBe('derrota');
    expect($r['combate'])->toBeNull();
    expect($r['talisman']['vida'])->toBe(0);
});

test('bloquear con una gema inerte es un error', function () {
    $talisman = talismanConGema(['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'esencia' => 0, 'fieldeada' => true]);
    $combate = MazeCombate::iniciar(1, 0, 0, 'tierra', 0, 0);
    $combate['turno'] = 'defensa';
    $combate['entrante'] = ['dano' => 7, 'elemento' => 'tierra', 'peso' => 2, 'critico' => false];

    $r = MazeCombate::resolver($combate, $talisman, 'bloquear', 1);

    expect($r['error'])->not->toBeNull();
});
