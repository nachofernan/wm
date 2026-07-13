<?php

use App\Game\MapaBuilder;
use App\Game\MazeGenerator;

/**
 * Vector de paridad de MapaBuilder::marcas(): (seed, 30x30) → entrada,
 * salida, puertas, llaves. Estos mismos casos y valores están commiteados
 * en resources/js/mapaBuilder.test.js. Si un test cambia, el otro tiene que
 * cambiar igual, o la paridad PHP/JS de las marcas está rota.
 */
dataset('seeds_marcas', [
    'seed 1' => [1, [
        'entrada' => ['x' => 0, 'y' => 0],
        'salida' => ['x' => 13, 'y' => 27, 'distancia' => 442],
        'puertas' => [['x' => 10, 'y' => 28], ['x' => 25, 'y' => 5]],
        'llaves' => [['x' => 7, 'y' => 6, 'm' => 9], ['x' => 5, 'y' => 14, 'm' => 77], ['x' => 21, 'y' => 15, 'm' => 22]],
    ]],
    'seed 42' => [42, [
        'entrada' => ['x' => 0, 'y' => 0],
        'salida' => ['x' => 14, 'y' => 25, 'distancia' => 523],
        'puertas' => [['x' => 14, 'y' => 10], ['x' => 5, 'y' => 17]],
        'llaves' => [['x' => 11, 'y' => 1, 'm' => 15], ['x' => 14, 'y' => 7, 'm' => 18], ['x' => 28, 'y' => 16, 'm' => 43]],
    ]],
    'seed 12345' => [12345, [
        'entrada' => ['x' => 0, 'y' => 0],
        'salida' => ['x' => 12, 'y' => 15, 'distancia' => 397],
        'puertas' => [['x' => 21, 'y' => 1], ['x' => 11, 'y' => 11]],
        'llaves' => [['x' => 5, 'y' => 6, 'm' => 14], ['x' => 28, 'y' => 3, 'm' => 7], ['x' => 9, 'y' => 12, 'm' => 117]],
    ]],
    'seed 2026' => [2026, [
        'entrada' => ['x' => 0, 'y' => 0],
        'salida' => ['x' => 11, 'y' => 25, 'distancia' => 432],
        'puertas' => [['x' => 25, 'y' => 5], ['x' => 11, 'y' => 13]],
        'llaves' => [['x' => 3, 'y' => 0, 'm' => 4], ['x' => 10, 'y' => 0, 'm' => 37], ['x' => 29, 'y' => 24, 'm' => 144]],
    ]],
]);

test('produce las marcas esperadas para un seed fijo en un mapa de 30x30', function (int $seed, array $marcasEsperadas) {
    $matriz = MazeGenerator::generar($seed, 30, 30);

    expect(MapaBuilder::marcas($matriz))->toBe($marcasEsperadas);
})->with('seeds_marcas');

test('dificultadCelda es 0 en la entrada y 1 en la salida (027)', function () {
    // seed 42: salida en (14,25) a distancia 523 (dataset seeds_marcas).
    $matriz = MazeGenerator::generar(42, 30, 30);

    expect(MapaBuilder::dificultadCelda($matriz, 0, 0))->toBe(0.0);
    expect(MapaBuilder::dificultadCelda($matriz, 14, 25))->toBe(1.0);

    // Una celda intermedia cae estrictamente entre 0 y 1.
    $t = MapaBuilder::dificultadCelda($matriz, 5, 17); // puerta 2 del dataset
    expect($t)->toBeGreaterThan(0.0);
    expect($t)->toBeLessThan(1.0);
});

test('esValido rechaza un mapa cuyo camino no llega a CAMINO_MINIMO', function () {
    $matriz = MazeGenerator::generar(1, 10, 10);

    $marcas = MapaBuilder::marcas($matriz);

    expect($marcas['salida']['distancia'])->toBeLessThan(MapaBuilder::CAMINO_MINIMO);
    expect(MapaBuilder::esValido($marcas))->toBeFalse();
});

test('buscarSeed siempre devuelve un seed cuyas marcas son válidas', function () {
    $resultado = MapaBuilder::buscarSeed(30, 30);

    expect($resultado['seed'])->toBeInt();
    expect(MapaBuilder::esValido($resultado['marcas']))->toBeTrue();

    // El seed devuelto tiene que reproducir exactamente esas mismas marcas.
    $matriz = MazeGenerator::generar($resultado['seed'], 30, 30);
    expect(MapaBuilder::marcas($matriz))->toBe($resultado['marcas']);
});

test('buscarSeed devuelve un seed que entra exacto en un double de JS', function () {
    // Si el seed excede 2^53, el navegador lo redondea y genera otro laberinto
    // que el del servidor (rompe la paridad). SEED_MAX (32 bits) lo garantiza.
    for ($i = 0; $i < 20; $i++) {
        $seed = MapaBuilder::buscarSeed(30, 30)['seed'];
        expect($seed)->toBeGreaterThanOrEqual(0);
        expect($seed)->toBeLessThanOrEqual(MapaBuilder::SEED_MAX);
        expect($seed)->toBeLessThanOrEqual(PHP_INT_MAX & ((1 << 53) - 1));
    }
});
