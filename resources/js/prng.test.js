import { describe, expect, test } from 'vitest';
import { crearPrng } from './prng.js';

/**
 * Vector de paridad: seed fijo → primeros 5 valores de next(). Estos mismos
 * seeds y valores están commiteados en tests/Unit/Game/PrngTest.php. Si un
 * test cambia, el otro tiene que cambiar igual, o la paridad PHP/JS está rota.
 */
const seedsPrng = [
    ['seed 0', 0, [1144304738, 1416247, 958946056, 627933444, 2007157716]],
    ['seed 42', 42, [2581720956, 1925393290, 3661312704, 2876485805, 750819978]],
    ['seed uint32 max', 4294967295, [3850105811, 813802916, 3073704848, 4054706436, 3630262831]],
    ['seed 123456789', 123456789, [1107202814, 4169434471, 3372958138, 885470128, 1301683845]],
];

describe('crearPrng', () => {
    test.each(seedsPrng)('produce la secuencia esperada para %s', (_nombre, seed, esperado) => {
        const next = crearPrng(seed);
        const obtenido = esperado.map(() => next());

        expect(obtenido).toEqual(esperado);
    });

    test('la misma seed siempre produce la misma secuencia', () => {
        const a = crearPrng(2026);
        const b = crearPrng(2026);

        const secuenciaA = Array.from({ length: 10 }, () => a());
        const secuenciaB = Array.from({ length: 10 }, () => b());

        expect(secuenciaA).toEqual(secuenciaB);
    });
});
