import { generarLaberinto } from './maze.js';
import { marcas as calcularMarcas } from './mapaBuilder.js';
import { campo as calcularCampo } from './encuentroBuilder.js';
import { crearPrng, randBelow } from './prng.js';

const CELDA = 20; // px por celda en el canvas

// Color del tinte de encuentro por elemento (docs/DECISIONES.md 016: color =
// elemento, alpha = probabilidad). Las celdas de solo ambiente (sin elemento)
// no se tiñen — el peligro parejo no se pinta, se sospecha.
const COLOR_ELEMENTO = {
    fuego: '220, 60, 40',
    agua: '40, 120, 220',
    tierra: '150, 110, 60',
    aire: '200, 200, 230',
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
 * Cada llave abre la puerta de su mismo índice (llave 0 → puerta 0, etc.);
 * la última llave, la que sobra, abre la salida. La salida arranca cerrada
 * (no se puede entrar) y se abre al recoger esa llave.
 */
export function game() {
    return {
        token: null,
        seed: null,
        matriz: null,
        marcas: null,
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

        // Estado de personaje y combate — verdad del servidor (axioma 4). El
        // cliente solo renderiza lo que recibe y manda acciones.
        talisman: null,
        combate: null,
        resultado: null, // 'victoria' | 'derrota' | null
        drop: null,
        consola: [],
        // Una llamada al servidor en vuelo: bloquea acciones concurrentes y prende
        // el spinner. accionActiva marca CUÁL botón gira (DECISIONES.md 023).
        cargando: false,
        accionActiva: null,

        // Filtro y orden del inventario, y orden de las fieldeadas (puro cliente,
        // no viaja al servidor). Mayores siempre arriba.
        filtroInv: null, // null = todos, o 'fuego'|'agua'|'tierra'|'aire'
        ordenInv: 'nivel', // 'nivel' | 'esencia' | 'elemento'
        ordenField: 'nivel', // idem para el talismán (fieldeadas): 'nivel' | 'esencia' | 'elemento'
        ordenFieldIds: [], // orden CONGELADO de las fieldeadas (ids); solo se recalcula al cambiar
                           // el select o el set fieldeado — nunca por un ataque que baje esencia

        init() {
            const { seed, ancho, alto, token, estado } = window.__MAZE__;
            this.token = token;
            this.seed = seed;
            this.talisman = estado.talisman;
            this.combate = estado.combate;
            this.registrar('entrás al laberinto. WASD o flechas para caminar.');
            this.matriz = generarLaberinto(seed, ancho, alto);
            this.marcas = calcularMarcas(this.matriz);
            this.campo = calcularCampo(seed, ancho, alto);
            this.ancho = ancho;
            this.alto = alto;
            this.alturaPx = alto * CELDA;
            this.puertasAbiertas = this.marcas.puertas.map(() => false);
            this.llavesRecogidas = this.marcas.llaves.map(() => false);
            this.visitadas[`${this.mago.x},${this.mago.y}`] = true;
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
                fila.forEach((celda, x) => {
                    if (!celda.elem) return;
                    const alpha = Math.min(0.6, celda.prob / 20);
                    ctx.fillStyle = `rgba(${COLOR_ELEMENTO[celda.elem]}, ${alpha})`;
                    ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
                });
            });
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
                    ctx.fillStyle = this.visitadas[`${x},${y}`]
                        ? 'rgba(120, 120, 135, 0.55)' // rastro: velo gris, el laberinto se intuye
                        : '#0a0a0d'; // no explorado: negro
                    ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
                }
            }
        },

        dibujarMarcas(ctx) {
            const pintar = ({ x, y }, color) => {
                ctx.fillStyle = color;
                ctx.fillRect(x * CELDA, y * CELDA, CELDA, CELDA);
            };

            pintar(this.marcas.entrada, COLOR_MARCA.entrada);
            pintar(this.marcas.salida, this.salidaAbierta ? COLOR_MARCA.salidaAbierta : COLOR_MARCA.salidaCerrada);

            this.marcas.puertas.forEach((p, i) => {
                pintar(p, this.puertasAbiertas[i] ? COLOR_MARCA.puertaAbierta : COLOR_MARCA.puertaCerrada);
            });

            this.marcas.llaves.forEach((l, i) => {
                if (!this.llavesRecogidas[i]) pintar(l, COLOR_MARCA.llave);
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

        mover(evento) {
            // No se camina con un combate abierto, con un drop sin resolver, ni
            // con una llamada al servidor en vuelo (abrir combate): la pelea frena
            // la marcha. El movimiento en sí ya no espera al servidor (022).
            if (this.terminado || this.combate || this.resultado || this.cargando) return;

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

            const idxLlave = this.buscarLlave(nx, ny);
            const llaveSinRecoger = idxLlave !== -1 && !this.llavesRecogidas[idxLlave];

            if (llaveSinRecoger) {
                // Guardián telegrafiado de la llave (docs/DECISIONES.md 011):
                // pelea obligatoria. Sigue siendo client-side por ahora — las
                // llaves todavía no viven en el servidor.
                this.movimientos.push({ dir: 'guardian', x: nx, y: ny });
                this.llavesRecogidas[idxLlave] = true;
                if (idxLlave < this.puertasAbiertas.length) {
                    this.puertasAbiertas[idxLlave] = true; // llave de puerta
                } else {
                    this.salidaAbierta = true; // llave que sobra: abre la salida
                }
            }

            // Caminar y tirar el encuentro son locales ahora (022): la salida es
            // terminal; si no, el cliente tira su propio dado y solo llama al
            // servidor si saltó un bicho.
            this.dibujar();
            if (this.esSalida(nx, ny)) {
                this.finalizar();
            } else if (this.tirarEncuentro(nx, ny)) {
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

        // ── Consola ────────────────────────────────────────────────────────
        registrar(txt) {
            this.consola.push(txt);
            if (this.consola.length > 200) this.consola.shift();
        },

        aplicarEstado(estado) {
            if (!estado) return;
            this.talisman = estado.talisman;
            this.combate = estado.combate;
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
            const datos = await this.pedir(`/jugar/${this.token}/combate`, { accion, gemaId }, `${accion}-${gemaId ?? ''}`);
            if (!datos) return;
            (datos.log || []).forEach((l) => this.registrar(l.txt));
            this.aplicarEstado(datos.estado);
            this.resultado = datos.resultado;
            this.drop = datos.drop;
            if (datos.resultado === 'derrota') this.terminado = true;
        },

        atacar(id) { this.accionCombate('atacar', id); },
        comer() { this.accionCombate('comer'); },
        bloquear(id) { this.accionCombate('bloquear', id); },

        // ── Talismán: armar el loadout entre peleas ────────────────────────
        async accionTalisman(accion, gemaId = null) {
            const datos = await this.pedir(`/jugar/${this.token}/talisman`, { accion, gemaId }, `${accion}-${gemaId ?? ''}`);
            if (!datos) return;
            this.aplicarEstado(datos.estado);
        },

        fieldear(id) { this.accionTalisman('fieldear', id); },
        guardar(id) { this.accionTalisman('guardar', id); },
        desguazar(id) { this.accionTalisman('desguazar', id); },
        subirNivel() { this.accionTalisman('subirNivel'); },
        curar() { this.accionTalisman('curar'); },
        puedeFieldear(g) { return this.talisman && this.capEnUso() + g.nivel <= this.talisman.cap; },

        // Esencia para subir de nivel — espejo de Talisman::costoNivel (024): nivel × COSTO_NIVEL.
        costoNivel() { return this.talisman ? this.talisman.nivel * 10 : 0; },

        // Cerrar el panel de botín y volver a caminar (la consola se conserva).
        seguir() {
            this.resultado = null;
            this.drop = null;
            this.registrar('— seguís camino —');
        },

        // ── Derivados de la hoja de personaje ──────────────────────────────
        fieldeadas() { return this.talisman ? this.talisman.gemas.filter((g) => g.fieldeada) : []; },
        inventario() { return this.talisman ? this.talisman.gemas.filter((g) => !g.fieldeada) : []; },

        // Comparadores de gemas — mayores siempre arriba; elemento por la rueda.
        // Compartido por el inventario y las fieldeadas del talismán.
        ordenarGemas(lista, clave) {
            const orden = {
                nivel: (a, b) => b.nivel - a.nivel || b.esencia - a.esencia,
                esencia: (a, b) => b.esencia - a.esencia || b.nivel - a.nivel,
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

        // Recalcula el orden congelado de las fieldeadas. Se llama SOLO desde el
        // onchange del select: ni al equipar/guardar ni al atacar. Si se reordenara
        // en vivo, bajar la esencia de una gema la haría saltar de lugar en plena
        // pelea, que es justo lo molesto que se quiere evitar.
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

        // Cuántas gemas de cada elemento hay en el inventario (para los chips de filtro).
        conteoInv(elem) { return this.inventario().filter((g) => g.elemento === elem).length; },

        // Golpes que aún banca una gema: cada ataque cuesta su nivel en esencia.
        // Es lo que de verdad importa mirar (no la barra): cuántos casteos quedan.
        golpesRestantes(g) { return Math.floor(g.esencia / (g.nivel || 1)); },

        // Llenado de la barra de esencia: esencia relativa a una carga llena de
        // drop (nivel × 6). Referencial — cuánto le queda de su máximo típico.
        anchoEsencia(g) { return Math.min(100, (g.esencia / (g.nivel * 6 || 1)) * 100); },

        // Etiqueta del costo de atacar: esencia si alcanza, o "X es +Y vida" cuando
        // el faltante se paga con vida a la penalidad de la 021. Espejo de MazeCombate.
        costoAtaqueLabel(g) {
            const costo = g.nivel; // atacar cuesta esencia = nivel
            if (g.esencia >= costo) return `−${costo} es.`;
            const vida = (costo - g.esencia) * COMBATE.vidaPorEsencia;
            return g.esencia > 0 ? `−${g.esencia} es +${vida} vida` : `−${vida} vida`;
        },

        // Cuánta vida repone curar ahora: esencia pura → vida 1:1, sin pasarse del
        // tope. 0 si no hay esencia o la vida está llena. Espejo de Talisman::curar.
        cuantoCura() {
            if (!this.talisman) return 0;
            return Math.min(this.talisman.esencia, this.talisman.vidaMax - this.talisman.vida);
        },

        capEnUso() { return this.fieldeadas().reduce((s, g) => s + g.nivel, 0); },
        poderActual() { return this.fieldeadas().reduce((s, g) => s + (g.esencia > 0 ? g.nivel : 0), 0); },

        // ── Preview de combate (solo display; la resolución la hace el servidor) ──
        // Daño estimado de atacar con la gema g al monstruo actual: tirada media,
        // sin crítico. Espejo de CombatResolver::dano con variacion = 1.
        danioEstimado(g) {
            if (!this.combate) return 0;
            const m = this.combate.monstruo;
            const poder = g.nivel * COMBATE.F;
            const mitig = COMBATE.K / (COMBATE.K + m.defensa);
            const mult = COMBATE[matchup(g.elemento, m.elemento)];
            return Math.max(1, Math.round(poder * mitig * mult));
        },

        // Costo en esencia de bloquear el golpe entrante con la gema g. Espejo de
        // CombatResolver::costoBloqueo (el azar del bloqueo es chico; esto es la media).
        costoBloqueoEstimado(g) {
            if (!this.combate || !this.combate.entrante) return 0;
            const factor = { ventaja: COMBATE.defVentaja, reves: COMBATE.defReves, neutral: COMBATE.defNeutral };
            return Math.max(1, Math.round(this.combate.entrante.peso * factor[matchup(g.elemento, this.combate.entrante.elemento)]));
        },

        // Relación de la gema con el monstruo actual, para teñir el botón de ataque.
        matchupAtaque(g) {
            return this.combate ? matchup(g.elemento, this.combate.monstruo.elemento) : 'neutral';
        },

        // Relación de la gema con el golpe entrante, para el botón de bloqueo.
        matchupBloqueo(g) {
            return this.combate && this.combate.entrante ? matchup(g.elemento, this.combate.entrante.elemento) : 'neutral';
        },

        finalizar() {
            this.terminado = true;
            this.movimientos.push({ dir: 'finalizado', x: this.mago.x, y: this.mago.y });
            this.enviarSalida();
        },

        async enviarSalida() {
            await this.pedir(`/jugar/${this.token}/salir`, { x: this.mago.x, y: this.mago.y }, 'salir');
        },
    };
}
