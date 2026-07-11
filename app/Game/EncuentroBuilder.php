<?php

namespace App\Game;

/**
 * El campo de encuentros: para cada celda, con qué probabilidad (y de qué
 * elemento) puede saltar un monstruo. Es una función pura del seed, espejo bit
 * a bit de resources/js/encuentroBuilder.js (docs/DECISIONES.md 016,
 * docs/PROTOCOLO_GENERADOR.md §5).
 *
 * El campo se PINTA (color = elemento, alpha = probabilidad) y viaja como el
 * resto del laberinto: no se persiste, se recalcula desde el seed en cliente y
 * servidor. Este módulo produce solo el SESGO (público, paritario). El DISPARO
 * ("¿me saltó algo ahora?") es secreto del servidor y no sale de acá.
 *
 * Modelo: un piso de ambiente parejo (casi nula), más un puñado de colmenas —
 * una celda-núcleo de alta probabilidad que irradia y decae por anillos de
 * Chebyshev, ATRAVESANDO muros (la distancia es de grilla, no del grafo del
 * laberinto). Los números son de arranque (tuning, se ajustan jugando); lo
 * cerrado es la forma.
 */
final class EncuentroBuilder
{
    /**
     * Stream propio del PRNG, decorrelado del que usan MazeGenerator y las
     * marcas (seed XOR esta constante). Idéntico en PHP y JS o la paridad se
     * rompe. No confundir con el 0x9E3779B9 que game.js usa para el dado de
     * disparo del cliente: esto ubica el campo, aquello lo dispara.
     */
    private const SEMILLA = 0x85EBCA6B;

    /**
     * Orden canónico de elementos para UBICAR (no es la rueda de ventaja de
     * combate, que sigue ❓): fija el índice que consume el PRNG. Cambiarlo
     * cambia todos los campos.
     */
    public const ELEMENTOS = ['fuego', 'agua', 'tierra', 'aire'];

    /** Piso de encuentro en toda celda: "casi nula", pero nunca cero (%). */
    public const AMBIENTE = 1;

    /** Cuánto cae la probabilidad por anillo al alejarse del núcleo (%). */
    public const DECAIMIENTO = 2;

    /** Una colmena cada tantas celdas de área (densidad). */
    public const CELDAS_POR_NUCLEO = 400;

    /** Probabilidad del núcleo: base fija + tirada [0, VARIACION). */
    public const PICO_BASE = 10;
    public const PICO_VARIACION = 6;

    /**
     * Campo de encuentros de un laberinto de ancho×alto.
     *
     * @return array{
     *     nucleos: list<array{x:int,y:int,elem:string,pico:int}>,
     *     celdas: list<list<array{prob:int,elem:string|null}>>,
     * }
     */
    public static function campo(int $seed, int $ancho, int $alto): array
    {
        $prng = new Prng($seed ^ self::SEMILLA);
        $nucleos = self::sortearNucleos($prng, $ancho, $alto);
        $celdas = self::pintarCampo($nucleos, $ancho, $alto);

        return ['nucleos' => $nucleos, 'celdas' => $celdas];
    }

    /**
     * Sortea las colmenas en orden explícito. Cada núcleo consume el PRNG
     * exactamente cuatro veces (x, y, elemento, pico), en ese orden: un
     * consumo de más o de menos desincroniza la paridad para siempre.
     *
     * @return list<array{x:int,y:int,elem:string,pico:int}>
     */
    private static function sortearNucleos(Prng $prng, int $ancho, int $alto): array
    {
        $cantidad = max(1, intdiv($ancho * $alto, self::CELDAS_POR_NUCLEO));
        $nucleos = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $x = $prng->randBelow($ancho);
            $y = $prng->randBelow($alto);
            $elem = self::ELEMENTOS[$prng->randBelow(count(self::ELEMENTOS))];
            $pico = self::PICO_BASE + $prng->randBelow(self::PICO_VARIACION);
            $nucleos[] = ['x' => $x, 'y' => $y, 'elem' => $elem, 'pico' => $pico];
        }

        return $nucleos;
    }

    /**
     * Pinta el campo sin tocar el PRNG (ya no hay azar): arranca del ambiente
     * y cada colmena eleva las celdas dentro de su radio al máximo entre lo que
     * ya había y su valor por anillo. En empate gana la colmena de menor índice
     * (recorrido en orden, comparación estricta) — determinista.
     *
     * La entrada (0,0) se fuerza a 0: nadie salta encima del mago al arrancar.
     *
     * @param  list<array{x:int,y:int,elem:string,pico:int}>  $nucleos
     * @return list<list<array{prob:int,elem:string|null}>>
     */
    private static function pintarCampo(array $nucleos, int $ancho, int $alto): array
    {
        $celdas = [];
        for ($y = 0; $y < $alto; $y++) {
            $fila = [];
            for ($x = 0; $x < $ancho; $x++) {
                $fila[] = ['prob' => self::AMBIENTE, 'elem' => null];
            }
            $celdas[] = $fila;
        }

        foreach ($nucleos as $n) {
            $radio = intdiv($n['pico'] - self::AMBIENTE, self::DECAIMIENTO);
            for ($dy = -$radio; $dy <= $radio; $dy++) {
                for ($dx = -$radio; $dx <= $radio; $dx++) {
                    $cx = $n['x'] + $dx;
                    $cy = $n['y'] + $dy;
                    if ($cx < 0 || $cx >= $ancho || $cy < 0 || $cy >= $alto) {
                        continue;
                    }
                    $anillo = max(abs($dx), abs($dy));
                    $prob = $n['pico'] - self::DECAIMIENTO * $anillo;
                    if ($prob > $celdas[$cy][$cx]['prob']) {
                        $celdas[$cy][$cx] = ['prob' => $prob, 'elem' => $n['elem']];
                    }
                }
            }
        }

        $celdas[0][0] = ['prob' => 0, 'elem' => null];

        return $celdas;
    }

    /**
     * Hash de paridad — mismo espíritu que MazeGenerator::hash().
     * Recorre las celdas fila por fila; cada celda son dos bytes: probabilidad
     * y código de elemento (0 = sin elemento, si no índice+1 en ELEMENTOS).
     * Espejo de hashCampo() en resources/js/encuentroBuilder.js.
     *
     * @param  array{celdas: list<list<array{prob:int,elem:string|null}>>}  $campo
     */
    public static function hash(array $campo): string
    {
        $bytes = '';
        foreach ($campo['celdas'] as $fila) {
            foreach ($fila as $celda) {
                $codigo = $celda['elem'] === null
                    ? 0
                    : array_search($celda['elem'], self::ELEMENTOS, true) + 1;
                $bytes .= chr($celda['prob']).chr($codigo);
            }
        }

        return hash('sha256', $bytes);
    }
}
