import { describe, expect, test } from 'vitest';
import { generarLaberinto } from './maze.js';
import { hashLaberinto } from './mazeHash.js';

/**
 * El test más importante del proyecto (CLAUDE.md). Vector de paridad:
 * (seed, ancho, alto) → hash SHA-256 del laberinto. Estos mismos casos y
 * hashes están commiteados en tests/Unit/Game/MazeGeneratorTest.php. Si un
 * test cambia, el otro tiene que cambiar igual, o la paridad PHP/JS está rota.
 */
const seedsLaberinto = [
    ['seed 1, 10x10', 1, 10, 10, 'd2c1a5b8ab4caf9d85bacb1864a8ec6a9063db17284e7e1c0a311223fd5b8b9a'],
    ['seed 42, 10x10', 42, 10, 10, '3026abb01eea87e9f01f8f0fb43f164ab80dcdc4f38b091a28396e6e57018d70'],
    ['seed 12345, 20x15', 12345, 20, 15, '9406f813c7770e4770d4bd67aebf14a5bc370b2bc98589bdd13173028ea2f1fd'],
    ['seed 7, 100x100 (tamaño canónico)', 7, 100, 100, '1675b97c02b874770028bbb2babe660d09a92e7af6a9f6b19c5266952e4210d6'],
];

describe('generarLaberinto', () => {
    test.each(seedsLaberinto)('produce el hash esperado para %s', (_nombre, seed, ancho, alto, hashEsperado) => {
        const matriz = generarLaberinto(seed, ancho, alto);

        expect(hashLaberinto(matriz)).toBe(hashEsperado);
    });

    test('el mismo seed y tamaño siempre producen el mismo laberinto', () => {
        const a = generarLaberinto(2026, 15, 15);
        const b = generarLaberinto(2026, 15, 15);

        expect(hashLaberinto(a)).toBe(hashLaberinto(b));
    });
});
