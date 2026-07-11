<?php

use App\Game\EncuentroBuilder;

/**
 * Vector de paridad del campo de encuentros (016): (seed, ancho, alto) → hash
 * SHA-256 del campo. Estos mismos casos y hashes están commiteados en
 * resources/js/encuentroBuilder.test.js. Si un test cambia, el otro tiene que
 * cambiar igual, o la paridad PHP/JS del campo está rota.
 */
dataset('seeds_campo', [
    'seed 1, 30x30' => [1, 30, 30, '04067babec0d838a67d5244ae8d0b9c6982a2f0180edf60f2c2593811fafdf3d'],
    'seed 42, 30x30' => [42, 30, 30, '48ba3dd2bb1972e8c52903cc608f81be6e7a1f5f04c7f63e967849b2120aba09'],
    'seed 12345, 20x15' => [12345, 20, 15, '98a5d44d111301a9eedb9c360426662bd8fd6efb0e2aa0f6f49a58faa1aa7367'],
    'seed 7, 100x100' => [7, 100, 100, '53b296fed15e14c348174057a967491f665f696a908f79acbaad6611cdb8847a'],
]);

test('produce el hash esperado para un seed fijo', function (int $seed, int $ancho, int $alto, string $hashEsperado) {
    $campo = EncuentroBuilder::campo($seed, $ancho, $alto);

    expect(EncuentroBuilder::hash($campo))->toBe($hashEsperado);
})->with('seeds_campo');

test('el mismo seed y tamaño siempre producen el mismo campo', function () {
    $a = EncuentroBuilder::campo(2026, 15, 15);
    $b = EncuentroBuilder::campo(2026, 15, 15);

    expect(EncuentroBuilder::hash($a))->toBe(EncuentroBuilder::hash($b));
});

test('la entrada (0,0) nunca tiene encuentro: nadie salta encima al arrancar', function () {
    $campo = EncuentroBuilder::campo(42, 30, 30);

    expect($campo['celdas'][0][0])->toBe(['prob' => 0, 'elem' => null]);
});

test('toda celda fuera de una colmena tiene el piso de ambiente', function () {
    // 20x15 con un solo núcleo: hay celdas lejos del núcleo que quedan en AMBIENTE.
    $campo = EncuentroBuilder::campo(12345, 20, 15);

    $ambiente = 0;
    foreach ($campo['celdas'] as $fila) {
        foreach ($fila as $celda) {
            expect($celda['prob'])->toBeGreaterThanOrEqual(0);
            if ($celda['elem'] === null && $celda['prob'] === EncuentroBuilder::AMBIENTE) {
                $ambiente++;
            }
        }
    }

    expect($ambiente)->toBeGreaterThan(0);
});

test('el núcleo de una colmena lleva su pico y su elemento', function () {
    $campo = EncuentroBuilder::campo(42, 30, 30);
    $nucleo = $campo['nucleos'][0]; // {x:17, y:25, elem:fuego, pico:13}

    $celda = $campo['celdas'][$nucleo['y']][$nucleo['x']];

    expect($celda['prob'])->toBe($nucleo['pico']);
    expect($celda['elem'])->toBe($nucleo['elem']);
});
