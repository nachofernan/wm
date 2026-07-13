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

test('el talismán inicial arranca en nivel 1 con cap y defensa base', function () {
    $t = MazeCombate::talismanInicial();

    expect($t['nivel'])->toBe(1);
    expect($t['cap'])->toBe(12);     // CAP_BASE
    expect($t['defensa'])->toBe(8);  // DEF_BASE
});

test('subir nivel cuesta esencia y sube cap y defensa', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 12;

    $r = Talisman::aplicar($t, 'subirNivel', null);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['nivel'])->toBe(2);
    expect($r['talisman']['cap'])->toBe(22);     // 12 + 10
    expect($r['talisman']['defensa'])->toBe(12); // 8 + 4
    expect($r['talisman']['esencia'])->toBe(2);  // 12 − (1 × 10)
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
