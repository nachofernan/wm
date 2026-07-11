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
        prngEncuentros: null,
        matriz: null,
        marcas: null,
        campo: null,
        ancho: 0,
        alto: 0,
        alturaPx: 0,
        mago: { x: 0, y: 0 },
        puertasAbiertas: [],
        llavesRecogidas: [],
        salidaAbierta: false,
        terminado: false,
        movimientos: [],

        init() {
            const { seed, ancho, alto, token } = window.__MAZE__;
            this.token = token;
            this.seed = seed;
            // Stream separado del PRNG del maze (decorrelado con XOR) para no
            // pisar la secuencia que usa generarLaberinto.
            this.prngEncuentros = crearPrng(seed ^ 0x9E3779B9);
            this.matriz = generarLaberinto(seed, ancho, alto);
            this.marcas = calcularMarcas(this.matriz);
            this.campo = calcularCampo(seed, ancho, alto);
            this.ancho = ancho;
            this.alto = alto;
            this.alturaPx = alto * CELDA;
            this.puertasAbiertas = this.marcas.puertas.map(() => false);
            this.llavesRecogidas = this.marcas.llaves.map(() => false);
            this.$nextTick(() => this.dibujar());
        },

        dibujar() {
            const canvas = this.$refs.canvas;
            canvas.width = this.ancho * CELDA;
            canvas.height = this.alto * CELDA;

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            this.dibujarCampo(ctx);
            this.dibujarMarcas(ctx);

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
            if (this.terminado) return;

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
            this.movimientos.push({ dir: direccion.nombre, x: nx, y: ny });

            const idxLlave = this.buscarLlave(nx, ny);
            const llaveSinRecoger = idxLlave !== -1 && !this.llavesRecogidas[idxLlave];

            // Disparo de encuentro por celda (docs/DECISIONES.md 016): la
            // probabilidad sale del campo (sesgo público, paritario), pero la
            // tirada acá es un ATAJO de playground — en el juego real el dado es
            // secreto del servidor y viaja en el ping por paso. Sigue siendo
            // obligatorio al pisar una llave sin recoger (guardián telegrafiado,
            // docs/DECISIONES.md 011). Sin resolución de combate todavía: solo
            // se registra el encuentro.
            const prob = this.campo.celdas[ny][nx].prob;
            if (llaveSinRecoger || randBelow(this.prngEncuentros, 100) < prob) {
                this.movimientos.push({ dir: 'encuentro', x: nx, y: ny });
            }

            if (llaveSinRecoger) {
                this.llavesRecogidas[idxLlave] = true;
                if (idxLlave < this.puertasAbiertas.length) {
                    this.puertasAbiertas[idxLlave] = true; // llave de puerta
                } else {
                    this.salidaAbierta = true; // llave que sobra: abre la salida
                }
            }

            if (this.esSalida(nx, ny)) {
                this.finalizar();
            }

            this.dibujar();
        },

        finalizar() {
            this.terminado = true;
            this.movimientos.push({ dir: 'finalizado', x: this.mago.x, y: this.mago.y });
            this.enviarSalida();
        },

        async enviarSalida() {
            await fetch(`/jugar/${this.token}/salir`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ x: this.mago.x, y: this.mago.y }),
            });
        },
    };
}
