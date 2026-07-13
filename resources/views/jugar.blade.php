<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wizard's Maze</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --bg: #16151a; --caja: #201f27; --caja2: #2a2933; --linea: #3a3945;
            --texto: #e8e6ef; --tenue: #918da0;
            --fuego: #e08a4a; --agua: #4aa8e0; --tierra: #b98a52; --aire: #b8e04a;
            --vida: #e0554f; --poder: #6f8fe0; --esencia: #55b088;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--texto); font-family: system-ui, sans-serif; }
        .maze-layout { display: flex; align-items: flex-start; gap: 16px; padding: 16px; }
        .caja { background: var(--caja); border: 1px solid var(--linea); border-radius: 10px; padding: 14px; }
        .col { display: flex; flex-direction: column; gap: 14px; width: 300px; }
        .col.gemas { width: 360px; } /* la columna que más crece: se le da aire */
        .hoja-cab { display: flex; justify-content: space-between; align-items: center; }
        .hoja-acc { display: flex; gap: 6px; }
        h2 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tenue); }
        h3 { margin: 0 0 4px; font-size: 16px; }
        .barra-cont { background: #100f14; border-radius: 5px; height: 14px; overflow: hidden; margin: 3px 0; }
        .barra { height: 100%; transition: width 0.2s; }
        .barra.vida { background: var(--vida); } .barra.poder { background: var(--poder); } .barra.esencia { background: var(--esencia); }
        .valor { font-size: 12px; color: var(--tenue); }
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; margin-top: 8px; }
        .stat { font-size: 12px; } .stat b { font-size: 16px; display: block; }
        .gema { border: 1px solid var(--linea); border-left: 3px solid var(--linea); border-radius: 6px; padding: 7px 9px; margin-bottom: 7px; background: var(--caja2); }
        .gema.fuego { border-left-color: var(--fuego); } .gema.agua { border-left-color: var(--agua); }
        .gema.tierra { border-left-color: var(--tierra); } .gema.aire { border-left-color: var(--aire); }
        .gema.inerte { opacity: 0.55; }
        .gema.mini { padding: 6px 9px; margin-bottom: 5px; }
        .barra-cont.slim { height: 6px; margin: 4px 0 0; }
        .esencia-num { font-size: 12px; color: var(--esencia); font-weight: 600; }
        .punto { display: inline-block; width: 8px; height: 8px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
        .punto.fuego { background: var(--fuego); } .punto.agua { background: var(--agua); }
        .punto.tierra { background: var(--tierra); } .punto.aire { background: var(--aire); }
        /* filas compactas del inventario */
        .inv-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .orden { background: var(--caja2); color: var(--texto); border: 1px solid var(--linea); border-radius: 6px; padding: 3px 6px; font-size: 12px; font-family: inherit; }
        .filtros { display: flex; gap: 5px; margin-bottom: 8px; flex-wrap: wrap; }
        .chip { padding: 3px 8px; font-size: 12px; display: flex; align-items: center; gap: 3px; }
        .chip.on { border-color: var(--texto); background: #33323d; }
        .gema.fila { display: flex; align-items: center; gap: 8px; padding: 5px 9px; margin-bottom: 4px; border-left-width: 3px; }
        .gema.fila .nom { flex: 1; font-size: 13px; text-transform: capitalize; white-space: nowrap; }
        .gema.fila .esc { color: var(--esencia); }
        .acciones-fila { display: flex; gap: 4px; margin: 0; }
        .mini-btn { padding: 3px 8px; font-size: 12px; line-height: 1; }
        .gema .cab { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
        .gema .nom { font-weight: 600; text-transform: capitalize; }
        .acciones { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; }
        button { cursor: pointer; border: 1px solid var(--linea); background: var(--caja2); color: var(--texto); border-radius: 6px; padding: 6px 11px; font-size: 13px; font-family: inherit; }
        button:hover:not(:disabled) { border-color: var(--tenue); }
        button:disabled { opacity: 0.35; cursor: not-allowed; }
        button.primario { background: #33507a; border-color: #45688f; }
        button.ataque { background: #4a2f2f; border-color: #6f4444; }
        button.ataque.ventaja { background: #2f4a33; border-color: #4c7a55; }
        button.ataque.reves { background: #3a3540; border-color: #55505f; opacity: 0.85; }
        /* Botón esperando al servidor (023): oculta el texto y gira un spinner. */
        button.enviando { color: transparent !important; position: relative; pointer-events: none; }
        button.enviando::after {
            content: ''; position: absolute; inset: 0; margin: auto;
            width: 12px; height: 12px; border: 2px solid var(--texto);
            border-right-color: transparent; border-radius: 50%; animation: giro 0.6s linear infinite;
        }
        @keyframes giro { to { transform: rotate(360deg); } }
        .sync { font-size: 12px; color: var(--tenue); display: inline-flex; align-items: center; gap: 6px; }
        .sync::before {
            content: ''; width: 11px; height: 11px; border: 2px solid var(--tenue);
            border-right-color: transparent; border-radius: 50%; animation: giro 0.6s linear infinite;
        }
        .rueda .rueda-ciclo { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; font-size: 13px; }
        .rueda .el { padding: 2px 7px; border-radius: 5px; text-transform: capitalize; font-weight: 600; }
        .rueda .el.fuego { background: #3a221a; color: var(--fuego); }
        .rueda .el.agua { background: #182a3a; color: var(--agua); }
        .rueda .el.tierra { background: #2f2418; color: var(--tierra); }
        .rueda .el.aire { background: #2a2f18; color: var(--aire); }
        .rueda .fl { color: var(--tenue); }
        .telegrafia { border: 1px dashed #7a6f44; background: #26230f; border-radius: 8px; padding: 9px 11px; font-size: 13px; margin: 10px 0; }
        .entrante { border: 1px solid var(--vida); background: #2a1414; border-radius: 8px; padding: 11px; margin-top: 10px; }
        .drop { border: 1px solid #d8c24a; background: #29260f; border-radius: 8px; padding: 12px; margin-top: 12px; }
        .final { text-align: center; padding: 14px 0; } .final .titulo { font-size: 22px; font-weight: 700; }
        .final.victoria .titulo { color: var(--esencia); } .final.derrota .titulo { color: var(--vida); }
        .vacio { color: var(--tenue); font-size: 13px; font-style: italic; }
        canvas { background: #f4f2ea; border-radius: 6px; }
        .consola {
            margin: 0 16px 16px; background: #0d0c11; border: 1px solid var(--linea);
            border-radius: 8px; padding: 10px 14px;
            font-family: ui-monospace, monospace; font-size: 12.5px; line-height: 1.6;
            height: 160px; overflow-y: auto; color: #a9d5b0;
        }
        .consola .linea { white-space: pre-wrap; }
        .consola .linea.combate { color: #e0c04a; }
    </style>
</head>
<body>
    <script>
        window.__MAZE__ = {
            seed: {{ $seed }},
            ancho: {{ $ancho }},
            alto: {{ $alto }},
            token: @json($token),
            estado: @json($estado),
        };
    </script>

    <div x-data="game" x-on:keydown.window="mover($event)">
    <div class="maze-layout">
        <canvas x-ref="canvas" class="shrink-0"></canvas>

        <!-- ── Mago + gemas: la columna que más crece ────────────────── -->
        <div class="col gemas">
            <div class="caja hoja" x-show="talisman">
                <div class="hoja-cab">
                    <h3 style="margin:0">El mago</h3>
                    <span class="sync" x-show="cargando" x-cloak>sincronizando…</span>
                    <div class="hoja-acc" x-show="!combate && !cargando">
                        <button class="mini-btn" @click="curar()" :class="{ enviando: accionActiva === 'curar-' }"
                            :disabled="cargando || talisman.esencia < 1 || talisman.vida >= talisman.vidaMax"
                            title="convertir esencia en vida (1:1)" x-text="`curar +${cuantoCura()}`"></button>
                        <button class="mini-btn" @click="subirNivel()" :class="{ enviando: accionActiva === 'subirNivel-' }"
                            :disabled="cargando || talisman.esencia < costoNivel()" :title="`subir nivel del talismán (${costoNivel()} es.)`">+1 nivel</button>
                    </div>
                </div>
                <div class="stat-grid">
                    <div class="stat">nivel<b x-text="talisman.nivel"></b></div>
                    <div class="stat">vida<b x-text="`${talisman.vida}/${talisman.vidaMax}`"></b></div>
                    <div class="stat">poder<b x-text="`${poderActual()}/${capEnUso()}`"></b></div>
                    <div class="stat">cap<b x-text="`${capEnUso()}/${talisman.cap}`"></b></div>
                    <div class="stat">ataque<b x-text="`+${Math.round(talisman.ataqueMult * 100)}%`"></b></div>
                    <div class="stat">defensa<b x-text="talisman.defensa"></b></div>
                    <div class="stat">esencia<b x-text="talisman.esencia"></b></div>
                    <div class="stat">bichos<b x-text="talisman.bichosCaidos"></b></div>
                    <div class="stat">gemas<b x-text="talisman.gemasJuntadas"></b></div>
                </div>
            </div>

            <div class="caja" x-show="talisman">
                <div class="inv-head">
                    <h2 style="margin:0">Gemas fieldeadas</h2>
                    <select x-model="ordenField" @change="reordenarField()" class="orden">
                        <option value="nivel">↓ nivel</option>
                        <option value="esencia">↓ esencia</option>
                        <option value="elemento">↓ tipo</option>
                    </select>
                </div>
                <template x-for="g in fieldeadasMostradas()" :key="g.id">
                    <div class="gema mini" :class="[g.elemento, g.esencia === 0 ? 'inerte' : '']">
                        <div class="cab">
                            <span class="nom"><span class="punto" :class="g.elemento"></span><span x-text="g.elemento"></span> <span class="valor" x-text="`n${g.nivel}`"></span></span>
                            <span class="esencia-num" x-text="g.esencia === 0 ? 'inerte' : `${g.esencia} es · ~${golpesRestantes(g)} golpes`"></span>
                        </div>
                        <div class="barra-cont slim"><div class="barra esencia" :style="`width:${anchoEsencia(g)}%`"></div></div>
                        <div class="acciones" x-show="combate && combate.turno === 'tuTurno'">
                            <button class="ataque" :class="[matchupAtaque(g), { enviando: accionActiva === `atacar-${g.id}` }]" :disabled="cargando" @click="atacar(g.id)"
                                x-text="`atacar · ~${danioEstimado(g)} dmg · ${costoAtaqueLabel(g)} (${matchupAtaque(g)})`"></button>
                        </div>
                        <div class="acciones" x-show="combate && combate.turno === 'defensa'">
                            <button @click="bloquear(g.id)" :class="{ enviando: accionActiva === `bloquear-${g.id}` }" :disabled="cargando || g.esencia === 0"
                                x-text="`bloquear · ${costoBloqueoEstimado(g)} es. (${matchupBloqueo(g)})`"></button>
                        </div>
                        <div class="acciones" x-show="!combate">
                            <button @click="guardar(g.id)" :class="{ enviando: accionActiva === `guardar-${g.id}` }" :disabled="cargando">guardar</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="caja" x-show="talisman && inventario().length">
                <div class="inv-head">
                    <h2 style="margin:0">Inventario (<span x-text="inventario().length"></span>)</h2>
                    <select x-model="ordenInv" class="orden">
                        <option value="nivel">↓ nivel</option>
                        <option value="esencia">↓ esencia</option>
                        <option value="elemento">por elemento</option>
                    </select>
                </div>
                <div class="filtros">
                    <button class="chip" :class="filtroInv === null ? 'on' : ''" @click="filtroInv = null">todos</button>
                    <template x-for="el in ['fuego','agua','tierra','aire']" :key="el">
                        <button class="chip" :class="[el, filtroInv === el ? 'on' : '']" x-show="conteoInv(el)"
                            @click="filtroInv = filtroInv === el ? null : el">
                            <span class="punto" :class="el"></span><span x-text="conteoInv(el)"></span>
                        </button>
                    </template>
                </div>
                <template x-for="g in inventarioMostrado()" :key="g.id">
                    <div class="gema fila" :class="[g.elemento, g.esencia === 0 ? 'inerte' : '']">
                        <span class="nom"><span class="punto" :class="g.elemento"></span><span x-text="g.elemento"></span> <span class="valor" x-text="`n${g.nivel}`"></span></span>
                        <span class="valor esc" x-text="`${g.esencia} es`"></span>
                        <div class="acciones-fila" x-show="!combate">
                            <button class="primario mini-btn" @click="fieldear(g.id)" :class="{ enviando: accionActiva === `fieldear-${g.id}` }" :disabled="cargando || !puedeFieldear(g)" title="equipar">▲</button>
                            <button class="mini-btn" @click="desguazar(g.id)" :class="{ enviando: accionActiva === `desguazar-${g.id}` }" :disabled="cargando" :title="`desguazar (+${g.nivel} es.)`" x-text="`+${g.nivel}`"></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Rueda + combate + partida ─────────────────────────────── -->
        <div class="col combate">
            <div class="caja rueda">
                <h2>Rueda elemental — quién le gana a quién</h2>
                <div class="rueda-ciclo">
                    <span class="el fuego">fuego</span><span class="fl">→</span><span class="el aire">aire</span><span class="fl">→</span><span class="el tierra">tierra</span><span class="fl">→</span><span class="el agua">agua</span><span class="fl">↺</span>
                </div>
                <div class="valor" style="margin-top:6px">Le pegás al que le ganás (×1.5) y flojo al que te gana (×0.5). Para bloquear, la gema que le gana al golpe gasta la mitad.</div>
            </div>

            <!-- Combate activo -->
            <div class="caja" x-show="combate" x-cloak>
                <h3 x-text="combate ? combate.monstruo.nombre : ''"></h3>
                <template x-if="combate">
                    <div>
                        <div class="barra-cont"><div class="barra vida" :style="`width:${(combate.monstruo.vida / combate.monstruo.vidaMax) * 100}%`"></div></div>
                        <div class="valor" x-text="`${combate.monstruo.vida} / ${combate.monstruo.vidaMax} — ${combate.monstruo.elemento}, def ${combate.monstruo.defensa}`"></div>

                        <div class="telegrafia" x-show="combate.turno === 'tuTurno'">
                            Anticipás su golpe: <b x-text="combate.monstruo.elemento"></b> nivel <span x-text="combate.monstruo.nivelAtaque"></span>, peso <span x-text="combate.monstruo.peso"></span>.
                            Atacá con una gema, o guardá esencia para bloquear.
                        </div>

                        <div class="entrante" x-show="combate.turno === 'defensa' && combate.entrante">
                            <div style="font-weight:600;margin-bottom:6px" x-text="`Golpe entrante: ${combate.entrante ? combate.entrante.dano : ''} (${combate.entrante ? combate.entrante.elemento : ''})${combate.entrante && combate.entrante.critico ? ' ¡CRÍTICO!' : ''}`"></div>
                            <div class="valor" style="margin-bottom:8px">Bloqueá con una gema (barato con el elemento que le gana) o comé el golpe.</div>
                            <button class="ataque" @click="comer()" :class="{ enviando: accionActiva === 'comer-' }" :disabled="cargando" x-text="`comer — ${combate.entrante ? combate.entrante.dano : ''} a la vida`"></button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Resultado + botín -->
            <div class="caja" x-show="resultado" x-cloak>
                <div class="final" :class="resultado">
                    <div class="titulo" x-text="resultado === 'victoria' ? '¡Victoria!' : 'Derrota'"></div>
                </div>
                <div class="drop" x-show="drop && drop.length">
                    <div style="font-weight:600;margin-bottom:6px" x-text="drop && drop.length > 1 ? `Botín: ${drop.length} piedras` : 'Botín'"></div>
                    <template x-for="d in (drop || [])" :key="d.id">
                        <div class="valor" x-text="`· gema de ${d.elemento} nivel ${d.nivel}`"></div>
                    </template>
                    <div class="valor" style="margin-top:4px">Quedó en tu inventario.</div>
                </div>
                <div style="margin-top:12px;text-align:center">
                    <button class="primario" x-show="resultado === 'victoria'" @click="seguir()">seguir</button>
                    <a x-show="resultado === 'derrota'" href="{{ route('jugar.crear') }}"><button>nueva partida</button></a>
                </div>
            </div>

            <!-- Partida -->
            <div class="caja">
                <div class="valor">seed: <span x-text="seed"></span></div>
                <p x-show="terminado" style="font-weight:700;margin:6px 0" x-text="resultado === 'derrota' ? 'el mago cayó' : 'laberinto finalizado'"></p>
                <a href="{{ route('jugar.crear') }}" style="display:inline-block;margin-top:6px"><button>nueva partida</button></a>
            </div>

        </div>
    </div>

    <!-- ── Consola ───────────────────────────────────────────────────── -->
    <div class="consola" x-ref="consolaBox" x-effect="consola.length; $nextTick(() => { if ($refs.consolaBox) $refs.consolaBox.scrollTop = $refs.consolaBox.scrollHeight; })">
        <template x-for="(l, i) in consola" :key="i">
            <div class="linea" :class="l.includes('⚔') || l.includes('daño') || l.includes('bloqueás') || l.includes('cae') ? 'combate' : ''" x-text="l"></div>
        </template>
    </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
