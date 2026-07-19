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

/** Tope de cofres por laberinto (DECISIÓN 035): top-N puntas de brazo. */
export const MAX_COFRES = 8;

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

// Cuántas celdas vecinas navegables tiene (x,y): 1 = punta / callejón sin salida.
function pasajes(matriz, x, y, ancho, alto) {
    let n = 0;
    DIRECCIONES.forEach((d) => {
        const nx = x + d.dx;
        const ny = y + d.dy;
        if (nx < 0 || nx >= ancho || ny < 0 || ny >= alto) return;
        if (matriz[y][x][d.nombre] === 0) n++;
    });
    return n;
}

// Nivel entero 1..7 de la gema de un cofre según la profundidad de su celda
// (t = dInicio/total): mismo eje que dificultadCelda y misma escala que el nivel
// de un monstruo (round(1 + t·6), clampeada). Espejo de MapaBuilder::nivelCofre.
function nivelCofre(dInicio, total) {
    const t = total > 0 ? dInicio / total : 0;
    return Math.max(1, Math.min(7, Math.round(1 + t * 6)));
}

// Hasta MAX_COFRES cofres en las puntas de brazo más largas de TODO el laberinto
// (DECISIÓN 035): top-N global por extensión m (no uno por segmento como las
// llaves). Una punta es un callejón sin salida (una sola vecina navegable) que
// cuelga del camino con m ≥ BRAZO_MINIMO, excluidas las celdas ocupadas (entrada,
// salida, puertas, llaves). Espejo bit a bit de MapaBuilder::ubicarCofres.
function ubicarCofres(matriz, distanciasInicio, distanciasSalida, total, ocupadas) {
    const ancho = matriz[0].length;
    const alto = matriz.length;

    const candidatos = [];
    distanciasInicio.forEach((fila, y) => {
        fila.forEach((dInicio, x) => {
            if (dInicio < 0) return;
            const m = extensionDesdeCamino(dInicio, distanciasSalida[y][x], total);
            if (m < BRAZO_MINIMO) return; // brazo corto (también descarta el camino, m=0)
            if (pasajes(matriz, x, y, ancho, alto) !== 1) return; // no es una punta
            if (ocupadas.has(`${x},${y}`)) return; // llave/puerta/entrada/salida
            candidatos.push({ x, y, m, nivel: nivelCofre(dInicio, total) });
        });
    });

    // Top-N por m; desempate determinista y paritario (y asc, luego x asc).
    candidatos.sort((a, b) => b.m - a.m || a.y - b.y || a.x - b.x);

    return candidatos.slice(0, MAX_COFRES).map((c) => ({ x: c.x, y: c.y, nivel: c.nivel }));
}

/**
 * Calcula entrada, salida, puertas, llaves y cofres para una matriz ya generada.
 * No valida las restricciones de diseño — para eso, esValido().
 */
export function marcas(matriz) {
    const distanciasInicio = calcularDistancias(matriz, 0, 0);
    const salida = celdaMasLejana(distanciasInicio);
    const distanciasSalida = calcularDistancias(matriz, salida.x, salida.y);

    const puertas = ubicarPuertas(distanciasInicio, distanciasSalida, salida.distancia);
    const llaves = ubicarLlaves(distanciasInicio, distanciasSalida, salida.distancia);

    // Celdas vedadas para un cofre: entrada, salida, puertas y llaves. Espejo del
    // set $ocupadas de MapaBuilder::marcas.
    const ocupadas = new Set(['0,0', `${salida.x},${salida.y}`]);
    puertas.forEach((p) => { if (p) ocupadas.add(`${p.x},${p.y}`); });
    llaves.forEach((l) => { if (l) ocupadas.add(`${l.x},${l.y}`); });

    return {
        entrada: { x: 0, y: 0 },
        salida,
        puertas,
        llaves,
        cofres: ubicarCofres(matriz, distanciasInicio, distanciasSalida, salida.distancia, ocupadas),
    };
}

/**
 * Distancias BFS desde la entrada (0,0) a cada celda. Tooling de dev (027):
 * alimenta el panel de celda (distancia + poder del monstruo x(1+t)). El
 * poder REAL de combate lo calcula el servidor con MapaBuilder::dificultadCelda
 * (autoridad, axioma 4); esto es solo para verlo mientras se testea.
 */
export function distanciasEntrada(matriz) {
    return calcularDistancias(matriz, 0, 0);
}

export function esValido(marcas) {
    if (marcas.salida.distancia < CAMINO_MINIMO) return false;
    if (marcas.puertas.some((p) => !p)) return false;
    if (marcas.llaves.some((l) => !l || l.m < BRAZO_MINIMO)) return false;
    return true;
}
