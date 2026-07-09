import { generarLaberinto } from './maze.js';

const CELDA = 20; // px por celda en el canvas

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
 */
export function game() {
    return {
        matriz: null,
        ancho: 0,
        alto: 0,
        mago: { x: 0, y: 0 },

        init() {
            const { seed, ancho, alto } = window.__MAZE__;
            this.matriz = generarLaberinto(seed, ancho, alto);
            this.ancho = ancho;
            this.alto = alto;
            this.$nextTick(() => this.dibujar());
        },

        dibujar() {
            const canvas = this.$refs.canvas;
            canvas.width = this.ancho * CELDA;
            canvas.height = this.alto * CELDA;

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
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

        mover(evento) {
            const direccion = TECLAS[evento.key];
            if (!direccion) return;
            evento.preventDefault();

            const celda = this.matriz[this.mago.y][this.mago.x];
            if (celda[direccion.nombre]) return; // pared cerrada

            this.mago.x += direccion.dx;
            this.mago.y += direccion.dy;
            this.dibujar();
        },
    };
}
