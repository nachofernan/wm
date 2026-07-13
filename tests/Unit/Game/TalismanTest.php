<?php

use App\Game\MazeCombate;
use App\Game\Talisman;

/** Talismán inicial con una gema suelta en el inventario para probar el swap. */
function talismanConInventario(): array
{
    $t = MazeCombate::talismanInicial(); // 4 fieldeadas (3×4 = 12 = cap)
    $t['gemas'][] = ['id' => 9, 'elemento' => 'aire', 'nivel' => 2, 'esencia' => 12, 'fieldeada' => false];

    return $t;
}

test('capEnUso suma los niveles de las gemas fieldeadas', function () {
    expect(Talisman::capEnUso(MazeCombate::talismanInicial()))->toBe(12);
});

test('fieldear una gema que no entra en el cap es rechazado', function () {
    // El talismán inicial ya usa 12/12: no entra nada más.
    $r = Talisman::aplicar(talismanConInventario(), 'fieldear', 9);

    expect($r['error'])->toBe('no entra en el cap');
});

test('guardar libera cap y después la gema del inventario entra', function () {
    $t = talismanConInventario();

    // Guardo la de tierra (n3) → cap en uso 9/12.
    $t = Talisman::aplicar($t, 'guardar', 3)['talisman'];
    expect(Talisman::capEnUso($t))->toBe(9);

    // Ahora la de aire (n2) entra.
    $r = Talisman::aplicar($t, 'fieldear', 9);
    expect($r['error'])->toBeNull();
    $equipada = collect($r['talisman']['gemas'])->firstWhere('id', 9);
    expect($equipada['fieldeada'])->toBeTrue();
    expect(Talisman::capEnUso($r['talisman']))->toBe(11);
});

test('desguazar una gema del inventario suma esencia y la saca', function () {
    $r = Talisman::aplicar(talismanConInventario(), 'desguazar', 9);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['esencia'])->toBe(2); // +nivel (2)
    expect(collect($r['talisman']['gemas'])->firstWhere('id', 9))->toBeNull();
});

test('una gema fieldeada no se desguaza', function () {
    $r = Talisman::aplicar(MazeCombate::talismanInicial(), 'desguazar', 1);

    expect($r['error'])->toBe('gema inválida');
});

test('el talismán inicial deriva cap, defensa y ataque de nivel + gemas fieldeadas', function () {
    $t = MazeCombate::talismanInicial();

    expect($t['nivel'])->toBe(1);
    expect($t['cap'])->toBe(12);          // CAP_BASE
    expect($t['defensa'])->toBe(8 + 9);   // base 8 + agua n3 fieldeada (3 × 3)
    expect($t['ataqueMult'])->toBe(0.15); // fuego n3 fieldeada (3 × 0.05)
});

test('subir nivel cuesta esencia y sube cap y defensa base', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 12;

    $r = Talisman::aplicar($t, 'subirNivel', null);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['nivel'])->toBe(2);
    expect($r['talisman']['cap'])->toBe(22);          // 12 + 10
    expect($r['talisman']['defensa'])->toBe(12 + 9);  // base nivel 2 (12) + agua n3 fieldeada
    expect($r['talisman']['esencia'])->toBe(2);       // 12 − (1 × 10)
});

test('el acople gema→stat: fieldear agua sube defensa, guardar fuego baja ataque', function () {
    // Arranco de un talismán limpio nivel 1 sin gemas, y armo el loadout a mano.
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 3,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'esencia' => 10, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'fuego', 'nivel' => 5, 'esencia' => 10, 'fieldeada' => true],
        ],
    ]);
    expect($t['defensa'])->toBe(8);        // base, ninguna agua fieldeada
    expect($t['ataqueMult'])->toBe(0.25);  // fuego n5 fieldeada

    // Fieldeo la agua n4 (entra: 5 + 4 = 9 ≤ 12) → +12 de defensa.
    $t = Talisman::aplicar($t, 'fieldear', 1)['talisman'];
    expect($t['defensa'])->toBe(8 + 12);

    // Guardo la fuego n5 → el ataque se cae a 0.
    $t = Talisman::aplicar($t, 'guardar', 2)['talisman'];
    expect($t['ataqueMult'])->toBe(0.0);
});

test('una gema inerte (esencia 0) no potencia la hoja', function () {
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 2,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'esencia' => 0, 'fieldeada' => true],
        ],
    ]);

    expect($t['defensa'])->toBe(8); // agua fieldeada pero seca: no suma
});

test('subir nivel sin esencia suficiente es rechazado', function () {
    $r = Talisman::aplicar(MazeCombate::talismanInicial(), 'subirNivel', null);

    expect($r['error'])->toBe('esencia insuficiente');
});

test('curar convierte esencia en vida 1:1', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 30;      // faltan 10 para el tope (40)
    $t['esencia'] = 6;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['talisman']['vida'])->toBe(36);    // +6
    expect($r['talisman']['esencia'])->toBe(0);  // −6
});

test('curar no se pasa del tope de vida ni malgasta esencia', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 38;      // faltan 2 para el tope
    $t['esencia'] = 10;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['talisman']['vida'])->toBe(40);    // llega al tope
    expect($r['talisman']['esencia'])->toBe(8);  // solo gastó 2
});

test('curar sin esencia es rechazado', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 20;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['error'])->toBe('sin esencia');
});

test('curar con la vida llena es rechazado', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 5; // vida ya en el tope

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['error'])->toBe('vida llena');
});
