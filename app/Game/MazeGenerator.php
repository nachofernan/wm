<?php

namespace App\Game;

/**
 * Generador de laberintos por backtracking recursivo, iterativo con pila
 * explícita. Espejo bit a bit de resources/js/maze.js. Ver
 * docs/PROTOCOLO_GENERADOR.md §3 y §4.
 *
 * Produce solo la topología (paredes); la ubicación de contenidos (llave,
 * salida, cofres, monstruos) no está diseñada todavía (§5 del protocolo).
 */
final class MazeGenerator
{
    /** Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1 */
    private const DIRECCIONES = [
        ['nombre' => 'N', 'dx' => 0, 'dy' => -1, 'opuesta' => 'S'],
        ['nombre' => 'E', 'dx' => 1, 'dy' => 0, 'opuesta' => 'O'],
        ['nombre' => 'S', 'dx' => 0, 'dy' => 1, 'opuesta' => 'N'],
        ['nombre' => 'O', 'dx' => -1, 'dy' => 0, 'opuesta' => 'E'],
    ];

    /**
     * @return list<list<array{N:int,E:int,S:int,O:int}>> matriz[y][x], cada
     *         celda con sus cuatro paredes (1 = cerrada, 0 = abierta)
     */
    public static function generar(int $seed, int $ancho, int $alto): array
    {
        $prng = new Prng($seed);
        $matriz = self::crearMatriz($ancho, $alto);
        $visitada = array_fill(0, $alto, array_fill(0, $ancho, false));
        $pila = [[0, 0]];
        $visitada[0][0] = true;

        while ($pila !== []) {
            [$x, $y] = $pila[array_key_last($pila)];

            $vecinos = [];
            foreach (self::DIRECCIONES as $d) {
                $nx = $x + $d['dx'];
                $ny = $y + $d['dy'];
                if ($nx < 0 || $nx >= $ancho || $ny < 0 || $ny >= $alto) {
                    continue;
                }
                if ($visitada[$ny][$nx]) {
                    continue;
                }
                $vecinos[] = [$nx, $ny, $d['nombre'], $d['opuesta']];
            }

            if ($vecinos === []) {
                array_pop($pila);
                continue;
            }

            [$ex, $ey, $nombre, $opuesta] = $vecinos[$prng->randBelow(count($vecinos))];
            $matriz[$y][$x][$nombre] = 0;
            $matriz[$ey][$ex][$opuesta] = 0;
            $visitada[$ey][$ex] = true;
            $pila[] = [$ex, $ey];
        }

        return $matriz;
    }

    /**
     * Hash de paridad — docs/PROTOCOLO_GENERADOR.md §6. Recorre la matriz
     * fila por fila; cada celda se serializa en 1 byte: (N<<3)|(E<<2)|(S<<1)|O.
     */
    public static function hash(array $matriz): string
    {
        $bytes = '';
        foreach ($matriz as $fila) {
            foreach ($fila as $celda) {
                $bytes .= chr(($celda['N'] << 3) | ($celda['E'] << 2) | ($celda['S'] << 1) | $celda['O']);
            }
        }

        return hash('sha256', $bytes);
    }

    /** @return list<list<array{N:int,E:int,S:int,O:int}>> */
    private static function crearMatriz(int $ancho, int $alto): array
    {
        $matriz = [];
        for ($y = 0; $y < $alto; $y++) {
            $fila = [];
            for ($x = 0; $x < $ancho; $x++) {
                $fila[] = ['N' => 1, 'E' => 1, 'S' => 1, 'O' => 1];
            }
            $matriz[] = $fila;
        }

        return $matriz;
    }
}
