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

## 009 — Generador de laberinto: empaquetado del hash y seeds de test — 2026-07-09
**Decisión:** `app/Game/MazeGenerator.php` y `resources/js/maze.js` implementan el
backtracking iterativo del protocolo, con `Prng`/`prng.js` como única fuente de
aleatoriedad. Se cierra el único punto que el protocolo dejaba sin especificar del §6: el
empaquetado de bits en bytes para el hash. Cada celda ocupa **1 byte completo**
(`(N<<3)|(E<<2)|(S<<1)|O`), sin compartir bits entre celdas. Seeds de test fijos
(commiteados en `tests/Unit/Game/MazeGeneratorTest.php` y `resources/js/maze.test.js`):
`(1,10,10)`, `(42,10,10)`, `(12345,20,15)`, `(7,100,100)` — el último al tamaño canónico.
**Por qué:** Empaquetar 2 celdas por byte ahorra espacio pero agrega una decisión más
(orden de nibbles, celda impar al final) sin beneficio real para un hash de test. 1 byte
por celda es inequívoco. El tamaño no cuadrado (20x15) y el tamaño canónico (100x100)
están incluidos para no depender solo de grids chicos y cuadrados.
**Se descartó:** Empaquetar múltiples celdas por byte.

## 010 — Vida y poder del PJ: dos pools acoplados por umbral — 2026-07-09
**Decisión:** El PJ tiene dos stats separados, cada uno con actual/máximo: **vida** y
**poder**.
- El poder es el agregado de los niveles de las cuatro gemas del talismán; de ahí salen la
  visión y los hechizos.
- La vida es un stat propio del PJ, no del talismán.
- Acoplamiento por umbral: mientras el poder es mayor a 0, la vida no se toca. Al llegar a
  poder 0, gastar de más (o permanecer en poder 0) empieza a drenar vida directamente.
- Vida en 0 = derrota. Poder en 0 impide atacar; qué significa "atacar" y cómo se resuelve
  el combate se decide junto con el diseño de los PNJs/monstruos, todavía sin empezar.
- Los números concretos (cuánto poder da cada nivel de gema, cuánto drena el turno en
  poder 0, ritmo de recuperación) quedan sin cerrar a propósito: se ajustan jugando, no
  de antemano.

**Por qué:** Reemplaza la hipótesis de "un solo pool" (talismán = vida y poder al mismo
tiempo) por dos recursos acoplados: el talismán sigue siendo la fuente de poder (y por lo
tanto de riesgo — ver más cuesta, lanzar hechizos cuesta), pero la vida no desaparece con
el talismán vacío; se drena progresivamente después, dando margen para retirarse sin morir
instantáneamente al agotar las gemas.
**Se descartó:** Un solo pool talismán=vida=poder (la hipótesis original de DISENO.md §3);
conversión directa sin umbral (cualquier sobregasto de poder se paga en vida sin un punto
de quiebre) — se prefirió el umbral porque separa con claridad "estás gastado" (poder 0) de
"estás muriendo" (perdiendo vida).

## 011 — Economía del talismán: loadout con cap, gemas que se gastan, secuencia de mazes — 2026-07-09
**Decisión:** Cierra la §2 de `DISENO.md` (qué habilidad mide el juego) y reencuadra la 010.

- **Habilidad primaria: planificación.** El jugador arma el talismán contra encuentros
  telegrafiados (decide *qué* fieldear contra lo que ve), con attrition como capa de sostén
  (gastar bien un loadout que no se recarga).
- **Talismán = loadout con cap.** El nivel del talismán es un tope sobre la **suma** de
  niveles de las gemas fieldeadas; bajo ese tope se reparte (una gema alta y frágil, o varias
  medias y equilibradas). El poder disponible es el agregado de las gemas fieldeadas.
- **Gemas con rol**, no fungibles: elementales, con ventaja de tipo frente a enemigos
  telegrafiados. Meter todo en un elemento deja desnudo en los otros (especialización frágil).
  Esto resuelve la duda abierta de §3 ("fungibles o con rol") hacia rol.
- **Se gastan, no se recargan** dentro de una vida. Cada acción (atacar, defender, moverse,
  esquivar) consume carga. Una gema gastada no se rellena; una muerta no revive. El poder se
  repone consiguiendo **gemas nuevas** (drops), no descansando.
- **Combate telegrafiado:** el encuentro revela al guardián y su tipo antes de comprometerse
  (p.ej. la llave muestra al monstruo protector). La información se da, no se infiere ni se
  gana muriendo.
- **Roster y fielding:** se poseen gemas (inventario) y se fieldean pocas bajo el cap.
  Re-fieldear tiene fricción (costo por definir, p.ej. un turno). Sin fricción, el counter
  perfecto es gratis y el combate se vuelve un lookup.
- **Desguace:** una gema se puede fieldear (poder ahora) o desguazar en **esencia** para
  subir el cap. La misma gema no hace las dos cosas: chatarra → cap, premios → fieldear.
- **Curva de cap (forma cerrada, números abiertos):** sube fuerte con el nivel; el
  crecimiento real lo empujan las presas grandes (bosses, cofres), no el farmeo de morralla.
  La "morralla" es **relativa al maze**: un bicho de un maze alto puede valer más que un boss
  de tres mazes atrás. Lo que decae es el valor del contenido **pasado** relativo a tu nivel
  actual (se apaga el back-farm), no el valor absoluto de un bicho. Drops y costos escalan
  juntos con la dificultad.
- **Drops:** bosses y cofres **garantizan** la gema importante; un monstruo común tiene chance
  baja (~1 en 100) de soltar una gema grande — variance como condimento, no como plan.
- **Estructura:** secuencia de mazes de dificultad creciente (monstruos, caminos, tamaño). El
  talismán **persiste entre mazes**; se banca al **extraer vivo**, no al farmear.
- **Muerte:** resetea el maze a su estado de entrada y restaura las gemas al loadout de
  entrada de ese maze — se pierde el progreso no bankeado de la corrida. Revividas como tope
  diferido (cantidad por definir); al agotarlas, game over.

Se mantiene de la 010: vida como stat propio del PJ, acoplada al poder por umbral (mientras
hay poder la vida no se toca; sin poder, forzar drena vida; vida 0 = derrota).

**Por qué:** Da forma a la economía gruesa como una sola pieza donde cada capa mueve una
decisión distinta — carga que se gasta ("¿gasto o guardo?"), roster que se fieldea ("¿tengo el
counter y me lo puedo gastar acá?"), cap que se sube fundiendo ("¿fieldeo esta gema o la
desguazo?") — y la secuencia de mazes le da ritmo. Reencuadra la 010: el talismán deja de ser
"un pool de poder = suma de cuatro gemas que se recupera" y pasa a "un loadout con cap cuyas
gemas se gastan sin recarga y se reponen looteando".
**Se descartó:** deducción como habilidad primaria (el combate telegrafiado da la info, no la
esconde); gemas fungibles (matarían la fragilidad de la especialización y el sentido de los
roles); recarga de gemas dentro de una vida (se prefirió reponer looteando, más brutal y
coherente con la muerte que resetea); fijar los números de la curva ahora (son tuning, se
ajustan jugando — regla heredada de la 010).

## 012 — Resolución de combate: gema de dos ejes, costo por nivel, defensa por elección — 2026-07-10
**Decisión:** Cierra los números de combate que la 011 dejó abiertos y refina la 010. Es la
base que el prototipo de tuning (mago promedio vs monstruo promedio) va a exponer para
ajustar valores antes de llevarlo al maze.

- **La gema tiene dos ejes independientes:**
  - **nivel** — el *poder* de la gema. Fijo dentro de una vida. Es lo que cuenta para el cap
    del talismán (011) y no decae con el uso. Nivel 5 pega como 5 en el primer tiro y en el
    último.
  - **esencia** — la *carga* (la "carga" que la 011 ya nombraba). Se consume al atacar y al
    defender. Esencia 0 = **piedra inerte**: no ataca, no defiende, no suma a poder ni a
    visión. Una gema sin esencia es un cacho de piedra.
  Poder y aguante quedan **ortogonales**: una gema demoledora casi seca (nivel 9, esencia 2)
  o una piedrita eterna (nivel 2, esencia 30).

- **Poder actual del talismán = suma de niveles de las gemas fieldeadas con esencia > 0.**
  Reconcilia la 010: el poder no baja porque el nivel decaiga, baja porque las gemas agotadas
  salen de la suma. Poder 0 = todas las gemas fieldeadas secas.

- **Costo de atacar = el nivel de la gema, en esencia.** Nivel 3 con 30 de esencia = 10
  golpes; nivel 10 con 30 = 3 golpes. Poder alto = golpes caros = durás menos. Es la forma
  concreta de "ver más / pegar más fuerte = durar menos".

- **Daño de un golpe (ratio, nunca cero):**
  `daño = max(1, round( poder × K/(K+defensa) × mult_elemental × variación ))`, con
  `poder = nivel × F`. Ratio, no muro: el alfeñique siempre araña, nunca gana.
  - `mult_elemental` ∈ {1.5 ventaja, 1.0 neutral, 0.5 revés} según la rueda.
  - `variación` = azar en [0.85, 1.15] con crítico (p≈0.10 → ×1.75). Es mística, **no decide
    vida/muerte**: se planea contra el piso (0.85). El azar del combate usa el `Prng` del
    proyecto (mulberry32), no `Math.random`/`mt_rand`, para que la resolución sea reproducible
    en el replay de eventos (axioma 6).

- **Defensa por elección (completa o nada).** Cuando llega un golpe, el jugador elige:
  - **comer** — el daño va a la vida (pasa por el ratio con la defensa base). No gasta esencia.
  - **bloquear con una gema** — anula el golpe entero y gasta esencia de esa gema:
    `costo_bloqueo = max(1, round( peso × factor_def ))`, con `peso` del ataque (1..3) y
    `factor_def` = 0.5 ventaja / 1.0 neutral / 2.0 revés (elemento de la gema vs elemento del
    ataque). Sin esa esencia, no se puede bloquear con esa gema. Un ataque pesado bloqueado con
    el elemento equivocado funde media gema.
  La rueda gobierna ataque *y* defensa; por eso cada gema pesa el doble.

- **Gema extinta (esencia 0): un último sacudón.** Se puede castear a nivel pleno pagando
  `nivel × C` de vida, una sola vez; después la gema es piedra. Es el umbral de la 010 (poder
  0 → forzar drena vida), ahora concreto. Un solo sentido: la vida paga el hechizo, la gema no
  revive.

- **Refina la 010:** "mientras hay poder, la vida no se toca" deja de ser absoluto. El drenaje
  *forzado* al llegar a poder 0 sigue en pie, pero ahora la vida también se puede gastar *por
  decisión* (comer un golpe en vez de gastar esencia, o el último sacudón). La vida pasa de
  reloj de muerte a recurso que también se administra.

- **Números de arranque (tuning, se ajustan jugando — regla de la 010/011):** nivel 1..10,
  esencia por gema 0..~30, peso de ataque 1..3, `K`=50, `F`=3, `C`=2, crítico p=0.10 ×1.75,
  variación [0.85, 1.15]. Las escalas son **relativas**: "chico" y "grande" dependen de la
  evolución (nivel 1 es chico cuando el techo es 10; 10 es chico cuando las gemas llegan a
  100). Se fijan con el prototipo de tuning, no de antemano.

**Por qué:** La 011 dejó "todos los números" abiertos y el combate sin fórmula. Esto le da una
resolución concreta y jugable manteniendo los axiomas: ratio en vez de muro (siempre se puede
pegar), azar solo como mística (nunca decide la muerte), y la esencia como moneda única de
ataque y defensa hace que cada gema pese el doble. El costo-por-nivel ata "poder = riesgo" a un
número. La defensa por elección convierte cada golpe recibido en una decisión ("¿gasto vida o
gasto munición?") en vez de una resta pasiva.
**Se descartó:** daño sustractivo con muro (`ataque − defensa`) — concentra la varianza en el
umbral y crea paredes duras; se prefirió el ratio. Bloqueo parcial (algo se filtra a vida) — se
prefirió completo-o-nada para que la elección vida/esencia sea nítida. Poder que decae con cada
cast (la gema se debilita con el uso) — se prefirió nivel fijo + esencia que se agota (poder y
aguante ortogonales). Azar en el umbral de muerte (daño que puede matar por mala tirada) — rompe
la planificación.
