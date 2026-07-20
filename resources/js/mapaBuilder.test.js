import { describe, expect, test } from 'vitest';
import { generarLaberinto } from './maze.js';
import { marcas, esValido, CAMINO_MINIMO } from './mapaBuilder.js';

/**
 * Vector de paridad de marcas(): (seed, 30x30) → entrada, salida, puertas,
 * llaves. Estos mismos casos y valores están commiteados en
 * tests/Unit/Game/MapaBuilderTest.php. Si un test cambia, el otro tiene que
 * cambiar igual, o la paridad PHP/JS de las marcas está rota.
 */
const seedsMarcas = [
    ['seed 1', 1, {
        entrada: { x: 0, y: 0 },
        salida: { x: 13, y: 27, distancia: 442 },
        puertas: [{ x: 10, y: 28 }, { x: 25, y: 5 }],
        llaves: [{ x: 7, y: 6, m: 9 }, { x: 5, y: 14, m: 77 }, { x: 21, y: 15, m: 22 }],
        cofres: [{ x: 3, y: 5, nivel: 4 }, { x: 13, y: 22, nivel: 4 }, { x: 2, y: 17, nivel: 3 }, { x: 7, y: 15, nivel: 4 }, { x: 0, y: 26, nivel: 3 }, { x: 5, y: 10, nivel: 4 }],
    }],
    ['seed 42', 42, {
        entrada: { x: 0, y: 0 },
        salida: { x: 14, y: 25, distancia: 523 },
        puertas: [{ x: 14, y: 10 }, { x: 5, y: 17 }],
        llaves: [{ x: 11, y: 1, m: 15 }, { x: 14, y: 7, m: 18 }, { x: 28, y: 16, m: 43 }],
        cofres: [{ x: 28, y: 5, nivel: 6 }, { x: 25, y: 18, nivel: 7 }, { x: 22, y: 7, nivel: 7 }, { x: 24, y: 2, nivel: 7 }, { x: 5, y: 14, nivel: 4 }],
    }],
    ['seed 12345', 12345, {
        entrada: { x: 0, y: 0 },
        salida: { x: 12, y: 15, distancia: 397 },
        puertas: [{ x: 21, y: 1 }, { x: 11, y: 11 }],
        llaves: [{ x: 5, y: 6, m: 14 }, { x: 28, y: 3, m: 7 }, { x: 9, y: 12, m: 117 }],
        cofres: [{ x: 6, y: 17, nivel: 7 }, { x: 3, y: 24, nivel: 6 }, { x: 3, y: 10, nivel: 6 }, { x: 17, y: 29, nivel: 6 }, { x: 6, y: 28, nivel: 6 }, { x: 9, y: 5, nivel: 7 }, { x: 0, y: 28, nivel: 6 }],
    }],
    ['seed 2026', 2026, {
        entrada: { x: 0, y: 0 },
        salida: { x: 11, y: 25, distancia: 432 },
        puertas: [{ x: 25, y: 5 }, { x: 11, y: 13 }],
        llaves: [{ x: 3, y: 0, m: 4 }, { x: 10, y: 0, m: 37 }, { x: 29, y: 24, m: 144 }],
        cofres: [{ x: 19, y: 1, nivel: 3 }, { x: 17, y: 9, nivel: 6 }, { x: 2, y: 12, nivel: 6 }, { x: 3, y: 9, nivel: 6 }, { x: 23, y: 21, nivel: 6 }, { x: 22, y: 15, nivel: 7 }, { x: 18, y: 18, nivel: 6 }, { x: 23, y: 29, nivel: 5 }],
    }],
];

describe('marcas', () => {
    test.each(seedsMarcas)('produce las marcas esperadas para %s en un mapa de 30x30', (_nombre, seed, marcasEsperadas) => {
        const matriz = generarLaberinto(seed, 30, 30);

        expect(marcas(matriz, seed)).toEqual(marcasEsperadas);
    });

    test('esValido rechaza un mapa cuyo camino no llega a CAMINO_MINIMO', () => {
        const matriz = generarLaberinto(1, 10, 10);
        const m = marcas(matriz, 1);

        expect(m.salida.distancia).toBeLessThan(CAMINO_MINIMO);
        expect(esValido(m)).toBe(false);
    });
});
