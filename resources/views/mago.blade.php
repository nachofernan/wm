<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wizard's Maze — Hoja del mago</title>
    <style>
        :root {
            --bg: #16151a;
            --caja: #201f27;
            --caja2: #2a2933;
            --linea: #3a3945;
            --texto: #e8e6ef;
            --tenue: #918da0;
            --fuego: #e08a4a;
            --agua: #4aa8e0;
            --tierra: #b98a52;
            --aire: #b8e04a;
            --vida: #e0554f;
            --poder: #6f8fe0;
            --esencia: #55b088;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            background: var(--bg);
            color: var(--texto);
            font-family: system-ui, sans-serif;
        }

        .tablero {
            display: grid;
            grid-template-columns: 320px 1fr 300px;
            gap: 18px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        @media (max-width: 1000px) { .tablero { grid-template-columns: 1fr; } }

        .caja {
            background: var(--caja);
            border: 1px solid var(--linea);
            border-radius: 10px;
            padding: 16px;
        }

        .col { display: flex; flex-direction: column; gap: 16px; }

        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 {
            margin: 0 0 10px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--tenue);
        }

        .barra-cont {
            background: #100f14;
            border-radius: 5px;
            height: 16px;
            overflow: hidden;
            margin: 4px 0 2px;
        }
        .barra { height: 100%; transition: width 0.2s; }
        .barra.vida { background: var(--vida); }
        .barra.poder { background: var(--poder); }
        .barra.esencia { background: var(--esencia); }
        .valor { font-size: 12px; color: var(--tenue); }

        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            margin-top: 8px;
        }
        .stat { font-size: 13px; }
        .stat b { font-size: 18px; display: block; color: var(--texto); }

        .gema {
            border: 1px solid var(--linea);
            border-left: 3px solid var(--linea);
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 8px;
            background: var(--caja2);
        }
        .gema.fuego { border-left-color: var(--fuego); }
        .gema.agua { border-left-color: var(--agua); }
        .gema.tierra { border-left-color: var(--tierra); }
        .gema.aire { border-left-color: var(--aire); }
        .gema.inerte { opacity: 0.55; }
        .gema.nueva { box-shadow: 0 0 0 2px #d8c24a55; }

        .gema .cab {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 4px;
        }
        .gema .nom { font-weight: 600; text-transform: capitalize; }
        .gema.fuego .nom { color: var(--fuego); }
        .gema.agua .nom { color: var(--agua); }
        .gema.tierra .nom { color: var(--tierra); }
        .gema.aire .nom { color: var(--aire); }
        .gema .lvl { font-size: 12px; color: var(--tenue); }

        .acciones { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }

        button {
            cursor: pointer;
            border: 1px solid var(--linea);
            background: var(--caja2);
            color: var(--texto);
            border-radius: 6px;
            padding: 7px 12px;
            font-size: 13px;
            font-family: inherit;
        }
        button:hover:not(:disabled) { border-color: var(--tenue); }
        button:disabled { opacity: 0.35; cursor: not-allowed; }
        button.primario { background: #33507a; border-color: #45688f; }
        button.ataque { background: #4a2f2f; border-color: #6f4444; }
        button.ancho { width: 100%; }

        .rivales { display: flex; flex-direction: column; gap: 8px; }
        .rival {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid var(--linea);
            border-radius: 8px;
            background: var(--caja2);
            cursor: pointer;
        }
        .rival:hover { border-color: var(--tenue); }
        .rival .desc { font-size: 12px; color: var(--tenue); }

        .telegrafia {
            border: 1px dashed #7a6f44;
            background: #26230f;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .entrante {
            border: 1px solid var(--vida);
            background: #2a1414;
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
        }

        .log { font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.5; }
        .log ol { margin: 0; padding-left: 18px; max-height: 340px; overflow-y: auto; }
        .log .crit { color: #e0c04a; }

        .final { text-align: center; padding: 20px 0; }
        .final .titulo { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .final.victoria .titulo { color: var(--esencia); }
        .final.derrota .titulo { color: var(--vida); }

        .drop {
            border: 1px solid #d8c24a;
            background: #29260f;
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
        }
        .vacio { color: var(--tenue); font-size: 13px; font-style: italic; }
    </style>
</head>
<body>
    <div class="tablero">
        <!-- ── Hoja del mago ─────────────────────────────────────────── -->
        <div class="col">
            <div class="caja">
                <h1>El mago</h1>
                <h2 style="margin-top:12px">Vida</h2>
                <div class="barra-cont"><div class="barra vida" id="barra-vida"></div></div>
                <div class="valor" id="txt-vida"></div>

                <h2 style="margin-top:16px">Talismán — poder</h2>
                <div class="barra-cont"><div class="barra poder" id="barra-poder"></div></div>
                <div class="valor" id="txt-poder"></div>

                <div class="stat-grid">
                    <div class="stat">cap<b id="txt-cap"></b><span class="valor">en uso <span id="txt-capuso"></span></span></div>
                    <div class="stat">esencia<b id="txt-esencia"></b>
                        <button id="btn-subir-cap" style="margin-top:4px;padding:4px 8px;font-size:11px">+1 cap (<span id="txt-costo-cap"></span> es.)</button>
                    </div>
                </div>
            </div>

            <div class="caja">
                <h2>Gemas fieldeadas</h2>
                <div id="fieldeadas"></div>
            </div>
        </div>

        <!-- ── Combate ───────────────────────────────────────────────── -->
        <div class="caja" id="zona-combate"><!-- render dinámico --></div>

        <!-- ── Inventario + progreso ─────────────────────────────────── -->
        <div class="col">
            <div class="caja">
                <h2>Inventario</h2>
                <div id="inventario"></div>
            </div>

            <div class="caja">
                <h2>Progreso</h2>
                <div class="stat-grid">
                    <div class="stat">bichos caídos<b id="p-bichos">0</b></div>
                    <div class="stat">gemas juntadas<b id="p-gemas">0</b></div>
                    <div class="stat">poder máx<b id="p-podermax">0</b></div>
                    <div class="stat">esencia total<b id="p-esencia">0</b></div>
                </div>
            </div>

            <div class="caja log">
                <h2>Bitácora</h2>
                <ol id="log"></ol>
            </div>
        </div>
    </div>

    <script>
        // Playground descartable — hoja del mago jugable. Une el combate real del
        // /pelea (POST /pj/combate, autoridad en el servidor, axioma 4) con la
        // economía del talismán de docs/DECISIONES.md 010 y 011: peleas sueltas,
        // drop de gema al ganar, y el loop guardar / fieldear / desguazar→esencia
        // →cap. No calcula daño en el cliente; no toca app/Game/; no tiene tests
        // (igual que welcome y pelea). La rueda elemental y los números finos son
        // los del resolver por defecto: acá se juega, no se tunea.
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const ELEMENTOS = ['fuego', 'agua', 'tierra', 'aire'];
        const COSTO_CAP = 5; // esencia por +1 de cap (valor de playground, ver 011)
        const C = 2;         // costo del último sacudón = nivel × C (DEFAULTS del resolver)

        // Presets de rival, tomados del /pelea. vida/def/elemento + su golpe.
        const RIVALES = [
            { id: 'golem',   nombre: 'Gólem de tierra', elemento: 'tierra', vida: 70, defensa: 30, nivelAtaque: 6, peso: 2, dificultad: 3 },
            { id: 'igneo',   nombre: 'Espectro ígneo',  elemento: 'fuego',  vida: 55, defensa: 18, nivelAtaque: 7, peso: 3, dificultad: 3 },
            { id: 'silfide', nombre: 'Sílfide del aire', elemento: 'aire',  vida: 45, defensa: 12, nivelAtaque: 5, peso: 1, dificultad: 2 },
        ];

        let idGema = 0;
        function crearGema(elemento, nivel, esencia) {
            idGema += 1;
            return { id: idGema, elemento, nivel, esencia, fieldeada: false };
        }

        // Mago fijo inicial (DECISIONES 010/011): Fuego n5, Agua n4, Tierra n3.
        // La suma de niveles fieldeados (12) llena justo el cap inicial.
        function magoInicial() {
            const g = [crearGema('fuego', 5, 20), crearGema('agua', 4, 15), crearGema('tierra', 3, 20)];
            g.forEach((x) => { x.fieldeada = true; });
            return g;
        }

        const S = {
            fase: 'preparacion', // 'preparacion' | 'tuTurno' | 'defensa' | 'fin'
            vidaMax: 40,
            vida: 40,
            defensa: 8,
            cap: 12,
            esencia: 0,
            gemas: magoInicial(),
            rival: null,
            entrante: null,
            dropPendiente: null,
            semilla: 1,
            resultado: null,   // 'victoria' | 'derrota'
            bichosCaidos: 0,
            gemasJuntadas: 0,
            log: [],
        };

        // ── Derivados del talismán ─────────────────────────────────────
        const fieldeadas = () => S.gemas.filter((g) => g.fieldeada);
        const inventario = () => S.gemas.filter((g) => !g.fieldeada);
        const capEnUso = () => fieldeadas().reduce((s, g) => s + g.nivel, 0);
        // Poder = niveles de gemas fieldeadas con esencia > 0 (una gema inerte no aporta).
        const poderActual = () => fieldeadas().reduce((s, g) => s + (g.esencia > 0 ? g.nivel : 0), 0);
        const poderMax = () => capEnUso();

        function log(txt, crit = false) {
            S.log.push({ txt, crit });
            if (S.log.length > 60) S.log.shift();
        }

        // ── Servidor: resuelve golpe / bloqueo (nunca el cliente) ──────
        async function resolver(cuerpo) {
            const resp = await fetch('/pj/combate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ ...cuerpo, semilla: S.semilla++ }),
            });
            return resp.json();
        }

        // ── Preparación: economía del talismán ─────────────────────────
        function fieldear(id) {
            const g = S.gemas.find((x) => x.id === id);
            if (!g || g.fieldeada) return;
            if (capEnUso() + g.nivel > S.cap) {
                log(`no entra: ${g.elemento} n${g.nivel} excede el cap (${capEnUso()}/${S.cap})`);
                return render();
            }
            g.fieldeada = true;
            log(`fieldeás ${g.elemento} n${g.nivel} — cap ${capEnUso()}/${S.cap}`);
            render();
        }

        function guardar(id) {
            const g = S.gemas.find((x) => x.id === id);
            if (!g || !g.fieldeada) return;
            g.fieldeada = false;
            log(`sacás ${g.elemento} n${g.nivel} del talismán`);
            render();
        }

        function desguazar(id) {
            const g = S.gemas.find((x) => x.id === id);
            if (!g || g.fieldeada) return;
            S.esencia += g.nivel;
            S.gemas = S.gemas.filter((x) => x.id !== id);
            if (S.dropPendiente === id) S.dropPendiente = null;
            log(`desguazás ${g.elemento} n${g.nivel} → +${g.nivel} esencia (total ${S.esencia})`);
            render();
        }

        function subirCap() {
            if (S.esencia < COSTO_CAP) {
                log(`esencia insuficiente para subir el cap (necesitás ${COSTO_CAP})`);
                return render();
            }
            S.esencia -= COSTO_CAP;
            S.cap += 1;
            log(`cap → ${S.cap} (−${COSTO_CAP} esencia)`);
            render();
        }

        // ── Combate ────────────────────────────────────────────────────
        function iniciarPelea(rivalId) {
            const base = RIVALES.find((r) => r.id === rivalId);
            S.rival = { ...base, vidaMax: base.vida, vidaActual: base.vida };
            S.entrante = null;
            S.dropPendiente = null;
            S.fase = 'tuTurno';
            log(`— aparece ${base.nombre} (${base.elemento}, ${base.vida} vida) —`);
            render();
        }

        async function atacar(id) {
            if (S.fase !== 'tuTurno') return;
            const g = fieldeadas().find((x) => x.id === id);
            if (!g) return;

            const r = await resolver({
                accion: 'golpe', nivel: g.nivel, elementoAtacante: g.elemento,
                defensa: S.rival.defensa, elementoDefensor: S.rival.elemento,
            });
            const crit = r.critico;

            if (g.esencia >= r.costoEsencia) {
                g.esencia -= r.costoEsencia;
                S.rival.vidaActual = Math.max(0, S.rival.vidaActual - r.dano);
                log(`atacás con ${g.elemento} n${g.nivel} — ${r.dano} de daño (${r.matchup}, −${r.costoEsencia} es.) — ${S.rival.nombre} ${S.rival.vidaActual}/${S.rival.vidaMax}`, crit);
            } else if (g.esencia === 0) {
                const costoVida = g.nivel * C;
                S.vida = Math.max(0, S.vida - costoVida);
                S.rival.vidaActual = Math.max(0, S.rival.vidaActual - r.dano);
                log(`ÚLTIMO SACUDÓN con ${g.elemento} extinta — ${r.dano} de daño, pagás ${costoVida} de vida`, crit);
            } else {
                log(`${g.elemento} tiene ${g.esencia} de esencia; castear cuesta ${r.costoEsencia} — no alcanza (gastala a 0 para el último sacudón)`);
                return render();
            }

            if (S.rival.vidaActual <= 0) return victoria();
            if (S.vida <= 0) return derrota();
            turnoRival();
        }

        async function turnoRival() {
            S.fase = 'defensa';
            const r = await resolver({
                accion: 'golpe', nivel: S.rival.nivelAtaque, elementoAtacante: S.rival.elemento,
                defensa: S.defensa, elementoDefensor: 'ninguno',
            });
            S.entrante = { dano: r.dano, critico: r.critico, elemento: S.rival.elemento, peso: S.rival.peso };
            log(`${S.rival.nombre} lanza ${S.rival.elemento} — ${r.dano} entrantes${r.critico ? ' ¡CRÍTICO!' : ''}`, r.critico);
            render();
        }

        function comer() {
            if (!S.entrante) return;
            S.vida = Math.max(0, S.vida - S.entrante.dano);
            log(`comés el golpe — ${S.entrante.dano} a la vida — ${S.vida}/${S.vidaMax}`);
            finDefensa();
        }

        async function bloquear(id) {
            if (!S.entrante) return;
            const g = fieldeadas().find((x) => x.id === id && x.esencia > 0);
            if (!g) return;
            const r = await resolver({
                accion: 'bloqueo', peso: S.entrante.peso,
                elementoGema: g.elemento, elementoAtaque: S.entrante.elemento,
            });
            if (g.esencia >= r.costo) {
                g.esencia -= r.costo;
                log(`bloqueás con ${g.elemento} — golpe anulado (−${r.costo} es.) — esencia ${g.esencia}/${g.nivel}`);
                finDefensa();
            } else {
                log(`${g.elemento} tiene ${g.esencia} de esencia; bloquear cuesta ${r.costo} — no alcanza, elegí otra o comé`);
                render();
            }
        }

        function finDefensa() {
            S.entrante = null;
            if (S.vida <= 0) return derrota();
            S.fase = 'tuTurno';
            render();
        }

        function victoria() {
            S.bichosCaidos += 1;
            // Drop: nivel escalado por la dificultad del rival; esencia ~ para varios casts.
            const elemento = ELEMENTOS[Math.floor(Math.random() * ELEMENTOS.length)];
            const nivel = Math.max(1, S.rival.dificultad + Math.floor(Math.random() * 3) - 1);
            const gema = crearGema(elemento, nivel, nivel * 4);
            S.gemas.push(gema);
            S.gemasJuntadas += 1;
            S.dropPendiente = gema.id;
            S.resultado = 'victoria';
            S.fase = 'fin';
            log(`¡cae ${S.rival.nombre}! dropea ${elemento} n${nivel} → al inventario`);
            render();
        }

        function derrota() {
            S.resultado = 'derrota';
            S.fase = 'fin';
            log('— derrota: vida en 0 —');
            render();
        }

        function volverAPreparacion() {
            S.rival = null;
            S.entrante = null;
            S.fase = 'preparacion';
            render();
        }

        function reiniciar() {
            Object.assign(S, {
                fase: 'preparacion', vida: 40, vidaMax: 40, defensa: 8, cap: 12,
                esencia: 0, gemas: magoInicial(), rival: null, entrante: null,
                dropPendiente: null, resultado: null, bichosCaidos: 0, gemasJuntadas: 0, log: [],
            });
            render();
        }

        // ── Render ─────────────────────────────────────────────────────
        function gemaCard(g, botones) {
            const inerte = g.esencia === 0;
            return `
                <div class="gema ${g.elemento} ${inerte ? 'inerte' : ''} ${g.id === S.dropPendiente ? 'nueva' : ''}">
                    <div class="cab">
                        <span class="nom">${g.elemento}</span>
                        <span class="lvl">nivel ${g.nivel}</span>
                    </div>
                    <div class="barra-cont"><div class="barra esencia" style="width:${Math.min(100, (g.esencia / (g.nivel * 4 || 1)) * 100)}%"></div></div>
                    <div class="valor">esencia ${g.esencia}${inerte ? ' — inerte' : ''}</div>
                    ${botones ? `<div class="acciones">${botones}</div>` : ''}
                </div>`;
        }

        function render() {
            // Hoja del mago
            document.getElementById('barra-vida').style.width = `${(S.vida / S.vidaMax) * 100}%`;
            document.getElementById('txt-vida').textContent = `${S.vida} / ${S.vidaMax}`;
            const pMax = poderMax() || 1;
            document.getElementById('barra-poder').style.width = `${(poderActual() / pMax) * 100}%`;
            document.getElementById('txt-poder').textContent = `${poderActual()} / ${poderMax()}`;
            document.getElementById('txt-cap').textContent = S.cap;
            document.getElementById('txt-capuso').textContent = `${capEnUso()}/${S.cap}`;
            document.getElementById('txt-esencia').textContent = S.esencia;
            document.getElementById('txt-costo-cap').textContent = COSTO_CAP;
            const btnCap = document.getElementById('btn-subir-cap');
            btnCap.disabled = S.esencia < COSTO_CAP || S.fase !== 'preparacion';

            // Gemas fieldeadas — en tu turno son botones de ataque
            const enTurno = S.fase === 'tuTurno';
            const enDefensa = S.fase === 'defensa';
            document.getElementById('fieldeadas').innerHTML = fieldeadas().map((g) => {
                let botones = '';
                if (enTurno) {
                    botones = `<button class="ataque" onclick="atacar(${g.id})">atacar</button>`;
                } else if (enDefensa) {
                    const puede = g.esencia > 0;
                    botones = `<button onclick="bloquear(${g.id})" ${puede ? '' : 'disabled'}>bloquear</button>`;
                } else if (S.fase === 'preparacion') {
                    botones = `<button onclick="guardar(${g.id})">guardar</button>`;
                }
                return gemaCard(g, botones);
            }).join('') || '<div class="vacio">sin gemas en el talismán</div>';

            // Inventario
            document.getElementById('inventario').innerHTML = inventario().map((g) => {
                const puedeField = S.fase === 'preparacion' && capEnUso() + g.nivel <= S.cap;
                const botones = S.fase === 'preparacion'
                    ? `<button class="primario" onclick="fieldear(${g.id})" ${puedeField ? '' : 'disabled'}>fieldear</button>
                       <button onclick="desguazar(${g.id})">desguazar (+${g.nivel} es.)</button>`
                    : '';
                return gemaCard(g, botones);
            }).join('') || '<div class="vacio">vacío — ganá peleas para juntar gemas</div>';

            // Progreso
            document.getElementById('p-bichos').textContent = S.bichosCaidos;
            document.getElementById('p-gemas').textContent = S.gemasJuntadas;
            document.getElementById('p-podermax').textContent = poderMax();
            document.getElementById('p-esencia').textContent = S.esencia;

            renderCombate();

            // Log
            document.getElementById('log').innerHTML = S.log.map((l) => `<li class="${l.crit ? 'crit' : ''}">${l.txt}</li>`).join('');
            const ol = document.getElementById('log');
            ol.scrollTop = ol.scrollHeight;
        }

        function renderCombate() {
            const z = document.getElementById('zona-combate');

            if (S.fase === 'preparacion') {
                z.innerHTML = `
                    <h1>Elegí rival</h1>
                    <div class="valor" style="margin-bottom:12px">Preparás el talismán a la izquierda; después, a pelear.</div>
                    <div class="rivales">
                        ${RIVALES.map((r) => `
                            <div class="rival" onclick="iniciarPelea('${r.id}')">
                                <div>
                                    <div style="font-weight:600">${r.nombre}</div>
                                    <div class="desc">${r.vida} vida · def ${r.defensa} · pega ${r.elemento} n${r.nivelAtaque} (peso ${r.peso})</div>
                                </div>
                                <button class="primario">pelear</button>
                            </div>`).join('')}
                    </div>`;
                return;
            }

            if (S.fase === 'fin') {
                const v = S.resultado === 'victoria';
                z.innerHTML = `
                    <div class="final ${S.resultado}">
                        <div class="titulo">${v ? '¡Victoria!' : 'Derrota'}</div>
                        <div class="valor">${v ? `${S.rival.nombre} cae. Revisá el drop en el inventario →` : 'Tu vida llegó a 0.'}</div>
                    </div>
                    ${v && S.dropPendiente ? dropPanel() : ''}
                    <div style="margin-top:16px;display:flex;gap:8px;justify-content:center">
                        ${v ? `<button class="primario" onclick="volverAPreparacion()">seguir</button>` : ''}
                        <button onclick="reiniciar()">reiniciar todo</button>
                    </div>`;
                return;
            }

            // En combate (tuTurno / defensa)
            const rv = S.rival;
            let html = `
                <h1>${rv.nombre}</h1>
                <div class="barra-cont"><div class="barra vida" style="width:${(rv.vidaActual / rv.vidaMax) * 100}%"></div></div>
                <div class="valor">${rv.vidaActual} / ${rv.vidaMax} — ${rv.elemento}, def ${rv.defensa}</div>`;

            if (S.fase === 'tuTurno') {
                html += `
                    <div class="telegrafia" style="margin-top:12px">
                        Anticipás su golpe: <b>${rv.elemento}</b> nivel ${rv.nivelAtaque}, peso ${rv.peso}.
                        Atacá con una gema (columna izquierda), o guardá esencia para bloquear.
                    </div>`;
            }

            if (S.fase === 'defensa' && S.entrante) {
                const e = S.entrante;
                html += `
                    <div class="entrante">
                        <div style="font-weight:600;margin-bottom:6px">Golpe entrante: ${e.dano} (${e.elemento}, peso ${e.peso})${e.critico ? ' ¡CRÍTICO!' : ''}</div>
                        <div class="valor" style="margin-bottom:8px">Bloqueá con una gema (izquierda; barato con el elemento que le gana) o comé el golpe.</div>
                        <button class="ataque" onclick="comer()">comer — ${e.dano} a la vida</button>
                    </div>`;
            }

            z.innerHTML = html;
        }

        function dropPanel() {
            const g = S.gemas.find((x) => x.id === S.dropPendiente);
            if (!g) return '';
            const puedeField = capEnUso() + g.nivel <= S.cap;
            return `
                <div class="drop">
                    <div style="font-weight:600;margin-bottom:6px">Botín: gema de ${g.elemento} nivel ${g.nivel}</div>
                    <div class="valor" style="margin-bottom:8px">¿La fieldeás${puedeField ? '' : ' (no entra en el cap)'}, la guardás, o la fundís en esencia para el cap?</div>
                    <div class="acciones">
                        <button class="primario" onclick="fieldear(${g.id})" ${puedeField ? '' : 'disabled'}>fieldear</button>
                        <button onclick="S.dropPendiente=null;render()">guardar</button>
                        <button onclick="desguazar(${g.id})">desguazar (+${g.nivel} es.)</button>
                    </div>
                </div>`;
        }

        render();
    </script>
</body>
</html>
