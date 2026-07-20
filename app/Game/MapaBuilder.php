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
     * Tope de cofres por laberinto (DECISIÓN 035): se colocan hasta esta cantidad,
     * repartidos por segmento del camino (DECISIÓN 037). Si un segmento no junta
     * candidatas suficientes van menos — el número no se fuerza.
     */
    public const MAX_COFRES = 8;

    /**
     * Stream propio del PRNG para el sorteo de cofres (DECISIÓN 037), decorrelado
     * del que usan MazeGenerator y EncuentroBuilder (seed XOR esta constante).
     * Idéntico en PHP y JS o la paridad se rompe. Mismo patrón que
     * EncuentroBuilder::SEMILLA.
     */
    public const SEMILLA_COFRES = 0xC2B2AE35;

    /**
     * Separación mínima entre dos cofres, medida como |dInicio_a - dInicio_b|
     * (proxy barato de distancia en el árbol del laberinto, consistente con cómo
     * extensionDesdeCamino ya usa dInicio). Evita que una bifurcación cerca de la
     * punta de una rama larga deje 3 cofres pegados (DECISIÓN 037): los callejones
     * que comparten casi todo el brazo caen a un puñado de unidades de dInicio entre
     * sí, así que 8 alcanza para partirlos. Se probó 15 (propuesta original) y 10 y
     * ambos degeneraban el conteo (3 de 4 seeds fijos con solo 4 cofres); 8 es el
     * valor más alto que mantiene los 4 seeds en 5..8 cofres. < BRAZO_MINIMO (25).
     */
    public const SEPARACION_MINIMA_COFRES = 8;

    /**
     * Calcula entrada, salida, puertas, llaves y cofres para una matriz ya
     * generada. No valida las restricciones de diseño — eso lo hace esValido().
     *
     * El $seed alimenta el PRNG del sorteo de cofres (DECISIÓN 037); no toca el
     * resto de las marcas, que son función pura de la topología.
     *
     * @param  list<list<array{N:int,E:int,S:int,O:int}>>  $matriz
     * @return array{
     *     entrada: array{x:int,y:int},
     *     salida: array{x:int,y:int,distancia:int},
     *     puertas: list<array{x:int,y:int}|null>,
     *     llaves: list<array{x:int,y:int,m:int}|null>,
     *     cofres: list<array{x:int,y:int,nivel:int}>,
     * }
     */
    public static function marcas(array $matriz, int $seed): array
    {
        $distanciasInicio = self::distancias($matriz, 0, 0);
        $salida = self::celdaMasLejana($distanciasInicio);
        $distanciasSalida = self::distancias($matriz, $salida['x'], $salida['y']);

        $puertas = self::ubicarPuertas($distanciasInicio, $distanciasSalida, $salida['distancia']);
        $llaves = self::ubicarLlaves($distanciasInicio, $distanciasSalida, $salida['distancia']);

        // Celdas ya ocupadas: un cofre no cae sobre entrada, salida, puerta ni
        // llave. Entrada/salida/puertas están sobre el camino (m=0) y ya las filtra
        // BRAZO_MINIMO; las llaves SÍ son puntas de brazo y colisionarían — por eso
        // el set. Clave "x,y".
        $ocupadas = ['0,0' => true, "{$salida['x']},{$salida['y']}" => true];
        foreach ($puertas as $p) {
            if ($p !== null) {
                $ocupadas["{$p['x']},{$p['y']}"] = true;
            }
        }
        foreach ($llaves as $l) {
            if ($l !== null) {
                $ocupadas["{$l['x']},{$l['y']}"] = true;
            }
        }

        return [
            'entrada' => ['x' => 0, 'y' => 0],
            'salida' => $salida,
            'puertas' => $puertas,
            'llaves' => $llaves,
            'cofres' => self::ubicarCofres($matriz, $distanciasInicio, $distanciasSalida, $salida['distancia'], $ocupadas, $seed),
        ];
    }

    /**
     * Dificultad de una celda como su distancia normalizada a la entrada:
     * 0.0 en la entrada, 1.0 en la celda más lejana (la salida). Es el eje
     * único que escala monstruos y loot dentro de un mismo maze (DECISIÓN 027):
     * cerca de la entrada, bichos flojos y piedras bajas; cerca de la salida,
     * ~el doble de dificultad y de nivel de drop. Una sola BFS desde la entrada.
     *
     * Determinista y paritario por construcción (sale del laberinto, que es
     * función pura del seed), pero se consume solo en el servidor: el combate
     * es autoridad del servidor (axioma 4), no hace falta espejo en JS.
     *
     * @param  list<list<array{N:int,E:int,S:int,O:int}>>  $matriz
     */
    public static function dificultadCelda(array $matriz, int $x, int $y): float
    {
        $distancias = self::distancias($matriz, 0, 0);
        $total = self::celdaMasLejana($distancias)['distancia'];
        $d = $distancias[$y][$x];

        if ($total <= 0 || $d < 0) {
            return 0.0;
        }

        return $d / $total;
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
            $marcas = self::marcas($matriz, $seed);
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

    /**
     * Ubica hasta MAX_COFRES cofres en las puntas de brazo del laberinto, repartidos
     * por segmento del camino y sorteados (DECISIÓN 037, reemplaza el top-N global de
     * la 035). Una "punta de brazo" es un callejón sin salida (una sola celda
     * navegable adyacente) que cuelga del camino con m ≥ BRAZO_MINIMO — el mismo piso
     * que las llaves. Se excluyen las celdas ya ocupadas (entrada, salida, puertas,
     * llaves): las llaves también son puntas y colisionarían.
     *
     * El reparto imita a las llaves (una por segmento) pero repartiendo el cupo de 8:
     * cada candidata se agrupa por su punto de desprendimiento k = dInicio - m en su
     * segmento (0..2, cortado por PUERTAS_EN), MAX_COFRES se reparte lo más parejo
     * posible en orden [seg0, seg1, seg2] (3/3/2), y el faltante de un segmento se
     * traslada hacia adelante. Dentro de cada segmento se sortea sin reemplazo con un
     * PRNG determinista sembrado en seed ^ SEMILLA_COFRES, descartando toda candidata
     * a menos de SEPARACION_MINIMA_COFRES de una ya aceptada (de cualquier segmento).
     * Así los cofres no se apelotonan al fondo del maze ni se pegan entre sí cuando
     * una rama larga se bifurca cerca de la punta.
     *
     * El nivel de la gema del cofre sale de la profundidad de la celda (nivelCofre),
     * el mismo eje 0..1 → 1..7 que escala monstruos y drops (027/029). Es posición
     * pura del seed: paritario y espejado en resources/js/mapaBuilder.js. El BOTÍN
     * real (elemento + gema) lo tira el servidor al abrir (axioma 4), no sale de acá.
     *
     * @param  list<list<array{N:int,E:int,S:int,O:int}>>  $matriz
     * @param  list<list<int>>  $distanciasInicio
     * @param  list<list<int>>  $distanciasSalida
     * @param  array<string,bool>  $ocupadas  celdas "x,y" vedadas para un cofre
     * @return list<array{x:int,y:int,nivel:int}>
     */
    private static function ubicarCofres(array $matriz, array $distanciasInicio, array $distanciasSalida, int $total, array $ocupadas, int $seed): array
    {
        $ancho = count($matriz[0]);
        $alto = count($matriz);
        $numSeg = count(self::PUERTAS_EN) + 1;

        // Candidatas agrupadas por segmento, en orden de recorrido (y asc, luego
        // x asc): así el pool de cada segmento es determinístico e idéntico en PHP y
        // JS sin depender de la estabilidad de ningún sort.
        $pools = array_fill(0, $numSeg, []);
        foreach ($distanciasInicio as $y => $fila) {
            foreach ($fila as $x => $dInicio) {
                if ($dInicio < 0) {
                    continue; // inalcanzable (no pasa en un árbol conexo, pero por las dudas)
                }
                $m = self::extensionDesdeCamino($dInicio, $distanciasSalida[$y][$x], $total);
                if ($m < self::BRAZO_MINIMO) {
                    continue; // brazo demasiado corto (también descarta el camino, m=0)
                }
                if (self::pasajes($matriz, $x, $y, $ancho, $alto) !== 1) {
                    continue; // no es una punta: solo un callejón sin salida cuenta
                }
                if (isset($ocupadas["$x,$y"])) {
                    continue; // ya hay una llave/puerta/entrada/salida acá
                }
                $k = $dInicio - $m;
                $seg = self::segmentoDe($k, self::PUERTAS_EN);
                $pools[$seg][] = [
                    'x' => $x,
                    'y' => $y,
                    'dInicio' => $dInicio,
                    'nivel' => self::nivelCofre($dInicio, $total),
                ];
            }
        }

        // Cupo por segmento, lo más parejo posible en orden [seg0..]; los primeros
        // `resto` segmentos reciben +1 (MAX_COFRES=8, 3 segmentos → 3/3/2).
        $base = intdiv(self::MAX_COFRES, $numSeg);
        $resto = self::MAX_COFRES % $numSeg;

        $prng = new Prng($seed ^ self::SEMILLA_COFRES);
        $aceptados = [];
        $carry = 0; // faltante de un segmento que se traslada al siguiente
        for ($seg = 0; $seg < $numSeg; $seg++) {
            $cupo = $base + ($seg < $resto ? 1 : 0) + $carry;
            $elegidos = self::seleccionarCofres($pools[$seg], $cupo, $aceptados, $prng);
            $carry = $cupo - $elegidos;
        }

        return array_map(
            fn ($c) => ['x' => $c['x'], 'y' => $c['y'], 'nivel' => $c['nivel']],
            $aceptados,
        );
    }

    /**
     * Sorteo sin reemplazo de hasta $cupo cofres de un pool, respetando la
     * separación mínima contra TODOS los ya aceptados (DECISIÓN 037). Muta
     * $aceptados agregando cada cofre elegido y devuelve cuántos aceptó.
     *
     * El algoritmo es token-por-token idéntico al de mapaBuilder.js (mismo orden de
     * llamadas a randBelow, mismo swap-con-el-último para remover en O(1)): cualquier
     * divergencia desincroniza el stream del PRNG y rompe la paridad. Una candidata
     * descartada por separación NO vuelve al pool.
     *
     * @param  list<array{x:int,y:int,dInicio:int,nivel:int}>  $pool
     * @param  list<array{x:int,y:int,dInicio:int,nivel:int}>  $aceptados
     */
    private static function seleccionarCofres(array $pool, int $cupo, array &$aceptados, Prng $prng): int
    {
        $elegidos = 0;
        while ($elegidos < $cupo && count($pool) > 0) {
            $i = $prng->randBelow(count($pool));
            $cand = $pool[$i];
            $pool[$i] = $pool[count($pool) - 1];
            array_pop($pool);

            $ok = true;
            foreach ($aceptados as $a) {
                if (abs($cand['dInicio'] - $a['dInicio']) < self::SEPARACION_MINIMA_COFRES) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $aceptados[] = $cand;
                $elegidos++;
            }
        }

        return $elegidos;
    }

    /** Cuántas celdas vecinas navegables tiene (x,y): 1 = punta / callejón sin salida. */
    private static function pasajes(array $matriz, int $x, int $y, int $ancho, int $alto): int
    {
        $n = 0;
        foreach (self::DIRECCIONES as $d) {
            $nx = $x + $d['dx'];
            $ny = $y + $d['dy'];
            if ($nx < 0 || $nx >= $ancho || $ny < 0 || $ny >= $alto) {
                continue;
            }
            if ($matriz[$y][$x][$d['nombre']] === 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Nivel entero 1..7 de la gema de un cofre según la profundidad de su celda.
     * Reusa el eje de dificultadCelda (t = distancia a la entrada / total) y la
     * misma escala que fija el nivel de un monstruo en MazeCombate::iniciar
     * (round(1 + t·6), clampeada) — así un cofre en el fondo rinde como un bicho del
     * fondo. Cerca de la entrada, ~N1; en la salida, N7.
     */
    private static function nivelCofre(int $dInicio, int $total): int
    {
        $t = $total > 0 ? $dInicio / $total : 0.0;

        return max(1, min(7, (int) round(1 + $t * 6)));
    }
}
