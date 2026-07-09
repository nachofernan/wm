/**
 * Ubica el contenido del laberinto (entrada, salida, puertas, llaves) sobre
 * una matriz ya generada. Espejo bit a bit de app/Game/MapaBuilder.php.
 * Puerto del algoritmo prototipado en resources/views/welcome.blade.php.
 *
 * El cliente recibe el seed ya validado por el servidor (MapaBuilder::buscarSeed
 * en PHP) — acá no hace falta reintentar seeds, solo recalcular las marcas.
 */

// Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1
const DIRECCIONES = [
    { nombre: 'N', dx: 0, dy: -1 },
    { nombre: 'E', dx: 1, dy: 0 },
    { nombre: 'S', dx: 0, dy: 1 },
    { nombre: 'O', dx: -1, dy: 0 },
];

/** El camino entrada→salida tiene que medir al menos esto. */
export const CAMINO_MINIMO = 400;

/** Distancias (sobre el camino, desde la entrada) donde va cada puerta. */
export const PUERTAS_EN = [100, 200];

/** Cada llave tiene que estar en un brazo de al menos esta extensión. */
export const BRAZO_MINIMO = 25;

// BFS sobre el grafo del laberinto (paredes abiertas), no distancia euclidiana
function calcularDistancias(matriz, inicioX, inicioY) {
    const alto = matriz.length;
    const ancho = matriz[0].length;
    const distancias = matriz.map((fila) => fila.map(() => -1));
    distancias[inicioY][inicioX] = 0;
    const cola = [{ x: inicioX, y: inicioY }];

    while (cola.length > 0) {
        const actual = cola.shift();
        DIRECCIONES.forEach((d) => {
            const nx = actual.x + d.dx;
            const ny = actual.y + d.dy;
            if (nx < 0 || nx >= ancho || ny < 0 || ny >= alto) return;
            if (matriz[actual.y][actual.x][d.nombre] === 1) return;
            if (distancias[ny][nx] !== -1) return;
            distancias[ny][nx] = distancias[actual.y][actual.x] + 1;
            cola.push({ x: nx, y: ny });
        });
    }

    return distancias;
}

function celdaMasLejana(distancias) {
    let mejor = { x: 0, y: 0, distancia: -1 };
    distancias.forEach((fila, y) => {
        fila.forEach((distancia, x) => {
            if (distancia > mejor.distancia) {
                mejor = { x, y, distancia };
            }
        });
    });
    return mejor;
}

// Cuánto se extiende una celda por fuera del camino inicio→salida: su
// distancia al punto de desprendimiento. Da 0 para las celdas sobre el
// camino. m = (dInicio + dSalida - total) / 2.
function extensionDesdeCamino(dInicio, dSalida, total) {
    return (dInicio + dSalida - total) / 2;
}

// A qué segmento del camino pertenece un punto de desprendimiento k, dado
// el orden de puertas (p.ej. puertas [100, 200] → segmentos [0,100),
// [100,200), [200,total]).
function segmentoDe(k, puertasEn) {
    for (let i = 0; i < puertasEn.length; i++) {
        if (k < puertasEn[i]) return i;
    }
    return puertasEn.length;
}

function ubicarPuertas(distanciasInicio, distanciasSalida, total) {
    const puertas = PUERTAS_EN.map(() => null);

    distanciasInicio.forEach((fila, y) => {
        fila.forEach((dInicio, x) => {
            if (dInicio + distanciasSalida[y][x] !== total) return;
            const idx = PUERTAS_EN.indexOf(dInicio);
            if (idx !== -1) puertas[idx] = { x, y };
        });
    });

    return puertas;
}

// Una llave por segmento: la punta del brazo más largo que cuelga del
// camino en ese tramo, elegida por su extensión (m), no por distancia a
// ninguna puerta.
function ubicarLlaves(distanciasInicio, distanciasSalida, total) {
    const llaves = new Array(PUERTAS_EN.length + 1).fill(null);

    distanciasInicio.forEach((fila, y) => {
        fila.forEach((dInicio, x) => {
            const dSalida = distanciasSalida[y][x];
            const m = extensionDesdeCamino(dInicio, dSalida, total);
            if (m === 0) return;

            const k = dInicio - m;
            const seg = segmentoDe(k, PUERTAS_EN);

            if (!llaves[seg] || m > llaves[seg].m) {
                llaves[seg] = { x, y, m };
            }
        });
    });

    return llaves;
}

/**
 * Calcula entrada, salida, puertas y llaves para una matriz ya generada.
 * No valida las restricciones de diseño — para eso, esValido().
 */
export function marcas(matriz) {
    const distanciasInicio = calcularDistancias(matriz, 0, 0);
    const salida = celdaMasLejana(distanciasInicio);
    const distanciasSalida = calcularDistancias(matriz, salida.x, salida.y);

    return {
        entrada: { x: 0, y: 0 },
        salida,
        puertas: ubicarPuertas(distanciasInicio, distanciasSalida, salida.distancia),
        llaves: ubicarLlaves(distanciasInicio, distanciasSalida, salida.distancia),
    };
}

export function esValido(marcas) {
    if (marcas.salida.distancia < CAMINO_MINIMO) return false;
    if (marcas.puertas.some((p) => !p)) return false;
    if (marcas.llaves.some((l) => !l || l.m < BRAZO_MINIMO)) return false;
    return true;
}
