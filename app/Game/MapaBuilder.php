<?php

namespace App\Game;

/**
 * Ubica el contenido del laberinto (entrada, salida, puertas, llaves) sobre
 * la topología que produce MazeGenerator. Espejo bit a bit de
 * resources/js/mapaBuilder.js. Puerto del algoritmo prototipado en
 * resources/views/welcome.blade.php.
 *
 * No define todavía qué función cumple cada marca en el juego (§5 del
 * protocolo sigue abierto) — solo dónde caen, de forma determinista.
 */
final class MapaBuilder
{
    /** Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1 */
    private const DIRECCIONES = [
        ['nombre' => 'N', 'dx' => 0, 'dy' => -1],
        ['nombre' => 'E', 'dx' => 1, 'dy' => 0],
        ['nombre' => 'S', 'dx' => 0, 'dy' => 1],
        ['nombre' => 'O', 'dx' => -1, 'dy' => 0],
    ];

    /** El camino entrada→salida tiene que medir al menos esto. */
    public const CAMINO_MINIMO = 400;

    /** Distancias (sobre el camino, desde la entrada) donde va cada puerta. */
    public const PUERTAS_EN = [100, 200];

    /** Cada llave tiene que estar en un brazo de al menos esta extensión. */
    public const BRAZO_MINIMO = 25;

    /**
     * Calcula entrada, salida, puertas y llaves para una matriz ya generada.
     * No valida las restricciones de diseño — eso lo hace esValido().
     *
     * @param  list<list<array{N:int,E:int,S:int,O:int}>>  $matriz
     * @return array{
     *     entrada: array{x:int,y:int},
     *     salida: array{x:int,y:int,distancia:int},
     *     puertas: list<array{x:int,y:int}|null>,
     *     llaves: list<array{x:int,y:int,m:int}|null>,
     * }
     */
    public static function marcas(array $matriz): array
    {
        $distanciasInicio = self::distancias($matriz, 0, 0);
        $salida = self::celdaMasLejana($distanciasInicio);
        $distanciasSalida = self::distancias($matriz, $salida['x'], $salida['y']);

        return [
            'entrada' => ['x' => 0, 'y' => 0],
            'salida' => $salida,
            'puertas' => self::ubicarPuertas($distanciasInicio, $distanciasSalida, $salida['distancia']),
            'llaves' => self::ubicarLlaves($distanciasInicio, $distanciasSalida, $salida['distancia']),
        ];
    }

    /** @param array{salida: array{distancia:int}, puertas: list<mixed|null>, llaves: list<array{m:int}|null>} $marcas */
    public static function esValido(array $marcas): bool
    {
        if ($marcas['salida']['distancia'] < self::CAMINO_MINIMO) {
            return false;
        }

        foreach ($marcas['puertas'] as $puerta) {
            if ($puerta === null) {
                return false;
            }
        }

        foreach ($marcas['llaves'] as $llave) {
            if ($llave === null || $llave['m'] < self::BRAZO_MINIMO) {
                return false;
            }
        }

        return true;
    }

    /**
     * El PRNG solo usa los 32 bits bajos del seed, así que este es el rango
     * completo de seeds distinguibles. Además entra exacto en un double de JS
     * (< 2^53): un seed más grande se redondea en el navegador y genera OTRO
     * laberinto que el del servidor — rompía la paridad al primer paso.
     */
    public const SEED_MAX = 0xFFFFFFFF;

    /**
     * Prueba seeds al azar hasta encontrar uno cuyo laberinto cumpla las
     * restricciones de diseño. El servidor es quien decide esto — el
     * cliente recibe el seed ya validado y solo recalcula las marcas.
     *
     * @return array{seed:int, marcas:array}
     */
    public static function buscarSeed(int $ancho, int $alto): array
    {
        do {
            $seed = random_int(0, self::SEED_MAX);
            $matriz = MazeGenerator::generar($seed, $ancho, $alto);
            $marcas = self::marcas($matriz);
        } while (! self::esValido($marcas));

        return ['seed' => $seed, 'marcas' => $marcas];
    }

    /**
     * BFS sobre el grafo del laberinto (paredes abiertas), no distancia
     * euclidiana.
     *
     * @param  list<list<array{N:int,E:int,S:int,O:int}>>  $matriz
     * @return list<list<int>> distancia desde (inicioX, inicioY) a cada celda
     */
    private static function distancias(array $matriz, int $inicioX, int $inicioY): array
    {
        $alto = count($matriz);
        $ancho = count($matriz[0]);
        $distancias = array_fill(0, $alto, array_fill(0, $ancho, -1));
        $distancias[$inicioY][$inicioX] = 0;
        $cola = [[$inicioX, $inicioY]];

        while ($cola !== []) {
            [$x, $y] = array_shift($cola);
            foreach (self::DIRECCIONES as $d) {
                $nx = $x + $d['dx'];
                $ny = $y + $d['dy'];
                if ($nx < 0 || $nx >= $ancho || $ny < 0 || $ny >= $alto) {
                    continue;
                }
                if ($matriz[$y][$x][$d['nombre']] === 1) {
                    continue;
                }
                if ($distancias[$ny][$nx] !== -1) {
                    continue;
                }
                $distancias[$ny][$nx] = $distancias[$y][$x] + 1;
                $cola[] = [$nx, $ny];
            }
        }

        return $distancias;
    }

    /** @param list<list<int>> $distancias */
    private static function celdaMasLejana(array $distancias): array
    {
        $mejor = ['x' => 0, 'y' => 0, 'distancia' => -1];
        foreach ($distancias as $y => $fila) {
            foreach ($fila as $x => $distancia) {
                if ($distancia > $mejor['distancia']) {
                    $mejor = ['x' => $x, 'y' => $y, 'distancia' => $distancia];
                }
            }
        }

        return $mejor;
    }

    /**
     * Cuánto se extiende una celda por fuera del camino inicio→salida: su
     * distancia al punto de desprendimiento. Da 0 para las celdas que están
     * sobre el camino. m = (dInicio + dSalida - total) / 2.
     */
    private static function extensionDesdeCamino(int $dInicio, int $dSalida, int $total): int|float
    {
        return ($dInicio + $dSalida - $total) / 2;
    }

    /**
     * A qué segmento del camino pertenece un punto de desprendimiento k,
     * dado el orden de puertas (p.ej. puertas [100, 200] → segmentos
     * [0,100), [100,200), [200,total]).
     */
    private static function segmentoDe(int|float $k, array $puertasEn): int
    {
        foreach ($puertasEn as $i => $limite) {
            if ($k < $limite) {
                return $i;
            }
        }

        return count($puertasEn);
    }

    /**
     * @param  list<list<int>>  $distanciasInicio
     * @param  list<list<int>>  $distanciasSalida
     * @return list<array{x:int,y:int}|null>
     */
    private static function ubicarPuertas(array $distanciasInicio, array $distanciasSalida, int $total): array
    {
        $puertas = array_fill(0, count(self::PUERTAS_EN), null);

        foreach ($distanciasInicio as $y => $fila) {
            foreach ($fila as $x => $dInicio) {
                if ($dInicio + $distanciasSalida[$y][$x] !== $total) {
                    continue;
                }
                $idx = array_search($dInicio, self::PUERTAS_EN, true);
                if ($idx !== false) {
                    $puertas[$idx] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $puertas;
    }

    /**
     * Una llave por segmento: la punta del brazo más largo que cuelga del
     * camino en ese tramo, elegida por su extensión (m), no por distancia a
     * ninguna puerta.
     *
     * @param  list<list<int>>  $distanciasInicio
     * @param  list<list<int>>  $distanciasSalida
     * @return list<array{x:int,y:int,m:int}|null>
     */
    private static function ubicarLlaves(array $distanciasInicio, array $distanciasSalida, int $total): array
    {
        $llaves = array_fill(0, count(self::PUERTAS_EN) + 1, null);

        foreach ($distanciasInicio as $y => $fila) {
            foreach ($fila as $x => $dInicio) {
                $dSalida = $distanciasSalida[$y][$x];
                $m = self::extensionDesdeCamino($dInicio, $dSalida, $total);
                if ($m === 0) {
                    continue;
                }

                $k = $dInicio - $m;
                $seg = self::segmentoDe($k, self::PUERTAS_EN);

                if ($llaves[$seg] === null || $m > $llaves[$seg]['m']) {
                    $llaves[$seg] = ['x' => $x, 'y' => $y, 'm' => $m];
                }
            }
        }

        return $llaves;
    }
}
