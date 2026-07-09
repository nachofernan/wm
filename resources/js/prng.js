/**
 * PRNG determinista (mulberry32), espejo bit a bit de app/Game/Prng.php.
 * Ver docs/PROTOCOLO_GENERADOR.md §2.
 */
export function crearPrng(seed) {
    let estado = seed >>> 0;

    return function next() {
        estado = (estado + 0x6D2B79F5) >>> 0;
        let t = estado;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return (t ^ (t >>> 14)) >>> 0;
    };
}

export function randBelow(next, n) {
    return next() % n;
}
