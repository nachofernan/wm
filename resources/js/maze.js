import { crearPrng, randBelow } from './prng.js';

/**
 * Generador de laberintos por backtracking recursivo, iterativo con pila
 * explícita. Espejo bit a bit de app/Game/MazeGenerator.php. Ver
 * docs/PROTOCOLO_GENERADOR.md §3 y §4.
 *
 * Produce solo la topología (paredes); la ubicación de contenidos (llave,
 * salida, cofres, monstruos) no está diseñada todavía (§5 del protocolo).
 */

// Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1
const DIRECCIONES = [
    { nombre: 'N', dx: 0, dy: -1, opuesta: 'S' },
    { nombre: 'E', dx: 1, dy: 0, opuesta: 'O' },
    { nombre: 'S', dx: 0, dy: 1, opuesta: 'N' },
    { nombre: 'O', dx: -1, dy: 0, opuesta: 'E' },
];

function crearCelda() {
    return { N: 1, E: 1, S: 1, O: 1 };
}

function crearMatriz(ancho, alto) {
    const matriz = [];
    for (let y = 0; y < alto; y++) {
        const fila = [];
        for (let x = 0; x < ancho; x++) {
            fila.push(crearCelda());
        }
        matriz.push(fila);
    }
    return matriz;
}

export function generarLaberinto(seed, ancho, alto) {
    const next = crearPrng(seed);
    const matriz = crearMatriz(ancho, alto);
    const visitada = matriz.map((fila) => fila.map(() => false));
    const pila = [{ x: 0, y: 0 }];
    visitada[0][0] = true;

    while (pila.length > 0) {
        const actual = pila[pila.length - 1];
        const vecinos = DIRECCIONES
            .map((d) => ({ ...d, x: actual.x + d.dx, y: actual.y + d.dy }))
            .filter((v) => v.x >= 0 && v.x < ancho && v.y >= 0 && v.y < alto && !visitada[v.y][v.x]);

        if (vecinos.length === 0) {
            pila.pop();
            continue;
        }

        const elegido = vecinos[randBelow(next, vecinos.length)];
        matriz[actual.y][actual.x][elegido.nombre] = 0;
        matriz[elegido.y][elegido.x][elegido.opuesta] = 0;
        visitada[elegido.y][elegido.x] = true;
        pila.push({ x: elegido.x, y: elegido.y });
    }

    return matriz;
}
