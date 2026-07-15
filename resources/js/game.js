import { generarLaberinto } from './maze.js';
import { marcas as calcularMarcas, distanciasEntrada } from './mapaBuilder.js';
import { campo as calcularCampo } from './encuentroBuilder.js';
import { crearPrng, randBelow } from './prng.js';

const CELDA = 24; // px por celda en el canvas

// Color del tinte de encuentro por elemento (docs/DECISIONES.md 016: color =
// elemento, alpha = probabilidad). Las celdas de solo ambiente (sin elemento)
// no se tiñen — el peligro parejo no se pinta, se sospecha.
const COLOR_ELEMENTO = {
    fuego: '235, 60, 10', // rojo-naranja bien saturado: el naranja anterior se confundía con tierra
    agua: '40, 120, 220',
    tierra: '140, 105, 75', // desaturado, para que no compita con el fuego
    aire: '70, 170, 70', // verde: el gris-lavanda anterior no se notaba sobre el fondo claro del canvas
};

const COLOR_MARCA = {
    entrada: 'green',
    salidaCerrada: 'green',
    salidaAbierta: 'blue',
    puertaCerrada: 'gold',
    puertaAbierta: 'deepskyblue',
    llave: 'orange',
};

// Rueda elemental — espejo de CombatResolver::VENCE_A (PLACEHOLDER, docs/DISENO.md §3):
// cada elemento le gana al que figura acá. Se usa SOLO para mostrar el matchup y
// estimar daño/bloqueo en la vista; la resolución real la hace el servidor (axioma 4).
const VENCE_A = { fuego: 'aire', aire: 'tierra', tierra: 'agua', agua: 'fuego' };

// Orden canónico para filtros/ordenamiento del inventario.
const ELEMENTOS = ['fuego', 'agua', 'tierra', 'aire'];

// Números de arranque espejo de CombatResolver::DEFAULTS — solo para el preview.
const COMBATE = { F: 3, K: 50, ventaja: 1.5, neutral: 1.0, reves: 0.5, defVentaja: 0.5, defNeutral: 1.0, defReves: 2.0, vidaPorEsencia: 3 };

// Radio de visión total alrededor del mago (Chebyshev). Fuera de acá está la niebla.
const RADIO_VISION = 1;

// Clave del guardado de navegación en localStorage, por token. La posición y la
// niebla explorada son puro cliente (nunca viajan al servidor, axioma 3), así que
// sin esto una recarga te devuelve a la entrada. Es por partida: un token nuevo
// (nueva partida) no tiene guardado y arranca limpio.
const CLAVE_NAV = 'wm_nav';

// Tope de gemas fieldeadas — espejo de Talisman::RANURAS (025). Fieldear valida
// esto Y el cap (suma de niveles); acá se replica solo para el preview del botón.
const RANURAS = 6;

// Esencia pura que cuesta fusionar — espejo de Talisman::COSTO_FUSION (027). Acá
// solo deshabilita el botón cuando no alcanza; el servidor valida de verdad.
const COSTO_FUSION = 1;

// Índice del guardián de la salida (docs/DECISIONES.md 032) — espejo de
// MazeCombate::INDICE_SALIDA. Los guardianes de llave usan el índice de su llave
// (0..2); la salida, el 3. La llave que abre la salida es la que sobra (índice =
// cantidad de puertas), distinta del guardián que la custodia.
const INDICE_SALIDA = 3;

// Tope de carga por nivel y costo de recarga — espejo de Talisman (026/028). El
// tope de una gema es nivel × 6; recargarla al tope cuesta nivel × 1 de esencia.
const CARGA_POR_NIVEL = 6;
const COSTO_RECARGA_POR_NIVEL = 1;

/** Relación del elemento a frente a b: 'ventaja' | 'reves' | 'neutral'. */
function matchup(a, b) {
    if (VENCE_A[a] === b) return 'ventaja';
    if (VENCE_A[b] === a) return 'reves';
    return 'neutral';
}

// Mismo mapeo de direcciones que el generador — docs/PROTOCOLO_GENERADOR.md §3.1
const TECLAS = {
    ArrowUp: { nombre: 'N', dx: 0, dy: -1 },
    w: { nombre: 'N', dx: 0, dy: -1 },
    ArrowRight: { nombre: 'E', dx: 1, dy: 0 },
    d: { nombre: 'E', dx: 1, dy: 0 },
    ArrowDown: { nombre: 'S', dx: 0, dy: 1 },
    s: { nombre: 'S', dx: 0, dy: 1 },
    ArrowLeft: { nombre: 'O', dx: -1, dy: 0 },
    a: { nombre: 'O', dx: -1, dy: 0 },
};

/**
 * Estado de la partida en el cliente. Regenera el laberinto desde el seed
 * que manda el servidor (window.__MAZE__) y lo mantiene en memoria — nunca
 * viaja por la red. Ver CLAUDE.md, axioma 1 y 3.
 *
 * Cada llave abre la puerta de su mismo índice (llave 0 → puerta 0, etc.); la
 * última llave, la que sobra, abre la salida. Una llave no se recoge sola: la
 * custodia un guardián telegrafiado que hay que vencer (docs/DECISIONES.md 032),
 * y la salida abierta tiene su propio boss final. El set de llaves conseguidas es
 * verdad del servidor (llega en el estado); acá solo se refleja en el mapa.
 */
export function game() {
    return {
        token: null,
        seed: null,
        matriz: null,
        marcas: null,
        distancias: null,
        campo: null,
        ancho: 0,
        alto: 0,
        alturaPx: 0,
        mago: { x: 0, y: 0 },
        pasos: 0, // pasos caminados: alimenta la tirada local de encuentro y el índice del monstruo
        visitadas: {}, // celdas ya pisadas ("x,y" → true): se dibujan en gris bajo la niebla
        puertasAbiertas: [],
        llavesRecogidas: [],
        salidaAbierta: false,
        terminado: false,
        movimientos: [],

        // Config de dificultad visual (DECISIÓN 033): con caminoOpaco, las celdas
        // ya exploradas fuera del radio se tapan con un gris sólido — se ve por
        // dónde pasaste, pero NO las paredes ni el tinte, así que la vuelta hay que
        // recordarla. Apagado, es el velo translúcido y el laberinto se intuye. Se
        // togglea desde el panel de configuración y se persiste global (no por token).
        caminoOpaco: true,

        // Ver el tinte de colmena en el rastro gris opaco (033): con caminoOpaco el
        // gris tapa todo, pero con esto se repinta el sesgo de encuentro encima —
        // ves dónde había peligro aunque las paredes sigan ocultas. Solo tiene efecto
        // con caminoOpaco (sin él, el velo translúcido ya deja ver el tinte).
        verColmenas: false,

        // Estado de personaje y combate — verdad del servidor (axioma 4). El
        // cliente solo renderiza lo que recibe y manda acciones.
        talisman: null,
        combate: null,
        guardian: null, // guardián revelado en la celda de llave/salida (staging, 032):
                        // se ve el boss con el combate aún cerrado (talismán libre) y
                        // recién al "pelear" se abre el combate. null = sin staging.
        resultado: null, // 'victoria' | 'derrota' | null
        drop: null,
        bichoResuelto: null, // monstruo del último combate cerrado: se muestra en la
                             // pantalla de victoria/derrota (el server ya mandó combate:null)
        consola: [],
        // Una llamada al servidor en vuelo: bloquea acciones concurrentes y prende
        // el spinner. accionActiva marca CUÁL botón gira (DECISIONES.md 023).
        cargando: false,
        accionActiva: null,

        // Filtro y orden del inventario, y orden de las fieldeadas (puro cliente,
        // no viaja al servidor). Mayores siempre arriba.
        filtroInv: null, // null = todos, o 'fuego'|'agua'|'tierra'|'aire'
        fusionSel: null, // id de la 1ª gema elegida para fusionar (025), o null
        ordenInv: 'elemento', // 'nivel' | 'carga' | 'elemento' — arranca por tipo
        ordenField: 'elemento', // idem para el talismán (fieldeadas): arranca por tipo
        ordenFieldIds: [], // orden CONGELADO de las fieldeadas (ids); solo se recalcula al cambiar
                           // el select o el set fieldeado — nunca por un ataque que baje carga
        arrastrando: null, // id de la gema fieldeada que se está arrastrando, o null

        init() {
            const { seed, ancho, alto, token, estado } = window.__MAZE__;
            this.token = token;
            this.seed = seed;
            this.leerConfig(); // preferencias de display (caminoOpaco), persistidas global
            this.talisman = estado.talisman;
            this.combate = estado.combate;
            this.registrar('entrás al laberinto. WASD o flechas para caminar.');
            this.matriz = generarLaberinto(seed, ancho, alto);
            this.marcas = calcularMarcas(this.matriz);
            this.distancias = distanciasEntrada(this.matriz); // panel de celda (027)
            this.campo = calcularCampo(seed, ancho, alto);
            this.ancho = ancho;
            this.alto = alto;
            this.alturaPx = alto * CELDA;
            // Las llaves son verdad del servidor (032): el estado trae los índices
            // ya conseguidos. Cada llave i abre la puerta i; la que sobra (índice =
            // cantidad de puertas) abre la salida.
            this.sincronizarLlaves(estado.llaves || []);
            // Recupera dónde estabas y qué exploraste: sin esto la recarga te
            // devuelve a la entrada (la posición no vive en el servidor).
            this.restaurarNavegacion();
            this.visitadas[`${this.mago.x},${this.mago.y}`] = true;
            this.reordenarField(); // el talismán arranca ordenado por tipo, no en el orden crudo
            this.$nextTick(() => this.dibujar());
        },

        dibujar() {
            const canvas = this.$refs.canvas;
            canvas.width = this.ancho * CELDA;
            canvas.height = this.alto * CELDA;

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            this.dibujarCampo(ctx);

            ctx.strokeStyle = 'black';
            ctx.lineWidth = 2;

            ctx.beginPath();
            this.matriz.forEach((fila, y) => {
                fila.forEach((celda, x) => {
                    const px = x * CELDA;
                    const py = y * CELDA;
                    if (celda.N) { ctx.moveTo(px, py); ctx.lineTo(px + CELDA, py); }
                    if (celda.E) { ctx.moveTo(px + CELDA, py); ctx.lineTo(px + CELDA, py + CELDA); }
                    if (celda.S) { ctx.moveTo(px, py + CELDA); ctx.lineTo(px + CELDA, py + CELDA); }
                    if (celda.O) { ctx.moveTo(px, py); ctx.lineTo(px, py + CELDA); }
                });
            });
            ctx.stroke();

            // La niebla tapa el laberinto (paredes + tinte de encuentro) donde no
            // hay visión: negro en lo no explorado, un velo gris en lo ya pisado.
            // Se pinta ANTES de las marcas para que llaves/puertas queden como
            // faros visibles por encima (docs/DECISIONES.md: objetivos a la vista).
            this.dibujarNiebla(ctx);
            this.dibujarMarcas(ctx);

            ctx.fillStyle = 'blue';
            ctx.beginPath();
            ctx.arc(
                this.mago.x * CELDA + CELDA / 2,
                this.mago.y * CELDA + CELDA / 2,
                CELDA / 3,
                0,
                Math.PI * 2
            );
            ctx.fill();
        },

        /**
         * Tiñe cada celda con su sesgo de encuentro: color = elemento de la
         * colmena, alpha = probabilidad (docs/DECISIONES.md 016). Es el sesgo
         * público; el disparo real lo esconde el servidor. Las celdas de solo
         * ambiente no se pintan — el peligro parejo se sospecha, no se ve.
         */
        dibujarCampo(ctx) {
            this.campo.celdas.forEach((fila, y) => {
                fila.forEach((_, x) => this.pintarTinte(ctx, x, y));
            });
        },

        /**
         * Pinta el tinte de encuentro de una celda (color = elemento, alpha = prob).
         * Lo usa dibujarCampo (mapa base) y la niebla, cuando verColmenas repinta el
         * sesgo sobre el rastro gris opaco (033). Las celdas de solo ambiente no se pintan.
         */
        pintarTinte(ctx, x, y) {
            const celda = this.campo.celdas[y][x];
            if (!celda.elem) return;
            const alpha = Math.min(0.6, celda.prob / 20);
            ctx.fillStyle = `rgba(${COLOR_ELEMENTO[celda.elem]}, ${alpha})`;
            ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
        },

        /** ¿La celda (x,y) está dentro del radio de visión total del mago? */
        visible(x, y) {
            return Math.max(Math.abs(x - this.mago.x), Math.abs(y - this.mago.y)) <= RADIO_VISION;
        },

        /**
         * Niebla de guerra (docs/DECISIONES.md): el mapa arranca en negro; solo
         * se ve el radio del mago (RADIO_VISION alrededor) con detalle completo,
         * y el rastro ya caminado queda en gris. Las marcas se dibujan aparte,
         * por encima, así los objetivos (llaves/puertas/salida) se ven desde el
         * arranque aunque no hayas llegado.
         */
        dibujarNiebla(ctx) {
            for (let y = 0; y < this.alto; y++) {
                for (let x = 0; x < this.ancho; x++) {
                    if (this.visible(x, y)) continue; // radio: detalle completo
                    if (this.visitadas[`${x},${y}`]) {
                        // Rastro explorado. Opaco (033): gris sólido que tapa paredes
                        // y tinte — ves el camino hecho, no cómo volver. Apagado: velo
                        // translúcido y el laberinto se intuye por debajo.
                        ctx.fillStyle = this.caminoOpaco ? '#5b5b66' : 'rgba(120, 120, 135, 0.55)';
                        ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
                        // Con verColmenas, el sesgo de encuentro se repinta sobre el
                        // gris: se ve dónde había colmena aunque las paredes sigan tapadas.
                        if (this.caminoOpaco && this.verColmenas) this.pintarTinte(ctx, x, y);
                    } else {
                        ctx.fillStyle = '#0a0a0d'; // no explorado: negro
                        ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
                    }
                }
            }
        },

        dibujarMarcas(ctx) {
            const pintar = ({ x, y }, color) => {
                ctx.fillStyle = color;
                ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
            };

            // Ícono centrado sobre la celda ya pintada (llave/candado). Contra el
            // fondo dorado/naranja de la marca, el emoji sin más se pierde — se le
            // pone un badge oscuro atrás para que siempre haya contraste.
            const icono = ({ x, y }, simbolo) => {
                const cx = x * CELDA + CELDA / 2;
                const cy = y * CELDA + CELDA / 2;
                ctx.beginPath();
                ctx.arc(cx, cy, CELDA * 0.4, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(10, 10, 14, 0.8)';
                ctx.fill();
                ctx.font = `${CELDA * 0.62}px sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(simbolo, cx, cy + 1);
            };

            pintar(this.marcas.entrada, COLOR_MARCA.entrada);
            pintar(this.marcas.salida, this.salidaAbierta ? COLOR_MARCA.salidaAbierta : COLOR_MARCA.salidaCerrada);

            this.marcas.puertas.forEach((p, i) => {
                pintar(p, this.puertasAbiertas[i] ? COLOR_MARCA.puertaAbierta : COLOR_MARCA.puertaCerrada);
                if (!this.puertasAbiertas[i]) icono(p, '🔒');
            });

            this.marcas.llaves.forEach((l, i) => {
                if (!this.llavesRecogidas[i]) {
                    pintar(l, COLOR_MARCA.llave);
                    icono(l, '🔑');
                }
            });
        },

        buscarPuerta(x, y) {
            return this.marcas.puertas.findIndex((p) => p.x === x && p.y === y);
        },

        buscarLlave(x, y) {
            return this.marcas.llaves.findIndex((l) => l.x === x && l.y === y);
        },

        esSalida(x, y) {
            return this.marcas.salida.x === x && this.marcas.salida.y === y;
        },

        // ── Panel de datos de celda (tooling de dev, DECISIÓN 027) ──────────
        // ¿La celda está DENTRO de una colmena? El campo de encuentros (016)
        // pinta un elemento solo en las celdas que un núcleo elevó sobre el
        // ambiente — así que un elem no nulo es "estás en una colmena", no solo
        // el núcleo exacto (que antes daba "normal" parado al lado del pico).
        esColmena(x, y) {
            return this.campo.celdas[y][x].elem !== null;
        },

        // Tipo de la celda (x,y): entrada / salida / puerta / llave / colmena /
        // normal. Es el dato que la visión (014) revelará gradual; hoy se ve entero.
        tipoCelda(x, y) {
            if (this.marcas.entrada.x === x && this.marcas.entrada.y === y) return 'entrada';
            if (this.esSalida(x, y)) return 'salida';
            if (this.buscarPuerta(x, y) !== -1) return 'puerta';
            if (this.buscarLlave(x, y) !== -1) return 'llave';
            if (this.esColmena(x, y)) return 'colmena';
            return 'normal';
        },

        // Datos de la celda donde está el mago, para el panel de la rueda. Reactivo:
        // depende de mago.x/y, así que el panel se actualiza al caminar. `dist` es
        // la distancia a la entrada y `poder` el multiplicador del monstruo x(1+t)
        // que el servidor aplica por distancia (027) — acá solo se muestra.
        celdaActual() {
            if (!this.campo || !this.marcas || !this.distancias) return null;
            const { x, y } = this.mago;
            const c = this.campo.celdas[y][x];
            const dist = this.distancias[y][x];
            const total = this.marcas.salida.distancia;
            const t = total > 0 && dist >= 0 ? dist / total : 0;
            return { x, y, prob: c.prob, elem: c.elem, tipo: this.tipoCelda(x, y), dist, poder: 1 + t };
        },

        mover(evento) {
            // No se camina con un combate abierto, un guardián en staging (032), un
            // drop sin resolver, ni una llamada al servidor en vuelo: la pelea frena
            // la marcha. El movimiento en sí ya no espera al servidor (022).
            if (this.terminado || this.combate || this.guardian || this.resultado || this.cargando) return;

            const direccion = TECLAS[evento.key];
            if (!direccion) return;
            evento.preventDefault();

            const celda = this.matriz[this.mago.y][this.mago.x];
            if (celda[direccion.nombre]) return; // pared cerrada

            const nx = this.mago.x + direccion.dx;
            const ny = this.mago.y + direccion.dy;

            const idxPuerta = this.buscarPuerta(nx, ny);
            if (idxPuerta !== -1 && !this.puertasAbiertas[idxPuerta]) return; // puerta cerrada

            if (this.esSalida(nx, ny) && !this.salidaAbierta) return; // salida cerrada

            this.mago.x = nx;
            this.mago.y = ny;
            this.pasos += 1;
            this.visitadas[`${nx},${ny}`] = true;
            this.movimientos.push({ dir: direccion.nombre, x: nx, y: ny });
            this.guardarNavegacion(); // que una recarga no pierda el paso recién dado

            this.dibujar();

            // Celda de llave con el guardián sin vencer: entrar dispara el staging
            // telegrafiado (docs/DECISIONES.md 032). La llave NO se recoge sola — hay
            // que matar a su guardián. La salida (con la 3ª llave ya conseguida) la
            // custodia el boss final. En ambos casos el combate lo abre el servidor;
            // acá solo se revela para armar el talismán antes de comprometerse.
            const idxLlave = this.buscarLlave(nx, ny);
            if (idxLlave !== -1 && !this.llavesRecogidas[idxLlave]) {
                this.revelarGuardian(idxLlave, nx, ny);
                return;
            }
            if (this.esSalida(nx, ny)) {
                this.revelarGuardian(INDICE_SALIDA, nx, ny);
                return;
            }

            // Ambiente: el cliente tira su dado y llama al server solo si saltó (022).
            if (this.tirarEncuentro(nx, ny)) {
                this.abrirCombate(nx, ny);
            }
        },

        /**
         * Dado de encuentro, ahora del cliente (docs/DECISIONES.md 022): el sesgo
         * (prob) sale del campo paritario; la tirada es determinista y pública
         * (seed + celda + pasos con el PRNG del proyecto). Perdió el secreto, pero
         * a cambio caminar es instantáneo y encaja con el pilar de planificación.
         */
        tirarEncuentro(x, y) {
            const prob = this.campo.celdas[y][x].prob;
            if (!prob) return false;
            const semilla = (this.seed ^ (x * 73856093) ^ (y * 19349663) ^ (this.pasos * 83492791)) >>> 0;
            return randBelow(crearPrng(semilla), 100) < prob;
        },

        /**
         * Saltó un bicho: sube la celda al servidor, que deriva el monstruo del
         * seed (autoridad de combate, axioma 4) y abre el combate. Es la única
         * llamada al servidor que corta la caminata.
         */
        async abrirCombate(x, y) {
            const prob = this.campo.celdas[y][x].prob;
            const datos = await this.pedir(`/jugar/${this.token}/encuentro`, { x, y, pasos: this.pasos }, 'encuentro');
            if (!datos) return;
            this.aplicarEstado(datos.estado);
            this.movimientos.push({ dir: 'encuentro', x, y });
            this.registrar(`⚔ (${x},${y}) riesgo ${prob}% · ¡te salta ${this.combate.monstruo.nombre} (${this.combate.monstruo.elemento})!`);
        },

        // ── Guardianes de llave y salida (docs/DECISIONES.md 032) ──────────
        /**
         * Revela el guardián de una celda de llave (índice de la llave) o de la
         * salida (INDICE_SALIDA), SIN abrir combate: es el staging telegrafiado. Con
         * el combate cerrado, el talismán sigue editable — armás el loadout y recién
         * ahí peleás. El bicho lo deriva el servidor del seed (autoridad, axioma 4).
         */
        async revelarGuardian(indice, x, y) {
            const datos = await this.pedir(`/jugar/${this.token}/guardian`, { indice, x, y }, 'guardian');
            if (!datos) return;
            this.guardian = { ...datos.guardian, indice, x, y };
            const cual = indice === INDICE_SALIDA
                ? 'el guardián de la salida'
                : `el guardián de la ${['primera', 'segunda', 'tercera'][indice] ?? `${indice + 1}ª`} llave`;
            this.registrar(`✦ ${cual}: ${this.guardian.nombre} (${this.guardian.elemento}) N${this.guardian.nivel} — armá el talismán, no hay escape.`);
        },

        /**
         * Comprometerse: abre el combate contra el guardián revelado. A partir de
         * acá no hay escape (el servidor rechaza 'escapar' contra un boss). El
         * combate se resuelve con la misma UI que cualquier pelea.
         */
        async pelearGuardian() {
            const g = this.guardian;
            if (!g) return;
            const datos = await this.pedir(`/jugar/${this.token}/guardian`, { indice: g.indice, x: g.x, y: g.y, pelear: true }, 'pelear');
            if (!datos) return;
            this.guardian = null;
            this.aplicarEstado(datos.estado);
            this.registrar(`⚔ ¡peleás contra ${this.combate.monstruo.nombre}!`);
        },

        // Retirarse del staging sin pelear: el guardián sigue custodiando la llave.
        // No se persiste nada (revelar no abre combate) — se puede volver a intentar.
        retirarseGuardian() {
            this.guardian = null;
            this.registrar('te retirás — el guardián sigue en su puesto.');
        },

        // ── Consola ────────────────────────────────────────────────────────
        registrar(txt) {
            this.consola.push(txt);
            if (this.consola.length > 200) this.consola.shift();
        },

        aplicarEstado(estado) {
            if (!estado) return;
            this.talisman = estado.talisman;
            this.combate = estado.combate;
            // Las llaves las manda el servidor (032). Redibujo solo si cambió alguna
            // (una puerta que se abre), no en cada acción de combate.
            if (estado.llaves && this.sincronizarLlaves(estado.llaves)) this.dibujar();
        },

        /**
         * Sincroniza llaves/puertas/salida desde los índices que trae el servidor
         * (032): cada llave i abre la puerta i; la que sobra (índice = cantidad de
         * puertas) abre la salida. Devuelve true si cambió algo, para redibujar solo
         * entonces.
         */
        sincronizarLlaves(llaves) {
            const nuevas = this.marcas.llaves.map((_, i) => llaves.includes(i));
            const cambio = nuevas.some((v, i) => v !== this.llavesRecogidas[i]);
            this.llavesRecogidas = nuevas;
            this.puertasAbiertas = this.marcas.puertas.map((_, i) => llaves.includes(i));
            this.salidaAbierta = llaves.includes(this.marcas.puertas.length);
            return cambio;
        },

        // ── Navegación persistida (puro cliente, localStorage) ─────────────
        /**
         * Persiste posición, pasos y rastro explorado en localStorage (por token)
         * tras cada paso, para que una recarga no te devuelva a la entrada. Es lo
         * único que no vive en el servidor (axioma 3); el talismán/HP/llaves ya
         * persisten allá. Un fallo de storage no rompe la partida: se sigue sin guardar.
         */
        guardarNavegacion() {
            try {
                localStorage.setItem(`${CLAVE_NAV}_${this.token}`, JSON.stringify({
                    mago: this.mago,
                    pasos: this.pasos,
                    visitadas: this.visitadas,
                    movimientos: this.movimientos,
                }));
            } catch { /* storage lleno o deshabilitado: la partida sigue igual */ }
        },

        /**
         * Restaura la navegación guardada de esta partida, si la hay. La llama init
         * antes de dibujar. Sin guardado (partida nueva) no hace nada y arrancás en
         * la entrada; un guardado corrupto se ignora.
         */
        restaurarNavegacion() {
            try {
                const crudo = localStorage.getItem(`${CLAVE_NAV}_${this.token}`);
                if (!crudo) return;
                const nav = JSON.parse(crudo);
                this.mago = nav.mago ?? this.mago;
                this.pasos = nav.pasos ?? 0;
                this.visitadas = nav.visitadas ?? {};
                this.movimientos = nav.movimientos ?? [];
            } catch { /* guardado corrupto: se arranca limpio */ }
        },

        // ── Configuración de display (puro cliente, global) ────────────────
        /** Lee las preferencias de display de localStorage. Global (no por token). */
        leerConfig() {
            try {
                const opaco = localStorage.getItem('wm_cfg_caminoOpaco');
                if (opaco !== null) this.caminoOpaco = opaco === '1';
                const colm = localStorage.getItem('wm_cfg_verColmenas');
                if (colm !== null) this.verColmenas = colm === '1';
            } catch { /* storage deshabilitado: se usan los defaults */ }
        },

        /** Persiste las preferencias de display y redibuja. La llaman los toggles del panel. */
        aplicarCfg() {
            try {
                localStorage.setItem('wm_cfg_caminoOpaco', this.caminoOpaco ? '1' : '0');
                localStorage.setItem('wm_cfg_verColmenas', this.verColmenas ? '1' : '0');
            } catch { /* nada */ }
            this.dibujar();
        },

        /**
         * Única puerta al servidor (DECISIONES.md 022/023). Marca `cargando` y
         * `accionActiva` (spinner del botón), hace el POST y devuelve el JSON, o
         * null si falló (lo registra en consola). El `finally` siempre apaga el
         * estado de carga, así un error de red no deja los botones colgados.
         */
        async pedir(url, cuerpo, clave) {
            this.cargando = true;
            this.accionActiva = clave;
            try {
                const respuesta = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(cuerpo),
                });
                const datos = await respuesta.json().catch(() => ({}));
                if (!respuesta.ok) {
                    this.registrar(`✗ ${datos.motivo ?? 'rechazado por el servidor'}`);
                    return null;
                }
                return datos;
            } catch {
                this.registrar('✗ sin respuesta del servidor');
                return null;
            } finally {
                this.cargando = false;
                this.accionActiva = null;
            }
        },

        // ── Combate: cada acción viaja al servidor, que la resuelve ────────
        async accionCombate(accion, gemaId = null) {
            // Snapshot del bicho ANTES de la llamada: al cerrar el combate el server
            // manda combate:null, y la pantalla de victoria/derrota necesita saber a
            // quién mataste / quién te mató.
            const bicho = this.combate?.monstruo;
            const datos = await this.pedir(`/jugar/${this.token}/combate`, { accion, gemaId }, `${accion}-${gemaId ?? ''}`);
            if (!datos) return;
            (datos.log || []).forEach((l) => this.registrar(l.txt));
            this.aplicarEstado(datos.estado);
            // Huida (030): el combate se cierra pero sin pantalla de resultado —
            // seguís caminando (la colmena queda viva).
            if (datos.resultado === 'huida') return;
            this.resultado = datos.resultado;
            this.drop = datos.drop;
            if (datos.resultado === 'victoria' || datos.resultado === 'derrota') this.bichoResuelto = bicho;
            if (datos.resultado === 'derrota') this.terminado = true;
            // Vencer al guardián de la salida (032) es ganar el laberinto: el server
            // ya marcó la corrida terminada; el cliente muestra la victoria final.
            if (datos.resultado === 'victoria' && bicho?.indice === INDICE_SALIDA) this.terminado = true;
        },

        atacar(id) { this.accionCombate('atacar', id); },
        bloquear(id) { this.accionCombate('bloquear', id); },
        escapar() { this.accionCombate('escapar'); },

        // ── Talismán: armar el loadout entre peleas ────────────────────────
        async accionTalisman(accion, gemaId = null, gemaId2 = null) {
            const datos = await this.pedir(`/jugar/${this.token}/talisman`, { accion, gemaId, gemaId2 }, `${accion}-${gemaId ?? ''}`);
            if (!datos) return;
            this.aplicarEstado(datos.estado);
        },

        // fieldear/guardar/vaciar cambian el SET fieldeado, así que reordenan las
        // fieldeadas al terminar (auto-orden en add/remove, además del select y el
        // ↻). El await asegura que el estado ya llegó antes de recalcular el orden.
        async fieldear(id) { await this.accionTalisman('fieldear', id); this.reordenarField(); },
        async guardar(id) { await this.accionTalisman('guardar', id); this.reordenarField(); },
        async vaciar() { await this.accionTalisman('vaciar'); this.reordenarField(); },
        desguazar(id) { this.accionTalisman('desguazar', id); },
        recargar(id) { this.accionTalisman('recargar', id); },
        subirNivel() { this.accionTalisman('subirNivel'); },
        curar() { this.accionTalisman('curar'); },

        // Recargar una gema al tope (028): espejo de Talisman::recargar. costoRecarga
        // es lo que cobra el servidor; puedeRecargar deshabilita el botón si ya está
        // llena o no alcanza la esencia (el servidor igual valida de verdad).
        costoRecarga(g) { return g.nivel * COSTO_RECARGA_POR_NIVEL; },
        puedeRecargar(g) {
            return this.talisman
                && g.carga < g.nivel * CARGA_POR_NIVEL
                && this.talisman.esencia >= this.costoRecarga(g);
        },

        // Fieldear valida ranura libre Y cap (025); el botón replica ambos topes.
        puedeFieldear(g) {
            return this.talisman
                && this.fieldeadas().length < RANURAS
                && this.capEnUso() + g.nivel <= this.talisman.cap;
        },

        // ── Fusión de gemas (025): dos del mismo tipo+nivel → una de nivel+1 ──
        // Es un flujo de dos toques: elegir la primera (elegirFusion) y después
        // tocar el "fusionar" de un par compatible. Solo entre gemas guardadas.
        elegirFusion(id) { this.fusionSel = this.fusionSel === id ? null : id; },

        fusionar(idB) {
            const idA = this.fusionSel;
            this.fusionSel = null;
            this.accionTalisman('fusionar', idA, idB);
        },

        // ¿g puede fusionarse con la seleccionada? mismo tipo y nivel, otra gema.
        fusionable(g) {
            if (this.fusionSel === null) return false;
            const a = this.inventario().find((x) => x.id === this.fusionSel);
            return !!a && a.id !== g.id && a.elemento === g.elemento && a.nivel === g.nivel;
        },

        // ¿Existe en el inventario alguna gema con la que g pueda fusionarse?
        tienePar(g) {
            return this.inventario().some((x) => x.id !== g.id && x.elemento === g.elemento && x.nivel === g.nivel);
        },

        // Estado del botón de fusión de g: 'elegir' (hay par, nada seleccionado),
        // 'seleccionada' (es la 1ª elegida), 'objetivo' (par válido de la elegida),
        // 'oculto' (no aplica). Lo usa la vista para pintar un solo botón.
        modoFusion(g) {
            if (this.fusionSel === null) return this.tienePar(g) ? 'elegir' : 'oculto';
            if (this.fusionSel === g.id) return 'seleccionada';
            return this.fusionable(g) ? 'objetivo' : 'oculto';
        },

        clicFusion(g) {
            if (this.modoFusion(g) === 'objetivo') {
                if (this.talisman.esencia < COSTO_FUSION) return; // sin esencia no se fusiona (027)
                this.fusionar(g.id);
            } else {
                this.elegirFusion(g.id);
            }
        },

        // Esencia pura que cuesta fusionar — espejo de Talisman::COSTO_FUSION (027).
        costoFusion() { return COSTO_FUSION; },

        // Esencia para subir de nivel — espejo de Talisman::costoNivel (024): nivel × COSTO_NIVEL.
        costoNivel() { return this.talisman ? this.talisman.nivel * 10 : 0; },

        // Cerrar el panel de botín y volver a caminar (la consola se conserva).
        seguir() {
            this.resultado = null;
            this.drop = null;
            this.bichoResuelto = null;
            this.registrar('— seguís camino —');
        },

        // ── Derivados de la hoja de personaje ──────────────────────────────
        fieldeadas() { return this.talisman ? this.talisman.gemas.filter((g) => g.fieldeada) : []; },
        inventario() { return this.talisman ? this.talisman.gemas.filter((g) => !g.fieldeada) : []; },

        // Comparadores de gemas — mayores siempre arriba; elemento por la rueda.
        // Compartido por el inventario y las fieldeadas del talismán.
        ordenarGemas(lista, clave) {
            const orden = {
                nivel: (a, b) => b.nivel - a.nivel || b.carga - a.carga,
                carga: (a, b) => b.carga - a.carga || b.nivel - a.nivel,
                elemento: (a, b) => ELEMENTOS.indexOf(a.elemento) - ELEMENTOS.indexOf(b.elemento) || b.nivel - a.nivel,
            };
            return [...lista].sort(orden[clave]);
        },

        // Inventario filtrado por elemento y ordenado. Es lo que se pinta; no muta el talismán.
        inventarioMostrado() {
            let g = this.inventario();
            if (this.filtroInv) g = g.filter((x) => x.elemento === this.filtroInv);
            return this.ordenarGemas(g, this.ordenInv);
        },

        // Recalcula el orden congelado de las fieldeadas. Se llama en tres puntos:
        // el onchange del select, el ↻, y al agregar/sacar una gema (fieldear/
        // guardar/vaciar) — NO en cada ataque. Si se reordenara en vivo, bajar la
        // carga de una gema la haría saltar de lugar en plena pelea, que es justo
        // lo molesto que se quiere evitar.
        reordenarField() {
            this.ordenFieldIds = this.ordenarGemas(this.fieldeadas(), this.ordenField).map((g) => g.id);
        },

        // Gemas fieldeadas en el orden congelado. Las que no estén en la lista
        // (recién equipadas) caen al final hasta el próximo reordenamiento.
        fieldeadasMostradas() {
            const pos = (id) => {
                const i = this.ordenFieldIds.indexOf(id);
                return i === -1 ? Infinity : i;
            };
            return [...this.fieldeadas()].sort((a, b) => pos(a.id) - pos(b.id));
        },

        // Drag-and-drop manual de las fieldeadas: mover una gema a la posición de
        // otra reescribe el orden congelado directamente (mismo mecanismo que el
        // select, pero a mano). Solo cliente, no viaja al servidor.
        iniciarArrastre(id) { this.arrastrando = id; },
        terminarArrastre() { this.arrastrando = null; },
        reordenarManual(idArrastrado, idDestino) {
            if (idArrastrado === null || idArrastrado === idDestino) return;
            const orden = this.fieldeadasMostradas().map((g) => g.id);
            const desde = orden.indexOf(idArrastrado);
            const hasta = orden.indexOf(idDestino);
            if (desde === -1 || hasta === -1) return;
            orden.splice(desde, 1);
            orden.splice(hasta, 0, idArrastrado);
            this.ordenFieldIds = orden;
        },

        // Cuántas gemas de cada elemento hay en el inventario (para los chips de filtro).
        conteoInv(elem) { return this.inventario().filter((g) => g.elemento === elem).length; },

        // Tope de carga de una gema: nivel × 6 (026). Mismo número que alimenta
        // la barra de abajo — se muestra también como texto (carga/tope).
        cargaMax(g) { return g.nivel * 6; },

        // Llenado de la barra de carga: carga relativa al tope de la gema
        // (nivel × 6, 026). Referencial — cuánto le queda de su máximo.
        anchoEsencia(g) { return Math.min(100, (g.carga / (g.nivel * 6 || 1)) * 100); },

        // Etiqueta del costo de atacar: carga si alcanza, o "X ⚡ +Y ♥" cuando el
        // faltante se paga con vida a la penalidad de la 021. Espejo de MazeCombate.
        costoAtaqueLabel(g) {
            const costo = g.nivel; // atacar cuesta carga = nivel
            if (g.carga >= costo) return `${costo} ⚡`;
            const vida = (costo - g.carga) * COMBATE.vidaPorEsencia;
            return g.carga > 0 ? `${g.carga} ⚡ +${vida} ♥` : `${vida} ♥`;
        },

        // Cuánta vida repone curar ahora: esencia pura → vida 1:1, sin pasarse del
        // tope. 0 si no hay esencia o la vida está llena. Espejo de Talisman::curar.
        cuantoCura() {
            if (!this.talisman) return 0;
            return Math.min(this.talisman.esencia, this.talisman.vidaMax - this.talisman.vida);
        },

        capEnUso() { return this.fieldeadas().reduce((s, g) => s + g.nivel, 0); },
        poderActual() { return this.fieldeadas().reduce((s, g) => s + (g.carga > 0 ? g.nivel : 0), 0); },

        // Ranuras vacías a mostrar en el talismán (RANURAS − fieldeadas): rellenan
        // hasta 6 filas para que la card mantenga siempre el mismo alto (ajuste visual).
        slotsVacios() { return Math.max(0, RANURAS - this.fieldeadas().length); },

        // ── Preview de combate (solo display; la resolución la hace el servidor) ──
        // Daño estimado de atacar con la gema g al monstruo actual: tirada media,
        // sin crítico. Espejo de CombatResolver::dano con variacion = 1, incluido
        // el acople gema→ataque de la hoja (ataqueMult, 024).
        danioEstimado(g) {
            if (!this.combate) return 0;
            const m = this.combate.monstruo;
            const poder = g.nivel * COMBATE.F;
            const mitig = COMBATE.K / (COMBATE.K + m.defensa);
            const mult = COMBATE[matchup(g.elemento, m.elemento)];
            const bono = 1 + (this.talisman ? this.talisman.ataqueMult : 0);
            return Math.max(1, Math.round(poder * mitig * mult * bono));
        },

        // Costo en carga de bloquear el golpe entrante con la gema g (029):
        // peso × elemento, determinista. Espejo de CombatResolver::costoBloqueo.
        costoBloqueoEstimado(g) {
            if (!this.combate || !this.combate.entrante) return 0;
            const factor = { ventaja: COMBATE.defVentaja, reves: COMBATE.defReves, neutral: COMBATE.defNeutral };
            return Math.max(1, Math.round(this.combate.entrante.peso * factor[matchup(g.elemento, this.combate.entrante.elemento)]));
        },

        // Etiqueta del bloqueo (029): la carga paga primero; lo que falte va a vida
        // ×3. "X ⚡" si alcanza, "X ⚡ +Y ♥" o "Y ♥" cuando cae a vida. Espejo de
        // MazeCombate::bloquear — la gema seca ya no rechaza, paga todo con vida.
        costoBloqueoLabel(g) {
            const costo = this.costoBloqueoEstimado(g);
            if (g.carga >= costo) return `${costo} ⚡`;
            const vida = (costo - g.carga) * COMBATE.vidaPorEsencia;
            return g.carga > 0 ? `${g.carga} ⚡ +${vida} ♥` : `${vida} ♥`;
        },

        // Relación de la gema con el monstruo actual, para teñir el botón de ataque.
        matchupAtaque(g) {
            return this.combate ? matchup(g.elemento, this.combate.monstruo.elemento) : 'neutral';
        },

        // Relación de la gema con el golpe entrante, para el botón de bloqueo.
        matchupBloqueo(g) {
            return this.combate && this.combate.entrante ? matchup(g.elemento, this.combate.entrante.elemento) : 'neutral';
        },
    };
}
