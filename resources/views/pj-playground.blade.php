<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wizard's Maze — Playground PJ</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            display: flex;
            justify-content: center;
            background: #1a1a1a;
            color: #eee;
            font-family: sans-serif;
        }

        .panel {
            display: flex;
            gap: 24px;
            padding: 24px;
            max-width: 1100px;
            flex-wrap: wrap;
        }

        .col {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .caja {
            background: #242424;
            border-radius: 8px;
            padding: 16px;
        }

        .stats { width: 300px; }
        .roster { width: 340px; }

        .log {
            width: 300px;
            font-family: monospace;
            font-size: 13px;
            max-height: 700px;
            overflow-y: auto;
        }

        h2 {
            margin-top: 0;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #999;
        }

        .barra-contenedor {
            background: #111;
            border-radius: 4px;
            height: 18px;
            overflow: hidden;
            margin: 4px 0 2px;
        }

        .barra { height: 100%; transition: width 0.15s; }
        .barra.vida { background: #e05555; }
        .barra.poder { background: #5588e0; }
        .barra.carga { background: #55b088; }

        .valor { font-size: 12px; color: #aaa; }

        .fila {
            display: flex;
            gap: 6px;
            align-items: center;
            margin: 4px 0;
        }

        .fila input[type=number] { width: 55px; }
        .fila select { flex: 1; }

        button {
            cursor: pointer;
            border: 1px solid #555;
            background: #333;
            color: #eee;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 12px;
        }

        button:hover { background: #3d3d3d; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }

        .derrota { color: #e05555; font-weight: bold; margin-top: 8px; }

        .gema-card {
            border: 1px solid #3a3a3a;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .gema-card.fieldeada { border-color: #5588e0; }
        .gema-card.muerta { opacity: 0.5; }

        .gema-card .titulo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .elemento {
            text-transform: capitalize;
            font-weight: bold;
        }

        .elemento.fuego { color: #e08a4a; }
        .elemento.agua { color: #4aa8e0; }
        .elemento.tierra { color: #a0824a; }
        .elemento.aire { color: #b8e04a; }

        .cap-uso { font-size: 12px; margin-top: 4px; }
        .cap-uso.excede { color: #e05555; }

        .acciones-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }

        ol { margin: 0; padding-left: 20px; }
        li { margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="panel">
        <div class="col">
            <div class="caja stats">
                <h2>Talismán</h2>
                <div class="fila">
                    <label>cap <input type="number" id="input-cap" value="12" min="1"></label>
                </div>
                <div class="valor">esencia: <span id="valor-esencia">0</span></div>
                <div class="fila">
                    <label>costo por punto <input type="number" id="input-costo-cap" value="5" min="1"></label>
                    <button id="btn-subir-cap">subir cap</button>
                </div>
                <div class="cap-uso" id="cap-uso"></div>

                <h2 style="margin-top: 16px">Poder (gemas fieldeadas)</h2>
                <div class="barra-contenedor"><div class="barra poder" id="barra-poder"></div></div>
                <div class="valor" id="valor-poder"></div>

                <h2 style="margin-top: 16px">Vida</h2>
                <div class="fila">
                    <label>máximo <input type="number" id="input-vida-max" value="100" min="1"></label>
                </div>
                <div class="barra-contenedor"><div class="barra vida" id="barra-vida"></div></div>
                <div class="valor" id="valor-vida"></div>
                <div class="derrota" id="msg-derrota" style="display: none">derrota — vida en 0</div>
            </div>

            <div class="caja stats">
                <h2>Acciones sin resolución (solo gastan carga — el combate real está a la derecha)</h2>
                <div class="fila">
                    <select id="select-gema-accion"></select>
                </div>
                <div class="fila">
                    <label>costo <input type="number" id="input-costo-accion" value="1" min="0"></label>
                </div>
                <div class="acciones-grid">
                    <button id="btn-atacar">atacar</button>
                    <button id="btn-defender">defender</button>
                    <button id="btn-mover">mover</button>
                    <button id="btn-esquivar">esquivar</button>
                </div>

                <div class="fila" style="margin-top: 8px">
                    <input type="number" id="input-drenaje" value="1" min="0">
                    <button id="btn-turno">pasar turno (drena vida si poder total = 0)</button>
                </div>
                <div class="fila">
                    <input type="number" id="input-dano" value="10" min="0">
                    <button id="btn-golpe">recibir golpe (daño directo a vida)</button>
                </div>
                <div class="fila">
                    <input type="number" id="input-cura" value="10" min="0">
                    <button id="btn-curar">curar vida</button>
                </div>
                <div class="fila">
                    <button id="btn-drop">obtener gema (drop)</button>
                    <button id="btn-reset">reset</button>
                </div>
            </div>
        </div>

        <div class="caja roster">
            <h2>Roster</h2>
            <div id="lista-roster"></div>
            <button id="btn-confirmar-fielding">confirmar fielding (cuesta un turno)</button>
        </div>

        <div class="col">
            <div class="caja stats">
                <h2>Reglas de combate (tuning — DECISIONES 012)</h2>
                <div class="fila">
                    <label>K <input type="number" id="r-K" value="50" min="1"></label>
                    <label>F <input type="number" id="r-F" value="3" min="1"></label>
                    <label>C <input type="number" id="r-C" value="2" min="1"></label>
                </div>
                <div class="fila">
                    <label>crítico % <input type="number" id="r-critProb" value="10" min="0" max="100"></label>
                    <label>× <input type="number" id="r-critMult" value="1.75" step="0.05" min="1"></label>
                </div>
                <div class="fila">
                    <label>ventaja <input type="number" id="r-ventaja" value="1.5" step="0.1"></label>
                    <label>revés <input type="number" id="r-reves" value="0.5" step="0.1"></label>
                </div>
                <div class="valor">defensa base del mago:
                    <input type="number" id="p-defensa" value="8" min="0" style="width:55px"></div>
            </div>

            <div class="caja stats">
                <h2>Combate — resolver real</h2>
                <div class="fila">
                    <label>monstruo vida <input type="number" id="m-vidaMax" value="40" min="1"></label>
                    <label>defensa <input type="number" id="m-defensa" value="30" min="0"></label>
                </div>
                <div class="fila"><label>elemento <select id="m-elemento"></select></label></div>
                <div class="barra-contenedor"><div class="barra vida" id="barra-monstruo"></div></div>
                <div class="valor" id="valor-monstruo"></div>
                <div class="fila" style="margin-top:8px">
                    <button id="btn-atacar-monstruo">atacar al monstruo (gema seleccionada arriba)</button>
                </div>

                <h2 style="margin-top:16px">Su golpe</h2>
                <div class="fila">
                    <label>elemento <select id="m-elemAtaque"></select></label>
                    <label>nivel <input type="number" id="m-nivelAtaque" value="5" min="1"></label>
                    <label>peso <input type="number" id="m-peso" value="2" min="1"></label>
                </div>
                <div class="fila"><button id="btn-monstruo-ataca">el monstruo ataca</button></div>
                <div id="golpe-entrante" style="display:none; margin-top:8px">
                    <div class="valor" id="golpe-entrante-txt"></div>
                    <div class="fila">
                        <button id="btn-comer">comer (a la vida)</button>
                        <select id="select-gema-bloqueo"></select>
                        <button id="btn-bloquear">bloquear</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="caja log">
            <h2>Log</h2>
            <ol id="lista-log"></ol>
        </div>
    </div>

    <script>
        // Playground descartable — mismo espíritu que welcome.blade.php (ver
        // docs/DECISIONES.md 008): no toca app/Game/, no tiene contraparte en
        // PHP, no tiene tests. Modela la economía de docs/DECISIONES.md 010 y
        // 011: talismán = loadout con cap, gemas con rol elemental que se
        // gastan sin recargar, roster/fielding con fricción, desguace en
        // esencia para subir el cap. Fuera de alcance a propósito: ventaja de
        // tipo elemental contra enemigos (sin monstruos diseñados todavía) y
        // el reset por muerte al loadout de entrada del maze.
        const ELEMENTOS = ['fuego', 'agua', 'tierra', 'aire'];

        let idGema = 0;
        function crearGema(elemento, nivel, fieldeada) {
            idGema += 1;
            return { id: idGema, elemento, nivel, cargaActual: nivel, fieldeada, pendienteFieldeo: fieldeada };
        }

        function loadoutInicial() {
            return ELEMENTOS.map((elemento) => crearGema(elemento, 3, true));
        }

        const estado = {
            cap: 12,
            esencia: 0,
            roster: loadoutInicial(),
            vidaMax: 100,
            vidaActual: 100,
            turno: 0,
            derrotado: false,
            monstruo: { vidaMax: 40, vidaActual: 40, defensa: 30, elemento: 'tierra' },
            golpeEntrante: null,
            log: [],
        };

        function agregarLog(texto) {
            estado.turno += 1;
            estado.log.push(`${estado.turno}. ${texto}`);
        }

        function verificarDerrota() {
            if (!estado.derrotado && estado.vidaActual <= 0) {
                estado.derrotado = true;
                agregarLog('derrota — vida en 0');
            }
        }

        function gemasFieldeadas() {
            return estado.roster.filter((g) => g.fieldeada);
        }

        function poderTotal() {
            return gemasFieldeadas().reduce((suma, g) => suma + g.cargaActual, 0);
        }

        function poderMax() {
            return gemasFieldeadas().reduce((suma, g) => suma + g.nivel, 0);
        }

        function pendienteSum() {
            return estado.roster.filter((g) => g.pendienteFieldeo).reduce((suma, g) => suma + g.nivel, 0);
        }

        function togglePendiente(id) {
            const gema = estado.roster.find((g) => g.id === id);
            if (!gema) return;
            const nuevoValor = !gema.pendienteFieldeo;
            const sumaSinEsta = pendienteSum() - (gema.pendienteFieldeo ? gema.nivel : 0);
            if (nuevoValor && sumaSinEsta + gema.nivel > estado.cap) {
                agregarLog(`no se puede fieldear ${gema.elemento} nivel ${gema.nivel} — excede el cap`);
                render();
                return;
            }
            gema.pendienteFieldeo = nuevoValor;
            render();
        }

        function confirmarFielding() {
            if (estado.derrotado) return;
            estado.roster.forEach((g) => { g.fieldeada = g.pendienteFieldeo; });
            agregarLog(`fielding actualizado — poder ${poderTotal()}/${poderMax()}`);
            render();
        }

        function desguazar(id) {
            if (estado.derrotado) return;
            const idx = estado.roster.findIndex((g) => g.id === id);
            if (idx === -1) return;
            const gema = estado.roster[idx];
            if (gema.fieldeada || gema.pendienteFieldeo) {
                agregarLog(`no se puede desguazar ${gema.elemento} — está fieldeada`);
                render();
                return;
            }
            estado.esencia += gema.nivel;
            estado.roster.splice(idx, 1);
            agregarLog(`desguace: gema ${gema.elemento} nivel ${gema.nivel} — +${gema.nivel} esencia`);
            render();
        }

        function subirCap() {
            if (estado.derrotado) return;
            const costo = Number(document.getElementById('input-costo-cap').value) || 1;
            if (estado.esencia < costo) {
                agregarLog(`esencia insuficiente para subir el cap (necesita ${costo})`);
                render();
                return;
            }
            estado.esencia -= costo;
            estado.cap += 1;
            agregarLog(`cap subido a ${estado.cap} (-${costo} esencia)`);
            render();
        }

        function dropGema() {
            if (estado.derrotado) return;
            const elemento = ELEMENTOS[Math.floor(Math.random() * ELEMENTOS.length)];
            const nivel = 1 + Math.floor(Math.random() * 5);
            estado.roster.push(crearGema(elemento, nivel, false));
            agregarLog(`drop: gema nueva — ${elemento} nivel ${nivel} (sumada al roster, sin fieldear)`);
            render();
        }

        function accion(tipo, gemaId, costo) {
            if (estado.derrotado) return;
            const gema = gemasFieldeadas().find((g) => g.id === gemaId);
            if (!gema) {
                agregarLog(`${tipo} — no hay gema fieldeada seleccionada`);
                render();
                return;
            }
            if (gema.cargaActual >= costo) {
                gema.cargaActual -= costo;
                agregarLog(`${tipo} con ${gema.elemento} (costo ${costo}) — carga ${gema.cargaActual}/${gema.nivel}`);
            } else {
                const excedente = costo - gema.cargaActual;
                gema.cargaActual = 0;
                estado.vidaActual = Math.max(0, estado.vidaActual - excedente);
                agregarLog(`${tipo} con ${gema.elemento} (costo ${costo}, carga insuficiente) — ${excedente} de excedente drenado de vida — vida ${estado.vidaActual}/${estado.vidaMax}`);
            }
            verificarDerrota();
            render();
        }

        function recibirGolpe(dano) {
            if (estado.derrotado) return;
            estado.vidaActual = Math.max(0, estado.vidaActual - dano);
            agregarLog(`golpe recibido (${dano}) — vida ${estado.vidaActual}/${estado.vidaMax}`);
            verificarDerrota();
            render();
        }

        function pasarTurno(drenaje) {
            if (estado.derrotado) return;
            if (poderTotal() === 0) {
                estado.vidaActual = Math.max(0, estado.vidaActual - drenaje);
                agregarLog(`turno con poder total 0 — drena ${drenaje} de vida — vida ${estado.vidaActual}/${estado.vidaMax}`);
            } else {
                agregarLog('turno — sin cambios');
            }
            verificarDerrota();
            render();
        }

        function curarVida(cantidad) {
            if (estado.derrotado) return;
            estado.vidaActual = Math.min(estado.vidaMax, estado.vidaActual + cantidad);
            agregarLog(`cura (${cantidad}) — vida ${estado.vidaActual}/${estado.vidaMax}`);
            render();
        }

        function resetear() {
            estado.cap = Number(document.getElementById('input-cap').value) || 12;
            estado.esencia = 0;
            estado.roster = loadoutInicial();
            estado.vidaMax = Number(document.getElementById('input-vida-max').value) || 100;
            estado.vidaActual = estado.vidaMax;
            estado.turno = 0;
            estado.derrotado = false;
            const mVida = Number(document.getElementById('m-vidaMax').value) || 40;
            estado.monstruo = {
                vidaMax: mVida,
                vidaActual: mVida,
                defensa: Number(document.getElementById('m-defensa').value) || 0,
                elemento: document.getElementById('m-elemento').value,
            };
            estado.golpeEntrante = null;
            estado.log = [];
            render();
        }

        function render() {
            document.getElementById('valor-esencia').textContent = estado.esencia;

            const usoPendiente = pendienteSum();
            const capEl = document.getElementById('cap-uso');
            capEl.textContent = `uso pendiente: ${usoPendiente} / ${estado.cap}`;
            capEl.className = `cap-uso ${usoPendiente > estado.cap ? 'excede' : ''}`;

            const pMax = poderMax() || 1;
            document.getElementById('barra-poder').style.width = `${(poderTotal() / pMax) * 100}%`;
            document.getElementById('valor-poder').textContent = `${poderTotal()} / ${poderMax()}`;

            document.getElementById('barra-vida').style.width = `${(estado.vidaActual / estado.vidaMax) * 100}%`;
            document.getElementById('valor-vida').textContent = `${estado.vidaActual} / ${estado.vidaMax}`;
            document.getElementById('msg-derrota').style.display = estado.derrotado ? 'block' : 'none';

            const lista = document.getElementById('lista-roster');
            lista.innerHTML = '';
            estado.roster.forEach((g) => {
                const div = document.createElement('div');
                const muerta = g.fieldeada && g.cargaActual === 0;
                div.className = `gema-card ${g.fieldeada ? 'fieldeada' : ''} ${muerta ? 'muerta' : ''}`;
                div.innerHTML = `
                    <div class="titulo">
                        <span class="elemento ${g.elemento}">${g.elemento}</span>
                        <span>nivel ${g.nivel}</span>
                    </div>
                    <div class="barra-contenedor"><div class="barra carga" style="width:${(g.cargaActual / g.nivel) * 100}%"></div></div>
                    <div class="valor">carga ${g.cargaActual}/${g.nivel}${muerta ? ' — muerta' : ''}</div>
                    <div class="fila">
                        <label><input type="checkbox" data-toggle="${g.id}" ${g.pendienteFieldeo ? 'checked' : ''}> fieldear</label>
                        <button data-desguazar="${g.id}" ${(g.fieldeada || g.pendienteFieldeo) ? 'disabled' : ''}>desguazar</button>
                    </div>
                `;
                lista.appendChild(div);
            });
            lista.querySelectorAll('[data-toggle]').forEach((chk) => {
                chk.addEventListener('change', () => togglePendiente(Number(chk.dataset.toggle)));
            });
            lista.querySelectorAll('[data-desguazar]').forEach((btn) => {
                btn.addEventListener('click', () => desguazar(Number(btn.dataset.desguazar)));
            });

            const select = document.getElementById('select-gema-accion');
            const seleccionPrevia = select.value;
            select.innerHTML = gemasFieldeadas().map((g) => (
                `<option value="${g.id}">${g.elemento} (carga ${g.cargaActual}/${g.nivel})</option>`
            )).join('');
            if (seleccionPrevia) select.value = seleccionPrevia;

            const listaLog = document.getElementById('lista-log');
            listaLog.innerHTML = estado.log.map((linea) => `<li>${linea}</li>`).join('');
            listaLog.parentElement.scrollTop = listaLog.parentElement.scrollHeight;

            renderCombate();

            document.querySelectorAll('.stats button, .roster button').forEach((btn) => {
                if (btn.id !== 'btn-reset') btn.disabled = estado.derrotado;
            });
        }

        document.getElementById('btn-confirmar-fielding').addEventListener('click', confirmarFielding);
        document.getElementById('btn-subir-cap').addEventListener('click', subirCap);
        document.getElementById('btn-drop').addEventListener('click', dropGema);
        document.getElementById('btn-reset').addEventListener('click', resetear);

        document.getElementById('btn-golpe').addEventListener('click', () => {
            recibirGolpe(Number(document.getElementById('input-dano').value) || 0);
        });
        document.getElementById('btn-turno').addEventListener('click', () => {
            pasarTurno(Number(document.getElementById('input-drenaje').value) || 0);
        });
        document.getElementById('btn-curar').addEventListener('click', () => {
            curarVida(Number(document.getElementById('input-cura').value) || 0);
        });

        function gemaSeleccionada() {
            return Number(document.getElementById('select-gema-accion').value);
        }
        function costoAccion() {
            return Number(document.getElementById('input-costo-accion').value) || 0;
        }

        document.getElementById('btn-atacar').addEventListener('click', () => accion('atacar', gemaSeleccionada(), costoAccion()));
        document.getElementById('btn-defender').addEventListener('click', () => accion('defender', gemaSeleccionada(), costoAccion()));
        document.getElementById('btn-mover').addEventListener('click', () => accion('mover', gemaSeleccionada(), costoAccion()));
        document.getElementById('btn-esquivar').addEventListener('click', () => accion('esquivar', gemaSeleccionada(), costoAccion()));

        document.getElementById('input-cap').addEventListener('change', (e) => {
            estado.cap = Number(e.target.value) || 1;
            render();
        });
        document.getElementById('input-vida-max').addEventListener('change', (e) => {
            const nuevoMax = Number(e.target.value) || 1;
            estado.vidaMax = nuevoMax;
            if (estado.vidaActual > nuevoMax) estado.vidaActual = nuevoMax;
            render();
        });

        // ── Combate contra el resolver real (POST /pj/combate) ──────────────
        // A diferencia del resto del playground (JS puro), esto NO calcula el
        // daño en el cliente: el servidor es la autoridad (axioma 4). El PHP
        // corre CombatResolver y devuelve el desglose; acá solo se aplica.
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let semillaCombate = 1;

        function reglasDesdeUI() {
            const num = (id) => Number(document.getElementById(id).value);
            return {
                K: num('r-K'), F: num('r-F'), C: num('r-C'),
                critProb: num('r-critProb'), critMult: num('r-critMult'),
                ventaja: num('r-ventaja'), reves: num('r-reves'),
            };
        }

        async function resolverCombate(cuerpo) {
            const resp = await fetch('/pj/combate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ ...cuerpo, reglas: reglasDesdeUI(), semilla: semillaCombate++ }),
            });
            return resp.json();
        }

        async function atacarMonstruo() {
            if (estado.derrotado) return;
            const gema = gemasFieldeadas().find((g) => g.id === gemaSeleccionada());
            if (!gema) { agregarLog('atacar — no hay gema fieldeada seleccionada'); render(); return; }
            if (estado.monstruo.vidaActual <= 0) { agregarLog('el monstruo ya está muerto'); render(); return; }

            const r = await resolverCombate({
                accion: 'golpe', nivel: gema.nivel, elementoAtacante: gema.elemento,
                defensa: estado.monstruo.defensa, elementoDefensor: estado.monstruo.elemento,
            });
            const critTxt = r.critico ? ' ¡CRÍTICO!' : '';

            if (gema.cargaActual >= r.costoEsencia) {
                gema.cargaActual -= r.costoEsencia;
                estado.monstruo.vidaActual = Math.max(0, estado.monstruo.vidaActual - r.dano);
                agregarLog(`atacás con ${gema.elemento} n${gema.nivel} — ${r.dano} de daño${critTxt} (${r.matchup}, -${r.costoEsencia} esencia) — monstruo ${estado.monstruo.vidaActual}/${estado.monstruo.vidaMax}`);
            } else if (gema.cargaActual === 0) {
                const costoVida = gema.nivel * (Number(document.getElementById('r-C').value) || 0);
                estado.vidaActual = Math.max(0, estado.vidaActual - costoVida);
                estado.monstruo.vidaActual = Math.max(0, estado.monstruo.vidaActual - r.dano);
                agregarLog(`ÚLTIMO SACUDÓN con ${gema.elemento} extinta — ${r.dano} de daño${critTxt}, pagás ${costoVida} de vida — vida ${estado.vidaActual}/${estado.vidaMax}`);
                verificarDerrota();
            } else {
                agregarLog(`${gema.elemento} tiene esencia ${gema.cargaActual}, castear cuesta ${r.costoEsencia} — no alcanza (gástala hasta 0 para el último sacudón)`);
            }
            render();
        }

        async function monstruoAtaca() {
            if (estado.derrotado) return;
            const r = await resolverCombate({
                accion: 'golpe',
                nivel: Number(document.getElementById('m-nivelAtaque').value) || 1,
                elementoAtacante: document.getElementById('m-elemAtaque').value,
                defensa: Number(document.getElementById('p-defensa').value) || 0,
                elementoDefensor: 'ninguno',
            });
            estado.golpeEntrante = {
                dano: r.dano, critico: r.critico,
                peso: Number(document.getElementById('m-peso').value) || 1,
                elemAtaque: document.getElementById('m-elemAtaque').value,
            };
            agregarLog(`el monstruo ataca con ${estado.golpeEntrante.elemAtaque} — ${r.dano} entrantes${r.critico ? ' ¡CRÍTICO!' : ''} (peso ${estado.golpeEntrante.peso}) — comé o bloqueá`);
            render();
        }

        function comerGolpe() {
            if (!estado.golpeEntrante || estado.derrotado) return;
            estado.vidaActual = Math.max(0, estado.vidaActual - estado.golpeEntrante.dano);
            agregarLog(`comés el golpe — ${estado.golpeEntrante.dano} a la vida — vida ${estado.vidaActual}/${estado.vidaMax}`);
            estado.golpeEntrante = null;
            verificarDerrota();
            render();
        }

        async function bloquearGolpe() {
            if (!estado.golpeEntrante || estado.derrotado) return;
            const gema = gemasFieldeadas().find((g) => g.id === Number(document.getElementById('select-gema-bloqueo').value));
            if (!gema) { agregarLog('bloquear — no hay gema con esencia'); return; }

            const r = await resolverCombate({
                accion: 'bloqueo', peso: estado.golpeEntrante.peso,
                elementoGema: gema.elemento, elementoAtaque: estado.golpeEntrante.elemAtaque,
            });

            if (gema.cargaActual >= r.costo) {
                gema.cargaActual -= r.costo;
                agregarLog(`bloqueás con ${gema.elemento} — golpe anulado, -${r.costo} esencia — esencia ${gema.cargaActual}/${gema.nivel}`);
                estado.golpeEntrante = null;
            } else {
                agregarLog(`${gema.elemento} tiene esencia ${gema.cargaActual}, bloquear cuesta ${r.costo} — no alcanza, elegí otra o comé`);
            }
            render();
        }

        function renderCombate() {
            const m = estado.monstruo;
            document.getElementById('barra-monstruo').style.width = `${(m.vidaActual / m.vidaMax) * 100}%`;
            document.getElementById('valor-monstruo').textContent = `${m.vidaActual} / ${m.vidaMax} — ${m.elemento}`;

            const ge = estado.golpeEntrante;
            document.getElementById('golpe-entrante').style.display = ge ? 'block' : 'none';
            if (ge) {
                document.getElementById('golpe-entrante-txt').textContent = `golpe entrante: ${ge.dano} (${ge.elemAtaque}, peso ${ge.peso})`;
                const sel = document.getElementById('select-gema-bloqueo');
                const previa = sel.value;
                sel.innerHTML = gemasFieldeadas().filter((g) => g.cargaActual > 0).map((g) => (
                    `<option value="${g.id}">${g.elemento} (esencia ${g.cargaActual}/${g.nivel})</option>`
                )).join('');
                if (previa) sel.value = previa;
            }
        }

        function poblarElementos(id, valorInicial) {
            const sel = document.getElementById(id);
            sel.innerHTML = ELEMENTOS.map((e) => `<option value="${e}">${e}</option>`).join('');
            if (valorInicial) sel.value = valorInicial;
        }
        poblarElementos('m-elemento', 'tierra');
        poblarElementos('m-elemAtaque', 'tierra');

        document.getElementById('m-elemento').addEventListener('change', (e) => { estado.monstruo.elemento = e.target.value; render(); });
        document.getElementById('m-defensa').addEventListener('change', (e) => { estado.monstruo.defensa = Number(e.target.value) || 0; });
        document.getElementById('m-vidaMax').addEventListener('change', (e) => {
            const v = Number(e.target.value) || 1;
            estado.monstruo.vidaMax = v;
            if (estado.monstruo.vidaActual > v) estado.monstruo.vidaActual = v;
            render();
        });
        document.getElementById('btn-atacar-monstruo').addEventListener('click', atacarMonstruo);
        document.getElementById('btn-monstruo-ataca').addEventListener('click', monstruoAtaca);
        document.getElementById('btn-comer').addEventListener('click', comerGolpe);
        document.getElementById('btn-bloquear').addEventListener('click', bloquearGolpe);

        render();
    </script>
</body>
</html>
