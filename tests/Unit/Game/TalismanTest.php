<?php

use App\Game\MazeCombate;
use App\Game\Talisman;

/** Talismán inicial con una gema suelta en el inventario para probar el swap. */
function talismanConInventario(): array
{
    $t = MazeCombate::talismanInicial(); // 3 fieldeadas (5+4+3 = 12 = cap)
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

test('subir cap cuesta esencia y sube el tope', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 7;

    $r = Talisman::aplicar($t, 'subirCap', null);

    expect($r['talisman']['cap'])->toBe(13);
    expect($r['talisman']['esencia'])->toBe(2); // 7 − 5
});

test('subir cap sin esencia suficiente es rechazado', function () {
    $r = Talisman::aplicar(MazeCombate::talismanInicial(), 'subirCap', null);

    expect($r['error'])->toBe('esencia insuficiente');
});
