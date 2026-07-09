# Decisiones — Wizard's Maze

Bitácora **append-only** de decisiones de diseño y arquitectura. Se agrega al final. No se
reescribe el pasado: si una decisión se revierte, se agrega una entrada nueva que la
revierte y explica por qué. Cada entrada dice qué se decidió, por qué, y qué se descartó.

Formato:

```
## NNN — Título — AAAA-MM-DD
**Decisión:** …
**Por qué:** …
**Se descartó:** …
```

---

## 001 — El laberinto es una función pura del seed — 2026-07-09
**Decisión:** El laberinto no se persiste nunca. Se guarda un entero (el seed) y se
regenera con `seed → algoritmo → laberinto`. La matriz no se serializa ni viaja por la red.
**Por qué:** Es la corrección del error que hundió la versión anterior, que persistía y
recargaba la matriz completa (100x100) en cada movimiento.
**Se descartó:** Persistir la matriz, aun mandando solo el borde visible; el problema de
fondo seguía ahí.

## 002 — El generador existe dos veces con output idéntico — 2026-07-09
**Decisión:** Una implementación en PHP (autoridad) y una en JS (render), bit a bit
idénticas. PRNG determinista propio, implementado igual en los dos lenguajes. Prohibido
`mt_rand`, `rand`, `random_int`, `Math.random`, `shuffle`. Iteración con orden explícito,
nunca dependiente del orden de claves de un hash ni de `Object.keys`.
**Por qué:** El cliente regenera el laberinto localmente y el servidor valida contra su
propia regeneración. Si no son idénticos, la validación es imposible.
**Se descartó:** Generar solo en el servidor y mandar el mapa (viola la decisión 001);
usar los PRNG del lenguaje (no son reproducibles entre PHP y JS).

## 003 — Servidor autoritativo, cliente optimista — 2026-07-09
**Decisión:** El movimiento no viaja al servidor: el cliente mueve y dibuja localmente. Al
servidor solo suben eventos que importan (abrir cofre, combate, hechizo, salir). El
servidor regenera el laberinto desde el seed, valida legalidad y resuelve el resultado. El
cliente nunca decide qué había en un cofre, cuánto daño hizo un golpe, ni si un hechizo
alcanzó.
**Por qué:** Movimiento sin latencia y estado chico, sin renunciar a la autoridad sobre lo
que importa.
**Se descartó:** Confiar en el cliente para resultados (el cliente conoce el seed y por lo
tanto el laberinto entero; eso se acepta como tradeoff consciente, pero solo para
información, nunca para resolución).

## 004 — Eventos append-only, estado derivado — 2026-07-09
**Decisión:** La tabla `events` es la fuente de verdad, append-only. El estado en `runs` es
una proyección (cache) del replay. Ninguna fila de eventos se actualiza ni se borra.
**Por qué:** Auditable, reproducible, y permite reconstruir cualquier partida.
**Se descartó:** Mutar un único registro de estado por partida.

## 005 — Sin Livewire — 2026-07-09
**Decisión:** El front es Alpine.js + `fetch` contra endpoints JSON. Sin Livewire.
**Por qué:** Livewire renderiza en el servidor y difunde DOM: es, con mejor ropa, el mismo
problema que hundió la versión anterior. Decisión cerrada, no se revisa por conveniencia de
una pantalla puntual.
**Se descartó:** Livewire.

## 006 — Sin autenticación por ahora — 2026-07-09
**Decisión:** Una partida se identifica por un token opaco en la URL. Sin login.
**Por qué:** El juego es single player y sin ranking; el login no aporta nada todavía.
**Se descartó:** Auth desde el arranque (Jetstream/Breeze). Se agrega cuando algo lo pida.

## 007 — Render sobre canvas, SQLite en dev — 2026-07-09
**Decisión:** El laberinto se dibuja en `<canvas>`. La DB de desarrollo es SQLite.
**Por qué:** Un grid 100x100 en DOM no es viable. SQLite alcanza para dev; la decisión de
producción no hace falta tomarla ahora.
**Se descartó:** Grid en DOM. La DB de producción queda abierta a propósito.

---

## 008 — Protocolo del generador cerrado — 2026-07-09
**Decisión:** `docs/PROTOCOLO_GENERADOR.md` queda cerrado salvo el set de seeds de test
(que se fija junto con el test de paridad en la Fase 1). Puntos firmados:
- PRNG: mulberry32.
- `randBelow`: `next() mod n`, sin rechazo. El sesgo sobre `n ≤ 4` es despreciable para un
  juego y evita la complejidad de un loop de rechazo.
- Grid canónico 100x100, celda de inicio (0,0), tamaño siempre por parámetro.
- Hash de paridad: SHA-256 sobre el recorrido fila por fila, 4 bits (N,E,S,O) por celda.

Validado antes de cerrar con un playground descartable en `resources/views/welcome.blade.php`
(backtracking iterativo + mulberry32 + mod directo en JS, corriendo en vivo en el navegador),
que confirmó que el algoritmo y la descomposición de distancias BFS para ubicar puertas y
llaves funcionan como se esperaba. Ese playground no es la implementación real: no toca
`app/Game/`, no tiene contraparte en PHP, y se descarta al empezar la Fase 1.
**Por qué:** Desbloquea la Fase 1 (`Prng.php`, `prng.js`, test de paridad), que no puede
empezar con el protocolo abierto.
**Se descartó:** `randBelow` con rechazo (más correcto estadísticamente pero innecesario acá);
fijar ya el set de seeds de test (se pidió mantenerlos junto al test, no en este doc, y
todavía no existe el test).
