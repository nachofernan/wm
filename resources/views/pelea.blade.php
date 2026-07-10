<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wizard's Maze — Pelea</title>
    <style>
        :root {
            --fuego: #e08a4a; --agua: #4aa8e0; --tierra: #a0824a; --aire: #b8e04a;
            --vida: #e05555; --esencia: #55b088;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; min-height: 100%;
            background: radial-gradient(circle at 50% 0%, #24222e, #16151c 70%);
            color: #eee; font-family: system-ui, sans-serif;
        }
        .arena { max-width: 640px; margin: 0 auto; padding: 20px 16px 48px; }
        h1 { font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a86a0; text-align: center; }

        /* ── Combatiente ── */
        .combatiente { background: #201f29; border: 1px solid #333140; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
        .combatiente .cabecera { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
        .nombre { font-size: 17px; font-weight: 700; }
        .tag { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; padding: 2px 8px; border-radius: 999px; background: #333140; }
        .tag.fuego { color: var(--fuego); } .tag.agua { color: var(--agua); }
        .tag.tierra { color: var(--tierra); } .tag.aire { color: var(--aire); }

        .barra-vida { position: relative; height: 22px; background: #100f16; border-radius: 6px; overflow: hidden; }
        .barra-vida > span { position: absolute; inset: 0; height: 100%; background: linear-gradient(#f06b6b, #c33); transition: width 0.35s ease; }
        .barra-vida > b { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; text-shadow: 0 1px 2px #000; }

        .intencion { margin-top: 10px; font-size: 13px; color: #d9a066; min-height: 18px; }
        .intencion.vacia { color: #6a6780; }

        /* ── Gemas ── */
        .gemas { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .gema {
            flex: 1 1 90px; background: #181722; border: 2px solid #333140; border-radius: 10px;
            padding: 9px; cursor: default; transition: transform 0.1s, border-color 0.15s; text-align: center;
        }
        .gema.jugable { cursor: pointer; }
        .gema.jugable:hover { transform: translateY(-3px); border-color: #6a6790; }
        .gema.muerta { opacity: 0.4; }
        .gema .el { font-weight: 700; text-transform: capitalize; font-size: 14px; }
        .gema.fuego .el { color: var(--fuego); } .gema.agua .el { color: var(--agua); }
        .gema.tierra .el { color: var(--tierra); } .gema.aire .el { color: var(--aire); }
        .gema .nivel { font-size: 11px; color: #9a97ad; }
        .barra-esencia { height: 8px; background: #100f16; border-radius: 4px; overflow: hidden; margin-top: 6px; }
        .barra-esencia > span { display: block; height: 100%; background: var(--esencia); transition: width 0.3s; }
        .gema .ese { font-size: 11px; color: #8a87a0; margin-top: 3px; }

        /* ── Turno / acciones ── */
        .banner { text-align: center; font-size: 15px; font-weight: 600; padding: 10px; margin: 6px 0 14px; border-radius: 10px; background: #2a2836; }
        .banner.tu { color: #8fd0ff; } .banner.enemigo { color: #ff9a6b; }
        .acciones { display: flex; gap: 10px; justify-content: center; margin-bottom: 14px; flex-wrap: wrap; }
        button {
            cursor: pointer; border: 1px solid #4a4760; background: #2f2d3d; color: #eee;
            border-radius: 8px; padding: 9px 16px; font-size: 14px; transition: background 0.15s;
        }
        button:hover:not(:disabled) { background: #3b3850; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }
        button.primario { background: #4a5cc0; border-color: #5b6dd0; }
        button.primario:hover:not(:disabled) { background: #5666d0; }

        .prompt { text-align: center; font-size: 13px; color: #b7b4c8; min-height: 18px; margin-bottom: 10px; }

        /* ── Log ── */
        .log { background: #16151d; border-radius: 10px; padding: 10px 14px; font-size: 13px; font-family: ui-monospace, monospace; max-height: 200px; overflow-y: auto; }
        .log div { padding: 2px 0; border-bottom: 1px solid #201f29; color: #c9c6da; }
        .log div.crit { color: #ffd166; }
        .log div.bien { color: #7fd8a0; }
        .log div.mal { color: #f08a8a; }

        /* ── Setup / fin ── */
        .panel { background: #201f29; border: 1px solid #333140; border-radius: 12px; padding: 20px; }
        .presets { display: flex; gap: 10px; flex-wrap: wrap; margin: 14px 0; }
        .preset { flex: 1 1 150px; text-align: left; padding: 12px; }
        .preset small { display: block; color: #9a97ad; font-weight: 400; margin-top: 4px; font-size: 12px; }
        .loadout { font-size: 13px; color: #b7b4c8; margin-top: 10px; }
        .oculto { display: none; }
        .fin { text-align: center; }
        .fin h2 { font-size: 26px; margin: 4px 0 12px; }
        .fin.victoria h2 { color: #7fd8a0; } .fin.derrota h2 { color: #f08a8a; }
    </style>
</head>
<body>
<div class="arena">
    <h1>Wizard's Maze · Pelea</h1>

    {{-- Seteo previo: elegir rival --}}
    <div id="setup" class="panel">
        <p>Tu talismán ya está armado. Elegí contra qué peleás.</p>
        <div class="presets" id="presets"></div>
        <div class="loadout" id="loadout-preview"></div>
    </div>

    {{-- Arena de combate --}}
    <div id="combate" class="oculto">
        <div class="combatiente" id="card-monstruo">
            <div class="cabecera">
                <span class="nombre" id="m-nombre"></span>
                <span class="tag" id="m-tag"></span>
            </div>
            <div class="barra-vida"><span id="m-barra"></span><b id="m-vida"></b></div>
            <div class="intencion" id="m-intencion"></div>
        </div>

        <div class="banner" id="banner"></div>
        <div class="prompt" id="prompt"></div>
        <div class="acciones" id="acciones"></div>

        <div class="combatiente" id="card-mago">
            <div class="cabecera">
                <span class="nombre">Mago</span>
                <span class="tag">defensa <span id="p-def"></span></span>
            </div>
            <div class="barra-vida"><span id="p-barra"></span><b id="p-vida"></b></div>
            <div class="gemas" id="gemas"></div>
        </div>

        <div class="log" id="log"></div>
    </div>

    {{-- Fin --}}
    <div id="fin" class="panel fin oculto">
        <h2 id="fin-titulo"></h2>
        <p id="fin-texto"></p>
        <button class="primario" onclick="location.reload()">otra vez</button>
    </div>
</div>

<script>
    // Playground descartable de combate — reusa el resolver real vía POST
    // /pj/combate (autoridad en el servidor, axioma 4). No calcula daño en el
    // cliente. Combate por turnos y telegrafiado, según docs/DECISIONES.md 012.
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    const MONSTRUOS = {
        golem:   { nombre: 'Gólem de tierra', elemento: 'tierra', vida: 70, defensa: 30, ataqueNivel: 6, peso: 2, desc: 'Duro y lento. Pega parejo.' },
        igneo:   { nombre: 'Ígneo',           elemento: 'fuego',  vida: 55, defensa: 18, ataqueNivel: 7, peso: 3, desc: 'Frágil pero golpea fuerte.' },
        silfide: { nombre: 'Sílfide',         elemento: 'aire',   vida: 45, defensa: 12, ataqueNivel: 5, peso: 1, desc: 'Blanda; sus golpes son leves.' },
    };

    function loadoutInicial() {
        return [
            { elemento: 'fuego',  nivel: 5, esencia: 20 },
            { elemento: 'agua',   nivel: 4, esencia: 15 },
            { elemento: 'tierra', nivel: 3, esencia: 20 },
        ].map((g, i) => ({ id: i, ...g }));
    }

    const S = {
        fase: 'setup',          // setup · tuTurno · defensa · fin
        pj: { vidaMax: 40, vida: 40, defensa: 8, gemas: [] },
        monstruo: null,
        entrante: null,         // golpe del monstruo esperando comer/bloquear
        semilla: 1,
        log: [],
    };

    let idSel = null; // gema seleccionada para atacar (setup de acción)

    async function resolver(cuerpo) {
        const resp = await fetch('/pj/combate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ ...cuerpo, semilla: S.semilla++ }),
        });
        return resp.json();
    }

    function log(texto, clase) { S.log.push({ texto, clase }); }

    // ── Arranque ──────────────────────────────────────────────────────────
    function iniciar(key) {
        const m = MONSTRUOS[key];
        S.monstruo = { ...m, vidaMax: m.vida, vida: m.vida };
        S.pj = { vidaMax: 40, vida: 40, defensa: 8, gemas: loadoutInicial() };
        S.fase = 'tuTurno';
        S.log = [];
        log(`Aparece ${m.nombre}. ${m.desc}`);
        telegrafiar();
        document.getElementById('setup').classList.add('oculto');
        document.getElementById('combate').classList.remove('oculto');
        render();
    }

    function telegrafiar() {
        S.monstruo.intencion = { elemento: S.monstruo.elemento, peso: S.monstruo.peso };
    }

    // ── Tu turno ──────────────────────────────────────────────────────────
    async function atacar(gemaId) {
        if (S.fase !== 'tuTurno') return;
        const g = S.pj.gemas.find((x) => x.id === gemaId);
        const r = await resolver({
            accion: 'golpe', nivel: g.nivel, elementoAtacante: g.elemento,
            defensa: S.monstruo.defensa, elementoDefensor: S.monstruo.elemento,
        });
        const crit = r.critico ? ' ¡CRÍTICO!' : '';

        if (g.esencia >= r.costoEsencia) {
            g.esencia -= r.costoEsencia;
            S.monstruo.vida = Math.max(0, S.monstruo.vida - r.dano);
            log(`Atacás con ${g.elemento} → ${r.dano} de daño${crit} (${r.matchup}, −${r.costoEsencia} esencia)`, r.critico ? 'crit' : 'bien');
        } else if (g.esencia === 0) {
            const costoVida = g.nivel * 2; // C por defecto (DECISIONES 012)
            S.pj.vida = Math.max(0, S.pj.vida - costoVida);
            S.monstruo.vida = Math.max(0, S.monstruo.vida - r.dano);
            log(`Último sacudón con ${g.elemento} extinta → ${r.dano} de daño${crit}, pagás ${costoVida} de vida`, 'mal');
            if (S.pj.vida <= 0) return fin('derrota');
        } else {
            log(`${g.elemento} tiene esencia ${g.esencia}, atacar cuesta ${r.costoEsencia} — no alcanza. Elegí otra.`, 'mal');
            return render();
        }

        if (S.monstruo.vida <= 0) return fin('victoria');
        await turnoMonstruo();
    }

    async function guardar() {
        if (S.fase !== 'tuTurno') return;
        log('Te cubrís y guardás esencia para el golpe que viene.');
        await turnoMonstruo();
    }

    // ── Turno del monstruo ────────────────────────────────────────────────
    async function turnoMonstruo() {
        S.fase = 'enemigo';
        render();
        const r = await resolver({
            accion: 'golpe', nivel: S.monstruo.ataqueNivel, elementoAtacante: S.monstruo.elemento,
            defensa: S.pj.defensa, elementoDefensor: 'ninguno',
        });
        S.entrante = { dano: r.dano, critico: r.critico, peso: S.monstruo.peso, elemento: S.monstruo.elemento };
        S.fase = 'defensa';
        log(`${S.monstruo.nombre} ataca con ${S.entrante.elemento} → ${r.dano} entrantes${r.critico ? ' ¡CRÍTICO!' : ''} (peso ${S.entrante.peso})`, 'mal');
        render();
    }

    function comer() {
        if (S.fase !== 'defensa') return;
        S.pj.vida = Math.max(0, S.pj.vida - S.entrante.dano);
        log(`Comés el golpe → −${S.entrante.dano} de vida`, 'mal');
        finDefensa();
    }

    async function bloquear(gemaId) {
        if (S.fase !== 'defensa') return;
        const g = S.pj.gemas.find((x) => x.id === gemaId);
        const r = await resolver({
            accion: 'bloqueo', peso: S.entrante.peso,
            elementoGema: g.elemento, elementoAtaque: S.entrante.elemento,
        });
        if (g.esencia >= r.costo) {
            g.esencia -= r.costo;
            log(`Bloqueás con ${g.elemento} → golpe anulado (−${r.costo} esencia)`, 'bien');
            finDefensa();
        } else {
            log(`${g.elemento} tiene esencia ${g.esencia}, bloquear cuesta ${r.costo} — no alcanza. Elegí otra o comé.`, 'mal');
            render();
        }
    }

    function finDefensa() {
        S.entrante = null;
        if (S.pj.vida <= 0) return fin('derrota');
        S.fase = 'tuTurno';
        telegrafiar();
        render();
    }

    function fin(resultado) {
        S.fase = 'fin';
        render();
        const el = document.getElementById('fin');
        el.className = `panel fin ${resultado}`;
        document.getElementById('fin-titulo').textContent = resultado === 'victoria' ? '¡Victoria!' : 'Derrota';
        document.getElementById('fin-texto').textContent = resultado === 'victoria'
            ? `Bajaste a ${S.monstruo.nombre}. Saliste con algo.`
            : 'El talismán no alcanzó. La vida llegó a 0.';
        document.getElementById('combate').classList.add('oculto');
        el.classList.remove('oculto');
    }

    // ── Render ────────────────────────────────────────────────────────────
    function render() {
        const m = S.monstruo;
        if (m) {
            document.getElementById('m-nombre').textContent = m.nombre;
            const tag = document.getElementById('m-tag');
            tag.textContent = m.elemento; tag.className = `tag ${m.elemento}`;
            document.getElementById('m-barra').style.width = `${(m.vida / m.vidaMax) * 100}%`;
            document.getElementById('m-vida').textContent = `${m.vida} / ${m.vidaMax}`;
            const intEl = document.getElementById('m-intencion');
            if (S.fase === 'tuTurno' && m.intencion) {
                intEl.className = 'intencion';
                intEl.textContent = `⚔ Prepara un golpe de ${m.intencion.elemento} (peso ${m.intencion.peso}).`;
            } else {
                intEl.className = 'intencion vacia';
                intEl.textContent = S.fase === 'defensa' ? '¡Ataca ahora!' : '';
            }
        }

        document.getElementById('p-def').textContent = S.pj.defensa;
        document.getElementById('p-barra').style.width = `${(S.pj.vida / S.pj.vidaMax) * 100}%`;
        document.getElementById('p-vida').textContent = `${S.pj.vida} / ${S.pj.vidaMax}`;

        // Banner + prompt + acciones según fase
        const banner = document.getElementById('banner');
        const prompt = document.getElementById('prompt');
        const acciones = document.getElementById('acciones');
        acciones.innerHTML = '';
        if (S.fase === 'tuTurno') {
            banner.className = 'banner tu'; banner.textContent = 'Tu turno';
            prompt.textContent = 'Tocá una gema para atacar, o guardá esencia para el próximo golpe.';
            const b = document.createElement('button');
            b.textContent = 'guardar (no atacar)'; b.onclick = guardar;
            acciones.appendChild(b);
        } else if (S.fase === 'defensa') {
            banner.className = 'banner enemigo'; banner.textContent = 'Golpe entrante';
            prompt.textContent = `${S.entrante.dano} de daño de ${S.entrante.elemento}. Comé el golpe o bloqueá con una gema.`;
            const b = document.createElement('button');
            b.className = 'primario'; b.textContent = `comer (−${S.entrante.dano} vida)`; b.onclick = comer;
            acciones.appendChild(b);
        } else {
            banner.className = 'banner enemigo'; banner.textContent = 'El monstruo actúa…';
            prompt.textContent = '';
        }

        // Gemas — jugables según la fase
        const cont = document.getElementById('gemas');
        cont.innerHTML = '';
        S.pj.gemas.forEach((g) => {
            const muerta = g.esencia === 0;
            const jugable = (S.fase === 'tuTurno') || (S.fase === 'defensa' && g.esencia > 0);
            const div = document.createElement('div');
            div.className = `gema ${g.elemento} ${jugable ? 'jugable' : ''} ${muerta ? 'muerta' : ''}`;
            div.innerHTML = `
                <div class="el">${g.elemento}</div>
                <div class="nivel">nivel ${g.nivel}${muerta ? ' · extinta' : ''}</div>
                <div class="barra-esencia"><span style="width:${(g.esencia / (g.nivel * 8)) * 100}%"></span></div>
                <div class="ese">esencia ${g.esencia}</div>
            `;
            if (S.fase === 'tuTurno') div.onclick = () => atacar(g.id);
            else if (S.fase === 'defensa' && g.esencia > 0) div.onclick = () => bloquear(g.id);
            cont.appendChild(div);
        });

        const logEl = document.getElementById('log');
        logEl.innerHTML = S.log.map((l) => `<div class="${l.clase || ''}">${l.texto}</div>`).join('');
        logEl.scrollTop = logEl.scrollHeight;
    }

    // ── Setup ─────────────────────────────────────────────────────────────
    const presets = document.getElementById('presets');
    Object.entries(MONSTRUOS).forEach(([key, m]) => {
        const b = document.createElement('button');
        b.className = 'preset';
        b.innerHTML = `${m.nombre}<small>${m.elemento} · ${m.vida} vida · def ${m.defensa}<br>${m.desc}</small>`;
        b.onclick = () => iniciar(key);
        presets.appendChild(b);
    });
    document.getElementById('loadout-preview').innerHTML =
        'Tu talismán: ' + loadoutInicial().map((g) => `<b>${g.elemento}</b> n${g.nivel}/e${g.esencia}`).join(' · ');
</script>
</body>
</html>
