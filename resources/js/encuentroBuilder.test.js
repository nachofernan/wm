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
    ['seed 1, 30x30', 1, 30, 30, '75cfc456ed88ee5ff1d1eae13aed81c4dbeb8b89c267f7df3d1ea29770f530a6'],
    ['seed 42, 30x30', 42, 30, 30, '2793661abaf93b5bb74e47d938de927faaa678a3956c21be2d65b302001f37be'],
    ['seed 12345, 20x15', 12345, 20, 15, 'c1e932853d4957452f378fee7e7d1af78d0ebd7ae5e5aacc9ec1db5b46d278bf'],
    ['seed 7, 100x100', 7, 100, 100, '736d8a86c48761c8c4e307f448634e5feb97c844da92b47e9999369bf5cf904b'],
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
