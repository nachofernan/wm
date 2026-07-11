# Roadmap — Wizard's Maze

Fases en orden. Se tachan a medida que se cierran. El orden no es negociable en un punto:
**el generador y su test de paridad vienen antes que cualquier pixel.** Todo lo que está
abajo de la Fase 1 asume que la paridad PHP/JS está en verde.

Leyenda: `[ ]` pendiente · `[~]` en curso · `[x]` cerrado.

---

## Fase 0 — Andamiaje

- [x] Reglas del proyecto (`CLAUDE.md`)
- [x] README con el problema, la idea y el stack
- [x] Documentos vivos creados (`docs/`, `ROADMAP.md`)
- [x] Cerrar el protocolo del generador (`docs/PROTOCOLO_GENERADOR.md`): PRNG y algoritmo
      elegidos y firmados. **Bloquea la Fase 1.**

## Fase 1 — El generador y su paridad (el corazón)

Nada de lo de abajo empieza hasta que esto esté en verde.

- [x] `app/Game/Prng.php` — PRNG determinista, según el protocolo
- [x] `resources/js/prng.js` — espejo exacto del anterior
- [x] Test unitario del PRNG: misma secuencia desde el mismo seed, en ambos lenguajes
- [x] `app/Game/MazeGenerator.php` — backtracking recursivo desde un seed
- [x] `resources/js/maze.js` — espejo exacto
- [x] **Test de paridad**: mismo seed → mismo hash de laberinto en PHP y JS, sobre un set
      de seeds fijos y commiteados. El test más importante del repo.
- [x] Comando Artisan para volcar el hash de un seed offline (herramienta de validación)

## Fase 2 — El laberinto se ve

- [x] Endpoint que entrega un seed (o token de partida) al cliente
- [x] `resources/js/game.js` — regenera el laberinto desde el seed y lo mantiene en memoria
- [x] Render sobre `<canvas>`
- [x] Movimiento local del mago (sin tocar el servidor)
- [x] Campo de encuentros por celda (`EncuentroBuilder` + espejo JS + paridad), pintado
      sobre el canvas — reemplaza el spawn provisional `1/20` (016)
- [ ] Radio de visión / niebla

## Fase 3 — Estado de partida y eventos

- [x] Migración `runs` (proyección del estado) y `events` (log append-only)
- [x] Modelos `Run` y `Event`
- [ ] Derivar estado desde el replay de eventos (hoy `salir` actualiza la
      proyección directo; falta generalizar cuando haya más de un evento)
- [~] Endpoints de eventos: `salir` — abrir cofre, combate y hechizo esperan
      a que cierre la economía del talismán (Fase 4)
- [x] Validación de legalidad en el servidor para `salir` (posición vs. seed)
- [x] Tests de endpoints contra intentos ilegales, no solo el camino feliz

## Fase 4 — El juego como juego

Depende de decisiones de diseño todavía abiertas (`docs/DISENO.md`). No se codea hasta
que la mecánica esté cerrada.

- [x] Economía del talismán (hoja persistida, 018; poder = vida extirpado, 013)
      + gestión in-run: fieldear/guardar/desguazar→esencia→cap entre peleas
      (`Talisman`). Faltan los números finos y el funguéo-% (015)
- [x] Reglas de combate y costos (`CombatResolver`, 012) + combate en el maze
      resuelto por acción en el servidor (`MazeCombate`, 018)
- [~] Comportamiento de monstruos: encuentros por celda (016) + ping con dado
      secreto (017) + combate al saltar el encuentro (018, hecho). Falta llevar
      puertas/llaves al servidor y las revividas tras derrota
- [ ] Contenido de cofres, llave, salida
- [ ] Condición de victoria: salir *con algo*

## Fase 5 — Terminación

- [ ] Persistencia de partida por token en URL
- [ ] Pantalla de fin (muerte / salida)
- [ ] Pulido de UI y feedback

---

Las fases 4 y 5 se van a reordenar y detallar a medida que el diseño cierre. Las fases 0–3
son estructurales y su orden es firme.
