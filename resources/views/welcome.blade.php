<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Wizard's Maze — Playground</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1a1a1a;
        }

        .maze-grid {
            display: grid;
            border: 10px solid black;
        }

        .maze-cell {
            width: 25px;
            height: 25px;
            box-sizing: border-box;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: sans-serif;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="maze-grid" id="maze-grid"></div>

    <script>
        const FILAS = 30;
        const COLUMNAS = 30;
        const CAMINO_MINIMO = 400;
        const PUERTAS_EN = [100, 200];
        const BRAZO_MINIMO = 25;

        // Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1
        const DIRECCIONES = [
            { nombre: 'N', dx: 0, dy: -1, opuesta: 'S' },
            { nombre: 'E', dx: 1, dy: 0, opuesta: 'O' },
            { nombre: 'S', dx: 0, dy: 1, opuesta: 'N' },
            { nombre: 'O', dx: -1, dy: 0, opuesta: 'E' },
        ];

        function crearCelda() {
            return { N: 1, E: 1, S: 1, O: 1 };
        }

        function crearMatriz(filas, columnas) {
            const matriz = [];
            for (let y = 0; y < filas; y++) {
                const fila = [];
                for (let x = 0; x < columnas; x++) {
                    fila.push(crearCelda());
                }
                matriz.push(fila);
            }
            return matriz;
        }

        // PRNG — mulberry32, PROPUESTA en docs/PROTOCOLO_GENERADOR.md §2
        function crearPrng(seed) {
            let estado = seed >>> 0;
            return function next() {
                estado = (estado + 0x6D2B79F5) >>> 0;
                let t = estado;
                t = Math.imul(t ^ (t >>> 15), t | 1);
                t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
                return (t ^ (t >>> 14)) >>> 0;
            };
        }

        // randBelow — §2.1, abierto entre rechazo y mod directo; acá mod directo
        function randBelow(next, n) {
            return next() % n;
        }

        // Backtracking iterativo con pila — §4
        function generarLaberinto(seed, filas, columnas) {
            const next = crearPrng(seed);
            const matriz = crearMatriz(filas, columnas);
            const visitada = matriz.map((fila) => fila.map(() => false));
            const pila = [{ x: 0, y: 0 }];
            visitada[0][0] = true;

            while (pila.length > 0) {
                const actual = pila[pila.length - 1];
                const vecinos = DIRECCIONES
                    .map((d) => ({ ...d, x: actual.x + d.dx, y: actual.y + d.dy }))
                    .filter((v) => v.x >= 0 && v.x < columnas && v.y >= 0 && v.y < filas && !visitada[v.y][v.x]);

                if (vecinos.length === 0) {
                    pila.pop();
                    continue;
                }

                const elegido = vecinos[randBelow(next, vecinos.length)];
                matriz[actual.y][actual.x][elegido.nombre] = 0;
                matriz[elegido.y][elegido.x][elegido.opuesta] = 0;
                visitada[elegido.y][elegido.x] = true;
                pila.push({ x: elegido.x, y: elegido.y });
            }

            return matriz;
        }

        // BFS sobre el grafo del laberinto (paredes abiertas), no distancia euclidiana
        function calcularDistancias(matriz, inicioX, inicioY) {
            const filas = matriz.length;
            const columnas = matriz[0].length;
            const distancias = matriz.map((fila) => fila.map(() => -1));
            distancias[inicioY][inicioX] = 0;
            const cola = [{ x: inicioX, y: inicioY }];

            while (cola.length > 0) {
                const actual = cola.shift();
                DIRECCIONES.forEach((d) => {
                    const nx = actual.x + d.dx;
                    const ny = actual.y + d.dy;
                    if (nx < 0 || nx >= columnas || ny < 0 || ny >= filas) return;
                    if (matriz[actual.y][actual.x][d.nombre] === 1) return;
                    if (distancias[ny][nx] !== -1) return;
                    distancias[ny][nx] = distancias[actual.y][actual.x] + 1;
                    cola.push({ x: nx, y: ny });
                });
            }

            return distancias;
        }

        function encontrarCeldaMasLejana(distancias) {
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

        // Para una celda fuera del camino inicio→final, a qué distancia de inicio está
        // el punto del camino donde su rama se desprende ("attachment point"). En un
        // árbol, las distancias por los dos lados se descomponen así:
        //   dInicio = k + m
        //   dFinal  = (total - k) + m
        // Restando: dInicio - dFinal = 2k - total  →  k = (dInicio - dFinal + total) / 2
        function puntoDeDesprendimiento(dInicio, dFinal, total) {
            return (dInicio - dFinal + total) / 2;
        }

        // Cuánto se extiende una celda por fuera del camino inicio→final: su
        // distancia al punto de desprendimiento. En un árbol:
        //   dInicio = k + m, dFinal = (total - k) + m  →  m = (dInicio + dFinal - total) / 2
        // Da 0 para las celdas que están sobre el camino.
        function extensionDesdeCamino(dInicio, dFinal, total) {
            return (dInicio + dFinal - total) / 2;
        }

        // A qué segmento del camino pertenece un punto de desprendimiento k, dado
        // el orden de puertas (p.ej. puertas [100, 200] → segmentos [0,100), [100,200), [200,total]).
        function segmentoDe(k, puertasEn) {
            for (let i = 0; i < puertasEn.length; i++) {
                if (k < puertasEn[i]) return i;
            }
            return puertasEn.length;
        }

        function crearMuros(celda) {
            const grosor = '2px solid black';
            const vacio = 'none';
            return {
                borderTop: celda.N ? grosor : vacio,
                borderRight: celda.E ? grosor : vacio,
                borderBottom: celda.S ? grosor : vacio,
                borderLeft: celda.O ? grosor : vacio,
            };
        }

        function dibujarMatriz(matriz, etiquetas, enCamino, marcas) {
            const contenedor = document.getElementById('maze-grid');
            const filas = matriz.length;
            const columnas = matriz[0].length;
            contenedor.style.gridTemplateColumns = `repeat(${columnas}, 25px)`;
            contenedor.style.gridTemplateRows = `repeat(${filas}, 25px)`;

            matriz.forEach((fila, y) => {
                fila.forEach((celda, x) => {
                    const div = document.createElement('div');
                    div.className = 'maze-cell';
                    Object.assign(div.style, crearMuros(celda));
                    div.textContent = etiquetas[y][x];

                    const marca = marcas.find((m) => m.x === x && m.y === y);
                    if (marca) {
                        div.style.background = marca.color;
                    } else if (enCamino[y][x]) {
                        div.style.background = 'rgb(210, 210, 210)';
                    }

                    contenedor.appendChild(div);
                });
            });
        }

        // Control: se reintenta con un seed nuevo hasta que:
        // - el camino inicio→final mida al menos CAMINO_MINIMO
        // - existan las puertas de PUERTAS_EN sobre ese camino
        // - cada segmento entre puertas (y antes de la primera / después de la
        //   última) tenga un brazo de al menos BRAZO_MINIMO de extensión
        let seed, maze, distanciasInicio, final, distanciasFinal, puertas, llaves;

        do {
            seed = Math.floor(Math.random() * 2 ** 32) >>> 0;
            maze = generarLaberinto(seed, FILAS, COLUMNAS);
            distanciasInicio = calcularDistancias(maze, 0, 0);
            final = encontrarCeldaMasLejana(distanciasInicio);

            if (final.distancia < CAMINO_MINIMO) continue;

            distanciasFinal = calcularDistancias(maze, final.x, final.y);

            // Cada puerta es la celda del camino inicio→final a la distancia pedida.
            puertas = PUERTAS_EN.map(() => null);
            distanciasInicio.forEach((fila, y) => {
                fila.forEach((dInicio, x) => {
                    if (dInicio + distanciasFinal[y][x] !== final.distancia) return;
                    const idx = PUERTAS_EN.indexOf(dInicio);
                    if (idx !== -1) puertas[idx] = { x, y };
                });
            });

            // Una llave por segmento: la punta del brazo más largo que cuelga del
            // camino en ese tramo, elegida por su extensión (m), no por distancia
            // a ninguna puerta.
            llaves = new Array(PUERTAS_EN.length + 1).fill(null);
            distanciasInicio.forEach((fila, y) => {
                fila.forEach((dInicio, x) => {
                    const dFinal = distanciasFinal[y][x];
                    const m = extensionDesdeCamino(dInicio, dFinal, final.distancia);
                    if (m === 0) return;

                    const k = dInicio - m;
                    const seg = segmentoDe(k, PUERTAS_EN);

                    if (!llaves[seg] || m > llaves[seg].m) {
                        llaves[seg] = { x, y, m };
                    }
                });
            });
        } while (
            final.distancia < CAMINO_MINIMO
            || puertas.some((p) => !p)
            || llaves.some((l) => !l || l.m < BRAZO_MINIMO)
        );

        console.log('seed:', seed, '— camino inicio→final:', final.distancia);

        // enCamino marca el camino principal (bg gris); etiquetas muestra distancia
        // a inicio sobre el camino, y la extensión (m) en los brazos.
        const enCamino = distanciasInicio.map((fila, y) => fila.map(
            (dInicio, x) => dInicio + distanciasFinal[y][x] === final.distancia
        ));
        const etiquetas = distanciasInicio.map((fila, y) => fila.map((dInicio, x) => (
            enCamino[y][x] ? dInicio : extensionDesdeCamino(dInicio, distanciasFinal[y][x], final.distancia)
        )));

        const marcas = [
            { x: 0, y: 0, color: 'green' },
            { x: final.x, y: final.y, color: 'red' },
            ...puertas.map((p) => ({ x: p.x, y: p.y, color: 'yellow' })),
            ...llaves.map((l) => ({ x: l.x, y: l.y, color: 'orange' })),
        ];

        dibujarMatriz(maze, etiquetas, enCamino, marcas);
    </script>
</body>
</html>
