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

- [ ] `app/Game/Prng.php` — PRNG determinista, según el protocolo
- [ ] `resources/js/prng.js` — espejo exacto del anterior
- [ ] Test unitario del PRNG: misma secuencia desde el mismo seed, en ambos lenguajes
- [ ] `app/Game/MazeGenerator.php` — backtracking recursivo desde un seed
- [ ] `resources/js/maze.js` — espejo exacto
- [ ] **Test de paridad**: mismo seed → mismo hash de laberinto en PHP y JS, sobre un set
      de seeds fijos y commiteados. El test más importante del repo.
- [ ] Comando Artisan para volcar el hash de un seed offline (herramienta de validación)

## Fase 2 — El laberinto se ve

- [ ] Endpoint que entrega un seed (o token de partida) al cliente
- [ ] `resources/js/game.js` — regenera el laberinto desde el seed y lo mantiene en memoria
- [ ] Render sobre `<canvas>`
- [ ] Movimiento local del mago (sin tocar el servidor)
- [ ] Radio de visión / niebla

## Fase 3 — Estado de partida y eventos

- [ ] Migración `runs` (proyección del estado) y `events` (log append-only)
- [ ] Modelos `Run` y `Event`
- [ ] Derivar estado desde el replay de eventos
- [ ] Endpoints de eventos: abrir cofre, combate, hechizo, salir
- [ ] Validación de legalidad en el servidor (¿podía estar ahí?, ¿la celda tiene eso?)
- [ ] Tests de endpoints contra intentos ilegales, no solo el camino feliz

## Fase 4 — El juego como juego

Depende de decisiones de diseño todavía abiertas (`docs/DISENO.md`). No se codea hasta
que la mecánica esté cerrada.

- [ ] Economía del talismán (poder = vida)
- [ ] Reglas de combate y costos (`app/Game/Rules.php`)
- [ ] Comportamiento de monstruos
- [ ] Contenido de cofres, llave, salida
- [ ] Condición de victoria: salir *con algo*

## Fase 5 — Terminación

- [ ] Persistencia de partida por token en URL
- [ ] Pantalla de fin (muerte / salida)
- [ ] Pulido de UI y feedback

---

Las fases 4 y 5 se van a reordenar y detallar a medida que el diseño cierre. Las fases 0–3
son estructurales y su orden es firme.
