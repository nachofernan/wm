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
