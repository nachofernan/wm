import { createHash } from 'node:crypto';
import { ELEMENTOS } from './encuentroBuilder.js';

/**
 * Hash de paridad del campo de encuentros — espejo de EncuentroBuilder::hash()
 * en PHP. Dos bytes por celda: probabilidad y código de elemento (0 = sin
 * elemento, si no índice+1 en ELEMENTOS). Solo para el test de paridad y
 * herramientas offline (usa node:crypto): no se importa desde el bundle del
 * navegador. Mismo split que maze.js/mazeHash.js.
 */
export function hashCampo(campoCalculado) {
    const bytes = [];
    for (const fila of campoCalculado.celdas) {
        for (const celda of fila) {
            const codigo = celda.elem === null ? 0 : ELEMENTOS.indexOf(celda.elem) + 1;
            bytes.push(celda.prob, codigo);
        }
    }

    return createHash('sha256').update(Uint8Array.from(bytes)).digest('hex');
}
