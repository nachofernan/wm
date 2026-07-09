<?php

use App\Game\MazeGenerator;

/**
 * El test más importante del proyecto (CLAUDE.md). Vector de paridad:
 * (seed, ancho, alto) → hash SHA-256 del laberinto. Estos mismos casos y
 * hashes están commiteados en resources/js/maze.test.js. Si un test cambia,
 * el otro tiene que cambiar igual, o la paridad PHP/JS está rota.
 */
dataset('seeds_laberinto', [
    'seed 1, 10x10' => [1, 10, 10, 'd2c1a5b8ab4caf9d85bacb1864a8ec6a9063db17284e7e1c0a311223fd5b8b9a'],
    'seed 42, 10x10' => [42, 10, 10, '3026abb01eea87e9f01f8f0fb43f164ab80dcdc4f38b091a28396e6e57018d70'],
    'seed 12345, 20x15' => [12345, 20, 15, '9406f813c7770e4770d4bd67aebf14a5bc370b2bc98589bdd13173028ea2f1fd'],
    'seed 7, 100x100 (tamaño canónico)' => [7, 100, 100, '1675b97c02b874770028bbb2babe660d09a92e7af6a9f6b19c5266952e4210d6'],
]);

test('produce el hash esperado para un seed y tamaño fijos', function (int $seed, int $ancho, int $alto, string $hashEsperado) {
    $matriz = MazeGenerator::generar($seed, $ancho, $alto);

    expect(MazeGenerator::hash($matriz))->toBe($hashEsperado);
})->with('seeds_laberinto');

test('el mismo seed y tamaño siempre producen el mismo laberinto', function () {
    $a = MazeGenerator::generar(2026, 15, 15);
    $b = MazeGenerator::generar(2026, 15, 15);

    expect(MazeGenerator::hash($a))->toBe(MazeGenerator::hash($b));
});

test('es un laberinto perfecto: toda celda es alcanzable desde el origen', function () {
    $ancho = 12;
    $alto = 12;
    $matriz = MazeGenerator::generar(99, $ancho, $alto);

    $direcciones = [
        'N' => [0, -1, 'S'],
        'E' => [1, 0, 'O'],
        'S' => [0, 1, 'N'],
        'O' => [-1, 0, 'E'],
    ];

    $visitada = array_fill(0, $alto, array_fill(0, $ancho, false));
    $visitada[0][0] = true;
    $pila = [[0, 0]];
    $alcanzadas = 1;

    while ($pila !== []) {
        [$x, $y] = array_pop($pila);
        foreach ($direcciones as $muro => [$dx, $dy, $_opuesta]) {
            if ($matriz[$y][$x][$muro] === 1) {
                continue;
            }
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (!$visitada[$ny][$nx]) {
                $visitada[$ny][$nx] = true;
                $alcanzadas++;
                $pila[] = [$nx, $ny];
            }
        }
    }

    expect($alcanzadas)->toBe($ancho * $alto);
});
