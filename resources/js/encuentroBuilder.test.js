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
    ['seed 1, 30x30', 1, 30, 30, '04067babec0d838a67d5244ae8d0b9c6982a2f0180edf60f2c2593811fafdf3d'],
    ['seed 42, 30x30', 42, 30, 30, '48ba3dd2bb1972e8c52903cc608f81be6e7a1f5f04c7f63e967849b2120aba09'],
    ['seed 12345, 20x15', 12345, 20, 15, '98a5d44d111301a9eedb9c360426662bd8fd6efb0e2aa0f6f49a58faa1aa7367'],
    ['seed 7, 100x100', 7, 100, 100, '53b296fed15e14c348174057a967491f665f696a908f79acbaad6611cdb8847a'],
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
