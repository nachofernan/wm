/**
 * El campo de encuentros: para cada celda, con qué probabilidad (y de qué
 * elemento) puede saltar un monstruo. Función pura del seed, espejo bit a bit
 * de app/Game/EncuentroBuilder.php (docs/DECISIONES.md 016,
 * docs/PROTOCOLO_GENERADOR.md §5).
 *
 * Produce solo el SESGO (público, paritario, se pinta). El DISPARO
 * ("¿me saltó algo?") es secreto del servidor y no sale de acá.
 *
 * El hash de paridad vive aparte, en encuentroHash.js, para no arrastrar
 * node:crypto al bundle del navegador (mismo split que maze.js/mazeHash.js).
 */
import { crearPrng, randBelow } from './prng.js';

// Stream propio, decorrelado del maze y las marcas (seed XOR esta constante).
// Idéntico al de EncuentroBuilder.php o la paridad se rompe.
const SEMILLA = 0x85ebca6b;

// Orden canónico para UBICAR (no es la rueda de ventaja de combate): fija el
// índice que consume el PRNG. Cambiarlo cambia todos los campos.
export const ELEMENTOS = ['fuego', 'agua', 'tierra', 'aire'];

export const AMBIENTE = 3; // piso de encuentro en toda celda (%), nunca cero — arranque
export const DECAIMIENTO = 2; // cuánto cae la probabilidad por anillo (%)
export const CELDAS_POR_NUCLEO = 400; // una colmena cada tantas celdas de área
export const PICO_BASE = 10;
export const PICO_VARIACION = 6;

// Cada núcleo consume el PRNG exactamente cuatro veces (x, y, elemento, pico),
// en ese orden. Un consumo de más o de menos desincroniza la paridad.
function sortearNucleos(next, ancho, alto) {
    const cantidad = Math.max(1, Math.floor((ancho * alto) / CELDAS_POR_NUCLEO));
    const nucleos = [];

    for (let i = 0; i < cantidad; i++) {
        const x = randBelow(next, ancho);
        const y = randBelow(next, alto);
        const elem = ELEMENTOS[randBelow(next, ELEMENTOS.length)];
        const pico = PICO_BASE + randBelow(next, PICO_VARIACION);
        nucleos.push({ x, y, elem, pico });
    }

    return nucleos;
}

// Pinta el campo sin tocar el PRNG: arranca del ambiente y cada colmena eleva
// las celdas de su radio al máximo entre lo que había y su valor por anillo.
// En empate gana la colmena de menor índice (comparación estricta). La entrada
// (0,0) se fuerza a 0: nadie salta encima del mago al arrancar.
function pintarCampo(nucleos, ancho, alto) {
    const celdas = [];
    for (let y = 0; y < alto; y++) {
        const fila = [];
        for (let x = 0; x < ancho; x++) {
            fila.push({ prob: AMBIENTE, elem: null });
        }
        celdas.push(fila);
    }

    for (const n of nucleos) {
        const radio = Math.floor((n.pico - AMBIENTE) / DECAIMIENTO);
        for (let dy = -radio; dy <= radio; dy++) {
            for (let dx = -radio; dx <= radio; dx++) {
                const cx = n.x + dx;
                const cy = n.y + dy;
                if (cx < 0 || cx >= ancho || cy < 0 || cy >= alto) continue;
                const anillo = Math.max(Math.abs(dx), Math.abs(dy));
                const prob = n.pico - DECAIMIENTO * anillo;
                if (prob > celdas[cy][cx].prob) {
                    celdas[cy][cx] = { prob, elem: n.elem };
                }
            }
        }
    }

    celdas[0][0] = { prob: 0, elem: null };

    return celdas;
}

/**
 * Campo de encuentros de un laberinto de ancho×alto.
 * @returns {{nucleos: Array, celdas: Array<Array<{prob:number, elem:string|null}>>}}
 */
export function campo(seed, ancho, alto) {
    const next = crearPrng(seed ^ SEMILLA);
    const nucleos = sortearNucleos(next, ancho, alto);
    const celdas = pintarCampo(nucleos, ancho, alto);

    return { nucleos, celdas };
}
