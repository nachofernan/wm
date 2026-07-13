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
            /* fuego rojo-anaranjado y tierra marrón apagado: antes eran dos naranjas
               casi iguales y no se distinguían ni uno al lado del otro. */
            --fuego: #d94e1f; --agua: #4aa8e0; --tierra: #8c6a3f; --aire: #b8e04a;
            --fuego-rgb: 217, 78, 31; --agua-rgb: 74, 168, 224;
            --tierra-rgb: 140, 106, 63; --aire-rgb: 184, 224, 74;
            --vida: #e0554f; --poder: #6f8fe0; --esencia: #55b088;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--texto); font-family: system-ui, sans-serif; }
        /* stretch: la columna 3 (rueda/combate/inventario) se estira a la altura
           del mapa, que suele ser la fila más alta. El mapa y la columna del mago
           optan afuera (flex-start) para no deformarse ni dejar aire de más. */
        .maze-layout { display: flex; align-items: stretch; gap: 16px; padding: 16px; }
        .caja { background: var(--caja); border: 1px solid var(--linea); border-radius: 10px; padding: 14px; }
        .col { display: flex; flex-direction: column; gap: 14px; width: 360px; }
        .col.gemas { width: 410px; align-self: flex-start; } /* la columna que más crece: se le da aire */
        .hoja-cab { display: flex; justify-content: space-between; align-items: center; }
        .badge-esencia { font-size: 12px; font-weight: 600; color: var(--esencia); background: var(--caja2); border: 1px solid var(--linea); border-radius: 6px; padding: 3px 8px; }
        h2 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tenue); }
        h3 { margin: 0 0 4px; font-size: 16px; }
        .barra-cont { background: #100f14; border-radius: 5px; height: 14px; overflow: hidden; margin: 3px 0; }
        .barra { height: 100%; transition: width 0.2s; }
        .barra.vida { background: var(--vida); } .barra.poder { background: var(--poder); } .barra.esencia { background: var(--esencia); }
        .valor { font-size: 12px; color: var(--tenue); }
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px 12px; margin-top: 8px; }
        .stat { font-size: 12px; } .stat b { font-size: 14px; display: block; }
        .hoja-vida { margin-top: 8px; }
        .hoja-vida-cab { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--tenue); }
        .hoja-vida-cab b { font-size: 14px; color: var(--texto); }
        .hoja-vida-valor { display: flex; align-items: center; gap: 8px; }
        .hoja-vida .barra-cont { height: 8px; margin-top: 3px; }
        .stat-acc { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
        .stat-acc b { font-size: 14px; }
        .gema { position: relative; overflow: hidden; border: 1px solid var(--linea); border-left: 3px solid var(--linea); border-radius: 6px; padding: 7px 9px; margin-bottom: 7px; background: var(--caja2); }
        .gema > * { position: relative; z-index: 1; }
        .gema.fuego { border-left-color: var(--fuego); } .gema.agua { border-left-color: var(--agua); }
        .gema.tierra { border-left-color: var(--tierra); } .gema.aire { border-left-color: var(--aire); }
        .gema.inerte { opacity: 0.55; }
        /* Esfumado decorativo con el color del elemento: en el talismán se apaga
           hacia la derecha (la gema "sale" para ese lado); en el inventario es al
           revés, el color queda del lado derecho (de donde "entra" al talismán). */
        .gema.mini.fuego { background: linear-gradient(to right, rgba(var(--fuego-rgb), 0.32), var(--caja2) 65%); }
        .gema.mini.agua { background: linear-gradient(to right, rgba(var(--agua-rgb), 0.32), var(--caja2) 65%); }
        .gema.mini.tierra { background: linear-gradient(to right, rgba(var(--tierra-rgb), 0.32), var(--caja2) 65%); }
        .gema.mini.aire { background: linear-gradient(to right, rgba(var(--aire-rgb), 0.32), var(--caja2) 65%); }
        .gema.fila.fuego { background: linear-gradient(to left, rgba(var(--fuego-rgb), 0.32), var(--caja2) 65%); }
        .gema.fila.agua { background: linear-gradient(to left, rgba(var(--agua-rgb), 0.32), var(--caja2) 65%); }
        .gema.fila.tierra { background: linear-gradient(to left, rgba(var(--tierra-rgb), 0.32), var(--caja2) 65%); }
        .gema.fila.aire { background: linear-gradient(to left, rgba(var(--aire-rgb), 0.32), var(--caja2) 65%); }
        /* Un poco más de aire que en combate, para que el card no cambie tanto de
           tamaño al entrar/salir de combate (ahí suma la línea de acción-info). */
        .gema.mini { padding: 9px 10px; margin-bottom: 6px; }
        .gema.mini .cab { margin-bottom: 5px; }
        /* En combate, la gema entera es la acción: un velo rojo/gris/verde tapa el
           esfumado elemental de abajo — el matchup se lee sin texto de más. */
        .gema.accionable { cursor: pointer; transition: filter 0.1s ease; }
        .gema.accionable:hover { filter: brightness(1.12); }
        .gema.accionable:active { filter: brightness(0.92); }
        .gema.accionable.inactivo { cursor: not-allowed; }
        .gema.accionable::before { content: ''; position: absolute; inset: 0; z-index: 0; pointer-events: none; }
        .gema.accionable.ventaja::before { background: linear-gradient(to right, #3c8a58, #234a34); }
        .gema.accionable.neutral::before { background: linear-gradient(to right, #4a4856, #2a2933); }
        .gema.accionable.reves::before { background: linear-gradient(to right, #9c4444, #5a2626); }
        .gema.accionable.inactivo::before { background: linear-gradient(to right, rgba(10, 10, 14, 0.85), rgba(10, 10, 14, 0.65)); }
        .accion-info { margin-top: 5px; font-size: 12.5px; font-weight: 600; text-align: center; }
        .barra-cont.slim { height: 6px; margin: 4px 0 0; }
        .esencia-num { font-size: 12px; color: var(--esencia); font-weight: 600; }
        .punto { display: inline-block; width: 8px; height: 8px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
        .punto.fuego { background: var(--fuego); } .punto.agua { background: var(--agua); }
        .punto.tierra { background: var(--tierra); } .punto.aire { background: var(--aire); }
        /* filas compactas del inventario */
        .inv-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .orden { background: var(--caja2); color: var(--texto); border: 1px solid var(--linea); border-radius: 6px; padding: 3px 6px; font-size: 12px; font-family: inherit; }
        .orden-cont { display: flex; gap: 4px; align-items: center; }
        .gema.mini[draggable] { cursor: grab; }
        .gema.mini.arrastrando { opacity: 0.35; }
        .filtros { display: flex; gap: 5px; margin-bottom: 8px; flex-wrap: wrap; }
        .chip { padding: 3px 8px; font-size: 12px; display: flex; align-items: center; gap: 3px; }
        .chip.on { border-color: var(--texto); background: #33323d; }
        .gema.fila { display: flex; align-items: center; gap: 8px; padding: 5px 9px; margin-bottom: 4px; border-left-width: 3px; }
        .gema.fila .nom { flex: 1; font-size: 13px; text-transform: capitalize; white-space: nowrap; }
        .gema.fila .esc { color: var(--esencia); }
        .acciones-fila { display: flex; gap: 4px; margin: 0; }
        .mini-btn { padding: 3px 8px; font-size: 12px; line-height: 1; }
        /* Flechas de mover gema entre talismán e inventario: el mismo triángulo,
           girado a un lado u otro (← hacia el talismán, → hacia el inventario),
           para que se entienda de un vistazo qué hace cada una sin leer texto. */
        .icon-btn { padding: 3px 8px; font-size: 12px; line-height: 1; }
        .icon-flecha { display: inline-block; }
        .icon-flecha.der { transform: rotate(90deg); }
        .icon-flecha.izq { transform: rotate(-90deg); }
        .gema.fila .icon-btn { margin-right: 2px; }
        .cab-der { display: flex; align-items: center; gap: 6px; }
        .gema .cab { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
        .gema .nom { font-weight: 600; text-transform: capitalize; }
        button { cursor: pointer; border: 1px solid var(--linea); background: var(--caja2); color: var(--texto); border-radius: 6px; padding: 6px 11px; font-size: 13px; font-family: inherit; }
        button:hover:not(:disabled) { border-color: var(--tenue); }
        button:disabled { opacity: 0.35; cursor: not-allowed; }
        button.primario { background: #33507a; border-color: #45688f; }
        button.fus.on { background: #3d3457; border-color: #6a5a94; color: #cbbdf0; } /* gema elegida para fusionar */
        /* Botón esperando al servidor (023): oculta el texto y gira un spinner. */
        button.enviando { color: transparent !important; position: relative; pointer-events: none; }
        /* Mismo spinner, pero para cuando la gema entera (no un botón) es la acción. */
        .gema.enviando { pointer-events: none; }
        .gema.enviando > * { visibility: hidden; }
        .gema.enviando::after {
            content: ''; position: absolute; inset: 0; margin: auto; z-index: 2;
            width: 16px; height: 16px; border: 2px solid var(--texto);
            border-right-color: transparent; border-radius: 50%; animation: giro 0.6s linear infinite;
        }
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
        /* Panel de datos de celda (dev): cuelga de la caja de la rueda. */
        .celda-panel { margin-top: 11px; border-top: 1px solid var(--linea); padding-top: 9px; }
        .celda-cab { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tenue); margin-bottom: 6px; }
        .celda-datos { display: flex; align-items: center; gap: 10px; font-size: 13px; flex-wrap: wrap; }
        .celda-datos .nom { text-transform: capitalize; display: inline-flex; align-items: center; gap: 4px; }
        .celda-tipo { padding: 2px 8px; border-radius: 5px; text-transform: capitalize; font-weight: 600; background: var(--caja2); border: 1px solid var(--linea); color: var(--tenue); }
        .celda-tipo.entrada, .celda-tipo.salida { color: #7fd18f; border-color: #3c8a58; }
        .celda-tipo.puerta { color: gold; border-color: #7a6a2a; }
        .celda-tipo.llave { color: orange; border-color: #8a5a20; }
        .celda-tipo.colmena { color: var(--vida); border-color: #7a3030; }
        .celda-poder b { color: #e0a94f; } /* poder x(1+t) por distancia (027) */
        /* Corazón de vida (♥) coloreado; los ♥ dentro de botones heredan el color del botón. */
        .ico-vida { color: var(--vida); }
        /* Botón de subir nivel cuando ya alcanza la esencia: blanco con glow que late. */
        button.nivel-listo:not(:disabled) { color: #fff; border-color: var(--esencia); animation: pulso-nivel 1.7s ease-in-out infinite; }
        @keyframes pulso-nivel { 0%, 100% { box-shadow: 0 0 5px rgba(85, 176, 136, 0.35); } 50% { box-shadow: 0 0 12px rgba(85, 176, 136, 0.85); } }
        .telegrafia { border: 1px dashed #7a6f44; background: #26230f; border-radius: 8px; padding: 9px 11px; font-size: 13px; margin: 10px 0; }
        .entrante { border: 1px solid var(--vida); background: #2a1414; border-radius: 8px; padding: 11px; margin-top: 10px; }
        .drop { border: 1px solid #d8c24a; background: #29260f; border-radius: 8px; padding: 12px; margin-top: 12px; }
        .final { text-align: center; padding: 14px 0; } .final .titulo { font-size: 22px; font-weight: 700; }
        .final.victoria .titulo { color: var(--esencia); } .final.derrota .titulo { color: var(--vida); }
        .vacio { color: var(--tenue); font-size: 13px; font-style: italic; }
        canvas { background: #f4f2ea; border-radius: 6px; display: block; }
        /* El mapa y su overlay de combate: el bicho flota sobre el mapa ocioso
           (no se camina en combate) en vez de abrir un card que corre el layout. */
        .mapa-wrap { position: relative; align-self: flex-start; }
        .combate-flotante {
            position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%);
            width: min(88%, 340px); background: rgba(22, 14, 14, 0.93);
            border: 1px solid var(--vida); border-radius: 10px; padding: 12px 14px;
            box-shadow: 0 6px 22px rgba(0, 0, 0, 0.55);
        }
        .combate-flotante h3 { margin: 0 0 8px; }
        .mini-btn.recarga { color: var(--esencia); }
        .mini-btn.recarga:disabled { color: var(--tenue); opacity: 0.5; }
        .inv-vacio { color: var(--tenue); font-size: 13px; font-style: italic; padding: 6px 2px; }
        .pie { display: flex; gap: 16px; padding: 0 16px 16px; }
        .consola {
            flex: 1; background: #0d0c11; border: 1px solid var(--linea);
            border-radius: 8px; padding: 10px 14px;
            font-family: ui-monospace, monospace; font-size: 12.5px; line-height: 1.6;
            height: 160px; overflow-y: auto; color: #a9d5b0;
        }
        .consola .linea { white-space: pre-wrap; }
        .consola .linea.combate { color: #e0c04a; }
        .caja.juego { width: 300px; flex-shrink: 0; }
        /* Inventario: la card crece con la columna (stretch) y solo la lista
           interna scrollea, no la card entera. */
        .inv-caja { display: flex; flex-direction: column; flex: 1; min-height: 0; }
        .inv-lista { flex: 1; min-height: 80px; overflow-y: auto; padding-right: 2px; }
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
        <!-- El mapa y, flotando encima, el combate: como no se puede caminar con un
             bicho abierto, la pelea se dibuja sobre el mapa ocioso en vez de abrir
             un card nuevo en la columna (que corría el layout). -->
        <div class="mapa-wrap shrink-0">
            <canvas x-ref="canvas"></canvas>

            <div class="combate-flotante" x-show="combate" x-cloak>
                <h3 x-text="combate ? combate.monstruo.nombre : ''"></h3>
                <template x-if="combate">
                    <div>
                        <div class="barra-cont"><div class="barra vida" :style="`width:${(combate.monstruo.vida / combate.monstruo.vidaMax) * 100}%`"></div></div>
                        <div class="valor" x-text="`${combate.monstruo.vida} / ${combate.monstruo.vidaMax} — ${combate.monstruo.elemento}, def ${combate.monstruo.defensa}`"></div>

                        <div class="telegrafia" x-show="combate.turno === 'tuTurno'">
                            Anticipás su golpe: <b x-text="combate.monstruo.elemento"></b> nivel <span x-text="combate.monstruo.nivelAtaque"></span>, peso <span x-text="combate.monstruo.peso"></span>.
                            Atacá con una gema, o guardá carga para bloquear.
                        </div>

                        <div class="entrante" x-show="combate.turno === 'defensa' && combate.entrante">
                            <div style="font-weight:600;margin-bottom:6px" x-text="`Golpe entrante: ${combate.entrante ? combate.entrante.dano : ''} (${combate.entrante ? combate.entrante.elemento : ''})${combate.entrante && combate.entrante.critico ? ' ¡CRÍTICO!' : ''}`"></div>
                            <div class="valor" style="margin-bottom:8px">Bloqueá con una gema (barato con el elemento que le gana) o comé el golpe.</div>
                            <button class="ataque" @click="comer()" :class="{ enviando: accionActiva === 'comer-' }" :disabled="cargando" x-text="`comer — ${combate.entrante ? combate.entrante.dano : ''} ♥`"></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Mago + gemas: la columna que más crece ────────────────── -->
        <div class="col gemas">
            <div class="caja hoja" x-show="talisman">
                <div class="hoja-cab">
                    <h3 style="margin:0">El mago</h3>
                    <span class="sync" x-show="cargando" x-cloak>sincronizando…</span>
                    <span class="badge-esencia" x-show="!cargando" x-text="`${talisman.esencia} ✦`" title="esencia disponible"></span>
                </div>
                <div class="hoja-vida">
                    <div class="hoja-vida-cab">
                        <span><span class="ico-vida">♥</span> vida</span>
                        <span class="hoja-vida-valor">
                            <b x-text="`${talisman.vida}/${talisman.vidaMax}`"></b>
                            <button class="mini-btn" x-show="!combate && !cargando && talisman.vida < talisman.vidaMax" @click="curar()" :class="{ enviando: accionActiva === 'curar-' }"
                                :disabled="cargando || talisman.esencia < 1"
                                title="convertir esencia en vida (1:1)" x-text="`curar +${cuantoCura()} ♥`"></button>
                        </span>
                    </div>
                    <div class="barra-cont"><div class="barra vida" :style="`width:${(talisman.vida / talisman.vidaMax) * 100}%`"></div></div>
                </div>

                <div class="stat-grid">
                    <div class="stat">
                        nivel
                        <div class="stat-acc">
                            <b x-text="talisman.nivel"></b>
                            <button class="mini-btn" x-show="!combate && !cargando" @click="subirNivel()"
                                :class="{ enviando: accionActiva === 'subirNivel-', 'nivel-listo': talisman.esencia >= costoNivel() }"
                                :disabled="cargando || talisman.esencia < costoNivel()"
                                :title="talisman.esencia >= costoNivel() ? `subir nivel del talismán (cuesta ${costoNivel()} ✦)` : `te faltan ${costoNivel() - talisman.esencia} ✦ para subir de nivel`"
                                x-text="talisman.esencia >= costoNivel() ? `Subir nivel · −${costoNivel()} ✦` : `${talisman.esencia}/${costoNivel()} ✦`"></button>
                        </div>
                    </div>
                    <div class="stat">cap<b x-text="`${capEnUso()}/${talisman.cap}`"></b></div>
                    <div class="stat">ataque<b x-text="`+${Math.round(talisman.ataqueMult * 100)}%`"></b></div>
                    <div class="stat">defensa<b x-text="talisman.defensa"></b></div>
                </div>
            </div>

            <div class="caja" x-show="talisman">
                <div class="inv-head">
                    <h2 style="margin:0">Talismán <span class="valor" x-text="`(${fieldeadas().length}/6 ranuras)`"></span></h2>
                    <div class="orden-cont">
                        <button class="mini-btn" @click="vaciar()" :class="{ enviando: accionActiva === 'vaciar-' }"
                            :disabled="cargando || combate || !fieldeadas().length" title="mandar todas las gemas al inventario">vaciar</button>
                        <select x-model="ordenField" @change="reordenarField()" class="orden">
                            <option value="nivel">↓ nivel</option>
                            <option value="carga">↓ carga</option>
                            <option value="elemento">↓ tipo</option>
                        </select>
                        <button class="mini-btn" @click="reordenarField()" title="reordenar ahora">↻</button>
                    </div>
                </div>
                <template x-for="g in fieldeadasMostradas()" :key="g.id">
                    <!-- En combate, la gema entera es el botón de acción: el color de
                         fondo (rojo/gris/verde) dice el matchup sin texto de más — se
                         superpone al esfumado elemental de la propia gema. -->
                    <div class="gema mini"
                        :draggable="!combate"
                        @dragstart="iniciarArrastre(g.id)" @dragend="terminarArrastre()"
                        @dragover.prevent @drop.prevent="reordenarManual(arrastrando, g.id); terminarArrastre()"
                        @click="!cargando && combate && combate.turno === 'tuTurno' && atacar(g.id); !cargando && combate && combate.turno === 'defensa' && g.carga > 0 && bloquear(g.id)"
                        :class="[g.elemento, g.carga === 0 ? 'inerte' : '', arrastrando === g.id ? 'arrastrando' : '',
                            combate && combate.turno === 'tuTurno' ? `accionable ${matchupAtaque(g)}` : '',
                            combate && combate.turno === 'defensa' ? `accionable ${matchupBloqueo(g)} ${g.carga === 0 ? 'inactivo' : ''}` : '',
                            (accionActiva === `atacar-${g.id}` || accionActiva === `bloquear-${g.id}`) ? 'enviando' : '']">
                        <div class="cab">
                            <span class="nom"><span class="punto" :class="g.elemento"></span><span x-text="g.elemento"></span> <span class="valor" x-text="`n${g.nivel}`"></span></span>
                            <div class="cab-der">
                                <span class="esencia-num" x-text="g.carga === 0 ? 'inerte' : `${g.carga}/${cargaMax(g)} ⚡`"></span>
                                <button class="mini-btn recarga" x-show="!combate" @click.stop="recargar(g.id)" :class="{ enviando: accionActiva === `recargar-${g.id}` }"
                                        :disabled="cargando || !puedeRecargar(g)" :title="`recargar al tope (−${costoRecarga(g)} ✦ esencia)`"
                                        x-text="`↻ ${costoRecarga(g)} ✦`"></button>
                                <button class="icon-btn primario" @click.stop="guardar(g.id)" :class="{ enviando: accionActiva === `guardar-${g.id}` }" :disabled="cargando || combate" title="guardar en el inventario"><span class="icon-flecha der">▲</span></button>
                            </div>
                        </div>
                        <div class="barra-cont slim"><div class="barra esencia" :style="`width:${anchoEsencia(g)}%`"></div></div>
                        <div class="accion-info" x-show="combate && combate.turno === 'tuTurno'" x-text="`~${danioEstimado(g)} dmg · ${costoAtaqueLabel(g)}`"></div>
                        <div class="accion-info" x-show="combate && combate.turno === 'defensa'" x-text="g.carga > 0 ? `Bloquear · ${costoBloqueoEstimado(g)} ⚡` : 'sin carga para bloquear'"></div>
                    </div>
                </template>
            </div>

        </div>

        <!-- ── Rueda + combate + partida ─────────────────────────────── -->
        <div class="col combate">
            <div class="caja rueda">
                <h2>Rueda elemental — quién le gana a quién</h2>
                <div class="rueda-ciclo">
                    <span class="el fuego">fuego</span><span class="fl">→</span><span class="el aire">aire</span><span class="fl">→</span><span class="el tierra">tierra</span><span class="fl">→</span><span class="el agua">agua</span><span class="fl">→</span><span class="el fuego">fuego</span>
                </div>

                <!-- Panel de datos de celda (tooling de dev, DECISIÓN 027): hoy se ve
                     todo; la visión (014) lo revelará gradual y la niebla lo tapará. -->
                <div class="celda-panel" x-show="celdaActual()" x-cloak>
                    <div class="celda-cab">celda actual <span x-text="celdaActual() ? `(${celdaActual().x}, ${celdaActual().y})` : ''"></span></div>
                    <div class="celda-datos">
                        <span class="celda-tipo" :class="celdaActual()?.tipo" x-text="celdaActual()?.tipo"></span>
                        <span class="celda-riesgo">riesgo <b x-text="celdaActual() ? `${celdaActual().prob}%` : ''"></b></span>
                        <span class="celda-dist">dist <b x-text="celdaActual()?.dist"></b></span>
                        <span class="celda-poder">poder <b x-text="celdaActual() ? `×${celdaActual().poder.toFixed(2)}` : ''"></b></span>
                        <template x-if="celdaActual()?.elem">
                            <span class="nom"><span class="punto" :class="celdaActual().elem"></span><span x-text="celdaActual().elem"></span></span>
                        </template>
                    </div>
                </div>
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

            <!-- Inventario: se estira hasta lo que le deja el resto de la fila (la
                 columna del mapa suele ser la más alta) y scrollea adentro. -->
            <div class="caja inv-caja" x-show="talisman">
                <div class="inv-head">
                    <h2 style="margin:0">Inventario (<span x-text="inventario().length"></span>)</h2>
                    <select x-model="ordenInv" class="orden">
                        <option value="nivel">↓ nivel</option>
                        <option value="carga">↓ carga</option>
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
                <div class="inv-lista">
                    <div class="inv-vacio" x-show="!inventario().length">vacío — matá bichos para juntar gemas.</div>
                    <template x-for="g in inventarioMostrado()" :key="g.id">
                        <div class="gema fila" :class="[g.elemento, g.carga === 0 ? 'inerte' : '']">
                            <button class="icon-btn primario" @click="fieldear(g.id)" :class="{ enviando: accionActiva === `fieldear-${g.id}` }" :disabled="cargando || combate || !puedeFieldear(g)" title="equipar en el talismán"><span class="icon-flecha izq">▲</span></button>
                            <span class="nom"><span class="punto" :class="g.elemento"></span><span x-text="g.elemento"></span> <span class="valor" x-text="`n${g.nivel}`"></span></span>
                            <span class="valor esc" x-text="`${g.carga}/${cargaMax(g)} ⚡`"></span>
                            <div class="acciones-fila" x-show="!combate">
                                <button class="mini-btn fus" x-show="modoFusion(g) !== 'oculto'"
                                    :class="{ on: modoFusion(g) === 'seleccionada', primario: modoFusion(g) === 'objetivo' }"
                                    @click="clicFusion(g)" :disabled="cargando || (modoFusion(g) === 'objetivo' && talisman.esencia < costoFusion())"
                                    :title="modoFusion(g) === 'objetivo' ? `fusionar estas dos en una n${g.nivel + 1} · cuesta ${costoFusion()} ✦${talisman.esencia < costoFusion() ? ' (sin esencia)' : ''}` : (modoFusion(g) === 'seleccionada' ? 'cancelar fusión' : 'fusionar: elegí el par')"
                                    x-text="modoFusion(g) === 'objetivo' ? `⚗ fusionar · ${costoFusion()} ✦` : '⚗'"></button>
                                <button class="mini-btn" @click="desguazar(g.id)" :class="{ enviando: accionActiva === `desguazar-${g.id}` }" :disabled="cargando" :title="`desguazar (+${g.nivel} ✦ esencia)`" x-text="`+${g.nivel} ✦`"></button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Consola + estado de la partida ───────────────────────────── -->
    <div class="pie">
        <div class="consola" x-ref="consolaBox" x-effect="consola.length; $nextTick(() => { if ($refs.consolaBox) $refs.consolaBox.scrollTop = $refs.consolaBox.scrollHeight; })">
            <template x-for="(l, i) in consola" :key="i">
                <div class="linea" :class="l.includes('⚔') || l.includes('daño') || l.includes('bloqueás') || l.includes('cae') ? 'combate' : ''" x-text="l"></div>
            </template>
        </div>
        <div class="caja juego">
            <div class="stat-grid">
                <div class="stat">bichos<b x-text="talisman ? talisman.bichosCaidos : 0"></b></div>
                <div class="stat">gemas<b x-text="talisman ? talisman.gemasJuntadas : 0"></b></div>
            </div>
            <div class="valor" style="margin-top:10px">seed: <span x-text="seed"></span></div>
            <p x-show="terminado" style="font-weight:700;margin:6px 0" x-text="resultado === 'derrota' ? 'el mago cayó' : 'laberinto finalizado'"></p>
            <a href="{{ route('jugar.crear') }}" style="display:inline-block;margin-top:6px"><button>nueva partida</button></a>
        </div>
    </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
