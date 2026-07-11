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
        .gema .cab { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
        .gema .nom { font-weight: 600; text-transform: capitalize; }
        .acciones { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; }
        button { cursor: pointer; border: 1px solid var(--linea); background: var(--caja2); color: var(--texto); border-radius: 6px; padding: 6px 11px; font-size: 13px; font-family: inherit; }
        button:hover:not(:disabled) { border-color: var(--tenue); }
        button:disabled { opacity: 0.35; cursor: not-allowed; }
        button.primario { background: #33507a; border-color: #45688f; }
        button.ataque { background: #4a2f2f; border-color: #6f4444; }
        .telegrafia { border: 1px dashed #7a6f44; background: #26230f; border-radius: 8px; padding: 9px 11px; font-size: 13px; margin: 10px 0; }
        .entrante { border: 1px solid var(--vida); background: #2a1414; border-radius: 8px; padding: 11px; margin-top: 10px; }
        .drop { border: 1px solid #d8c24a; background: #29260f; border-radius: 8px; padding: 12px; margin-top: 12px; }
        .final { text-align: center; padding: 14px 0; } .final .titulo { font-size: 22px; font-weight: 700; }
        .final.victoria .titulo { color: var(--esencia); } .final.derrota .titulo { color: var(--vida); }
        .log { font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.5; }
        .log ol { margin: 0; padding-left: 16px; max-height: 220px; overflow-y: auto; }
        .vacio { color: var(--tenue); font-size: 13px; font-style: italic; }
        canvas { background: #f4f2ea; border-radius: 6px; }
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

    <div x-data="game" x-on:keydown.window="mover($event)" class="maze-layout">
        <canvas x-ref="canvas" class="shrink-0"></canvas>

        <!-- ── Hoja del mago ─────────────────────────────────────────── -->
        <div class="col">
            <div class="caja" x-show="talisman">
                <h3>El mago</h3>
                <h2 style="margin-top:10px">Vida</h2>
                <div class="barra-cont"><div class="barra vida" :style="`width:${(talisman.vida / talisman.vidaMax) * 100}%`"></div></div>
                <div class="valor" x-text="`${talisman.vida} / ${talisman.vidaMax}`"></div>

                <h2 style="margin-top:12px">Talismán — poder</h2>
                <div class="barra-cont"><div class="barra poder" :style="`width:${(poderActual() / (capEnUso() || 1)) * 100}%`"></div></div>
                <div class="valor" x-text="`${poderActual()} / ${capEnUso()}`"></div>

                <div class="stat-grid">
                    <div class="stat">cap<b x-text="`${capEnUso()}/${talisman.cap}`"></b></div>
                    <div class="stat">esencia<b x-text="talisman.esencia"></b></div>
                    <div class="stat">bichos<b x-text="talisman.bichosCaidos"></b></div>
                    <div class="stat">gemas<b x-text="talisman.gemasJuntadas"></b></div>
                </div>
            </div>

            <div class="caja" x-show="talisman">
                <h2>Gemas fieldeadas</h2>
                <template x-for="g in fieldeadas()" :key="g.id">
                    <div class="gema" :class="[g.elemento, g.esencia === 0 ? 'inerte' : '']">
                        <div class="cab"><span class="nom" x-text="g.elemento"></span><span class="valor" x-text="`nivel ${g.nivel}`"></span></div>
                        <div class="barra-cont"><div class="barra esencia" :style="`width:${anchoEsencia(g)}%`"></div></div>
                        <div class="valor" x-text="`esencia ${g.esencia}${g.esencia === 0 ? ' — inerte' : ''}`"></div>
                        <div class="acciones" x-show="combate && combate.turno === 'tuTurno'">
                            <button class="ataque" @click="atacar(g.id)">atacar</button>
                        </div>
                        <div class="acciones" x-show="combate && combate.turno === 'defensa'">
                            <button @click="bloquear(g.id)" :disabled="g.esencia === 0">bloquear</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="caja" x-show="talisman && inventario().length">
                <h2>Inventario</h2>
                <template x-for="g in inventario()" :key="g.id">
                    <div class="gema" :class="[g.elemento, g.esencia === 0 ? 'inerte' : '']">
                        <div class="cab"><span class="nom" x-text="g.elemento"></span><span class="valor" x-text="`nivel ${g.nivel}`"></span></div>
                        <div class="valor" x-text="`esencia ${g.esencia}`"></div>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Combate + partida ─────────────────────────────────────── -->
        <div class="col">
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
                            <button class="ataque" @click="comer()" x-text="`comer — ${combate.entrante ? combate.entrante.dano : ''} a la vida`"></button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Resultado + botín -->
            <div class="caja" x-show="resultado" x-cloak>
                <div class="final" :class="resultado">
                    <div class="titulo" x-text="resultado === 'victoria' ? '¡Victoria!' : 'Derrota'"></div>
                </div>
                <div class="drop" x-show="drop">
                    <div style="font-weight:600;margin-bottom:6px" x-text="drop ? `Botín: gema de ${drop.elemento} nivel ${drop.nivel}` : ''"></div>
                    <div class="valor">Quedó en tu inventario.</div>
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

            <!-- Bitácora de combate -->
            <div class="caja log" x-show="logCombate.length" x-cloak>
                <h2>Combate</h2>
                <ol><template x-for="(l, i) in logCombate" :key="i"><li x-text="l"></li></template></ol>
            </div>
        </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
