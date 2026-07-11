import { describe, expect, test } from 'vitest';
import { campo, AMBIENTE } from './encuentroBuilder.js';
import { hashCampo } from './encuentroHash.js';

/**
 * Vector de paridad del campo de encuentros (016): (seed, ancho, alto) → hash
 * SHA-256 del campo. Estos mismos casos y hashes están commiteados en
 * tests/Unit/Game/EncuentroBuilderTest.php. Si un test cambia, el otro tiene
 * que cambiar igual, o la paridad PHP/JS del campo está rota.
 */
const seedsCampo = [
    ['seed 1, 30x30', 1, 30, 30, '430709b9511ba7bc1cd953843b8f4d3df7dc55c80602c44fc6140a6a8bb41dbb'],
    ['seed 42, 30x30', 42, 30, 30, 'ccdec06ccd4ed031d08a6152936345c736d751a458c50570f0e69f9693a8ab88'],
    ['seed 12345, 20x15', 12345, 20, 15, 'c1e932853d4957452f378fee7e7d1af78d0ebd7ae5e5aacc9ec1db5b46d278bf'],
    ['seed 7, 100x100', 7, 100, 100, 'a157f391348f0231d441012813a6160e9c29c35d84fda166cb4e0435cf5b5c72'],
];

describe('campo', () => {
    test.each(seedsCampo)('produce el hash esperado para %s', (_nombre, seed, ancho, alto, hashEsperado) => {
        expect(hashCampo(campo(seed, ancho, alto))).toBe(hashEsperado);
    });

    test('el mismo seed y tamaño siempre producen el mismo campo', () => {
        expect(hashCampo(campo(2026, 15, 15))).toBe(hashCampo(campo(2026, 15, 15)));
    });

    test('la entrada (0,0) nunca tiene encuentro', () => {
        const c = campo(42, 30, 30);
        expect(c.celdas[0][0]).toEqual({ prob: 0, elem: null });
    });

    test('el núcleo de una colmena lleva su pico y su elemento', () => {
        const c = campo(42, 30, 30);
        const n = c.nucleos[0];
        expect(c.celdas[n.y][n.x]).toEqual({ prob: n.pico, elem: n.elem });
    });

    test('hay celdas en el piso de ambiente fuera de las colmenas', () => {
        const c = campo(12345, 20, 15);
        const ambiente = c.celdas.flat().filter((celda) => celda.elem === null && celda.prob === AMBIENTE);
        expect(ambiente.length).toBeGreaterThan(0);
    });
});
