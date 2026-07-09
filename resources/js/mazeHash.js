import { createHash } from 'node:crypto';

/**
 * Hash de paridad — docs/PROTOCOLO_GENERADOR.md §6. Solo para el test de
 * paridad y herramientas offline (no se importa desde el bundle del
 * navegador: usa node:crypto). Espejo de MazeGenerator::hash() en PHP.
 */
export function hashLaberinto(matriz) {
    const bytes = [];
    for (const fila of matriz) {
        for (const celda of fila) {
            bytes.push((celda.N << 3) | (celda.E << 2) | (celda.S << 1) | celda.O);
        }
    }

    return createHash('sha256').update(Uint8Array.from(bytes)).digest('hex');
}
