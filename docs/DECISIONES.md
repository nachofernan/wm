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

## 013 — Se extirpa "poder = vida": la atrición la carga la esencia, ver cuesta cap — 2026-07-10
**Decisión:** **Revierte** el acoplamiento vida↔poder de la 010 y el drenaje pasivo que la 011
y la 012 arrastraban. Se elimina la idea de "el talismán es simultáneamente el poder y la vida"
y su corolario "ver más / existir con poder = durar menos en vida".
- **No hay drenaje pasivo de vida.** Quedarse en poder 0, o tener el talismán cargado, no le
  saca vida al mago por el mero hecho de existir o de ver. La vida solo la amenaza el **combate**
  (comer un golpe, el último sacudón de la 012).
- **El costo de ver se muda de la vida al cap.** Visión más grande = más gema de visión
  fieldeada = **cap** que no gastás en ataque o defensa. La tensión "ver más cuesta algo" se
  mantiene, pero pagada en espacio del talismán, no en un impuesto por turno.
- **La esencia queda como el único reloj del juego.** La atrición que "poder = vida" venía a
  crear ya la carga la economía de esencia de la 012 (finita, no se recarga sola, gema en 0 =
  inerte). El drenaje pasivo era redundante.
- Se conserva de la 012: el **último sacudón** (gastar vida *por decisión* para castear una gema
  seca) y **comer un golpe** (mandar daño a la vida en vez de gastar esencia). Vida 0 = derrota
  sigue existiendo, pero se llega peleando, no por un tic pasivo.

**Por qué:** El drenaje pasivo era la parte más castigadora y de contabilidad más fina del
modelo, y en un juego indie por turnos explota de más. Con la esencia ya cargando la atrición,
"poder = vida" pasó a ser doble castigo por lo mismo. Mudar el costo de visión al cap conserva
la tensión sin bookkeeping por turno y encaja con la hoja de personaje de la 014 (visión es una
stat que las gemas potencian a costa de espacio). Nota de encuadre general de esta charla: el
juego es single-player para el autor y amigos (a lo sumo portfolio), así que el criterio pasa a
ser **fluidez antes que blindaje** — la autoridad del servidor se mantiene por corrección y
estado chico, no como muro anti-trampa (el cliente conoce el seed y eso ya se acepta, axioma 4).
**Se descartó:** el modelo "poder = vida / ver más = durar menos por vida" (010, hipótesis
original de DISENO §3) — buen lore, pero castigo redundante y fino de más una vez que la esencia
carga la atrición.

## 014 — Hoja de personaje: el mago es solo vida, el talismán es el stat block — 2026-07-10
**Decisión:** Separa al **mago** (solo tiene **vida** — la cosa que muere) del **talismán** (todo
el resto de la hoja de personaje). Las **gemas potencian** los stats del talismán.
- **Stats del talismán:** ataque, defensa, bonus crítico, visión, memoria, y lo que el juego
  vaya pidiendo. El ataque sigue siendo **gema-a-gema** (la 012 no se toca: atacás con una gema
  concreta, gastás su esencia, importa su elemento); el resto son stats pasivos.
- **Nivel del talismán = progresión maestra**, comprada con esencia pura (ver 015). Es la
  máscara de progreso "más allá de espacios para gemas": subir de nivel sube **stats base** y
  también el cap.
- **El cap deriva del nivel (escalonado, no afinable).** Nivel 1 → 10 de cap, nivel 2 → 20,
  nivel 3 → 30… (números placeholder). Entre niveles el cap no se afina punto a punto: la
  esencia de más se junta para el próximo salto. Esto **revierte** el "subir el cap punto a
  punto con esencia" de la 011 hacia una sola progresión (nivel → cap + stats base).
- **Gemas → stats con acople suelto**, no 1 a 1. Tendencia elemental (fuego~ataque, aire~visión,
  tierra~memoria, agua~defensa), pero una gema puede tocar varios stats (una de aire da visión
  *y* +10% de ataque). Fieldear una gema **nunca es solo DPS**: mueve la hoja entera.
- **Visión = revelado gradual de datos de celda**, nunca a través de muros. Escala de ejemplo:
  v1 ves tu celda; v2 la "vibra" de la contigua; v3 + porcentaje de aparición; v10 tres celdas a
  la redonda + color de relleno antes de pisar. Es previsión, no rayos X. Cuesta cap (013), y
  estructura lo que ve el **jugador honesto** (a uno que abre la consola no le esconde nada — el
  cliente conoce el seed; es el tradeoff aceptado del axioma 4, no una barrera de seguridad).
- **Números TBD a propósito** (regla mecánica-antes-que-número, heredada de 010/011/012): qué
  bumpea cada nivel, la curva de cap, los porcentajes de acople. Se rellenan jugando.

**Por qué:** Le da casa a lo que el CLAUDE.md ya prometía ("el radio de visión sale del
talismán") y hace del talismán *la* hoja de personaje: perdés/vaciás el talismán y el mago no es
nada. Una sola progresión (nivel) evita la inflación de una tercera capa de stats. El acople
suelto elemento→stat convierte "qué gemas fieldeo" en una decisión de hoja completa, no de puro
daño.
**Se descartó:** stats repartidos entre mago y talismán (el mago queda solo con vida, más
legible); un "nivel de talismán" como tercer eje aparte del cap y de las gemas (se derivó del
cap para no inflar); acople elemento→stat estricto 1:1 (se prefirió suelto, gemas multi-stat).

## 015 — Esencia única en dos estados: ligada (combate) y pura (oro) — 2026-07-10
**Decisión:** Reemplaza la idea de "dos esencias" por **una sola sustancia en dos estados**, y
cierra qué es el oro del juego.
- **Esencia ligada** — la carga dentro de una gema (la "esencia" de la 012). Se gasta atacando y
  bloqueando. Gema en 0 = piedra inerte.
- **Esencia pura (el oro)** — se obtiene **fungueando** (desguazando) una gema: rendís un
  **porcentaje de la esencia que le queda** (50% placeholder, quizás hasta el total). Se acumula
  en el talismán y **compra niveles** (014).
- **El desguace rinde sobre lo que queda**, así que cada tiro que pegás con la gema baja lo que
  vale funguearla después. **"Usarla ahora o cobrarla después"** es una decisión real.
- **Una gema en 0 no se funguea**; sacada del talismán es una **roca** muerta, sin valor.
- La esencia pura es la moneda de meta-progreso; la ligada es combustible de combate. Es la misma
  sustancia: el desguace la libera de la gema con pérdida.

**Por qué:** Unifica la economía en un solo recurso legible ("la esencia es el oro"), sin la
colisión conceptual de dos monedas que se llaman parecido. El desguace-sobre-lo-restante crea la
tensión gastar-vs-cobrar sin reglas nuevas. Cierra el loop: gema con carga → la gastás peleando →
gema casi seca → la fundís en oro (con pérdida) → subís de nivel. **Refina la 011**: el desguace
ya no va a "subir el cap punto a punto" sino a esencia pura que compra niveles (014).
**Se descartó:** dos pozos separados con nombres distintos (carga vs esencia) — se colapsaron en
uno; desguace a rendimiento fijo independiente del uso (se prefirió proporcional a lo restante,
que es lo que genera la decisión).

## 016 — Encuentros por celda, paso validado por el servidor, niebla en tres zonas — 2026-07-10
**Decisión:** Cierra cómo aparecen los encuentros en el maze y **refina el axioma 3** (la 003).
- **Probabilidad de encuentro por celda, derivada del seed.** Cada celda tiene una probabilidad
  (p.ej. 10% / 5% / 1%) que sale del generador → es **paritaria PHP/JS** y va al
  `PROTOCOLO_GENERADOR.md` con su test de paridad, porque el cliente la **pinta** y el servidor
  la usa como sesgo. Pintura: color = elemento (rojo fuego, azul agua, marrón tierra, gris aire),
  alpha = probabilidad. **Colmenas:** una celda-núcleo de alta probabilidad que **irradia y
  decae** en las vecinas (15/13/10/7…/1) y **atraviesa muros** (el peligro sangra donde vos no
  pasás); limpiar el núcleo apaga toda la zona, y el núcleo puede ser casi inalcanzable.
- **El disparo del encuentro es secreto del servidor**, no derivado del seed. Si saliera del
  seed, el cliente (que lo conoce) lo pre-calcularía y no habría sorpresa. El sesgo es público y
  se pinta; la tirada de "¿me saltó algo ahora?" la tira el server y el cliente no la predice.
- **Refinamiento del axioma 3:** el paso del mago **sí viaja al servidor** — pero solo para que
  el server valide (¿celda adyacente?, ¿no es muro?, regenerando el maze desde el seed) y tire el
  dado del encuentro. **No** sincroniza el mapa ni el estado: el laberinto sigue siendo local
  desde el seed, caminar y chocar paredes es local e instantáneo, y la latencia del ping se tapa
  con la animación del paso. Es lo contrario del pecado de la versión vieja (que recargaba la
  matriz por paso); acá viaja un JSON de dos enteros.
- **Los pasos no se persisten como eventos.** La **posición actual** y el **set de celdas
  visitadas** viven en la caché `runs` (proyección, no verdad — axioma 4/6). Solo se vuelven
  **evento inmutable** las cosas que importan: un encuentro que dispara combate, un cofre, la
  llave, la salida. Un "no encuentro" no deja rastro. Esto mantiene el log chico (axioma 5) sin
  perder la caminata.
- **Combate en el maze: resuelto por acción en el servidor.** Cada acción tuya (atacar con gema
  X, bloquear con Y, comer) viaja, el server la resuelve y devuelve el resultado (próxima acción,
  muerte del bicho). La **semilla del combate se deriva en el servidor** de `(seed de la partida,
  celda, índice del encuentro)` — **nunca** la manda el cliente (hoy los playgrounds `/pelea` y
  `/mago` la mandan desde el JS: es atajo de playground, prohibido en el juego real).
- **Niebla en tres zonas** (memoria, ver 014): **oscura** = celda no visitada; **gris** =
  visitada pero sin detalle (sabés que pasaste, no si tenía colmena, corte o bifurcación —
  bitset barato y permanente); **blanca** = radio de visión actual (aire) **+** rastro de las
  últimas N celdas (memoria/tierra, acotado y se desvanece).

**Por qué:** Reconcilia el "encuentro random" que se quería (sorpresa, "estar hecho de goma y
llorar para que no me toque nada") con los axiomas: el mapa es función pura del seed (001), pero
el dado del encuentro es secreto del server, y eso obliga al ping por paso — que no es recargar
estado, es un refinamiento chico y honesto del axioma 3 (que ya listaba "entablar combate" como
evento legítimo). Persistir posición/visitadas en la caché y solo lo-que-importa como eventos
mantiene el estado chico. La niebla gris ("no sé qué había") es el motor de la tensión de
deducción sobre el mapa.
**Se descartó:** disparo del encuentro derivado del seed (campo minado 100% predecible por el
cliente — mata la sorpresa); persistir cada paso como evento inmutable (infla el log a miles de
filas, rompe el espíritu del axioma 5); movimiento 100% local sin validación (se prefirió que el
paso constate, ahora que igual viaja para el dado del encuentro); mandar la semilla de combate
desde el cliente (se puede reintentar hasta pescar un crítico).

## 017 — Implementación del ping por paso: dado secreto con semilla guardada — 2026-07-11
**Decisión:** Implementa la 016 (Fase 4, primer escalón que une maze + encuentro + servidor).
Concreta *cómo* el dado de encuentro es a la vez secreto para el cliente y reproducible en el
servidor, sin romper el axioma 6.

- **`EncuentroBuilder`** (PHP + espejo JS + test de paridad) produce el **campo** como función
  pura del seed: por celda `{prob, elemento}`, piso de ambiente parejo más colmenas que irradian
  y decaen por anillos de Chebyshev (atraviesan muros). Es el **sesgo público**, se pinta.
- **El dado del disparo NO es `random_int`.** Cada partida guarda una `semilla_secreta` (columna
  en `runs`, sorteada al crear, **nunca viaja al cliente**). El disparo del paso *n* es
  `Prng(semilla_secreta + pasos).randBelow(100) < prob`. Así queda impredecible para el cliente
  (no conoce la semilla) pero **reproducible en el servidor**, consistente con la disciplina de
  PRNG del proyecto (nada de entropía del sistema en la lógica del juego).
- **`POST /jugar/{token}/paso`**: valida el paso (adyacencia + pared abierta, regenerando el maze
  desde el seed), actualiza la posición en la caché `runs` (`pos_x`, `pos_y`, `pasos`), tira el
  dado y, si salta, escribe un evento `encuentro` append-only. El paso en sí no se persiste.
- **El cliente ya no tira el dado.** El movimiento sigue optimista (se dibuja al instante); el
  encuentro lo decide el servidor por ping async. Se borró el `prngEncuentros` del cliente.

**Por qué:** La 016 dejó el "cómo" del secreto abierto. Una semilla guardada por partida cierra
la tensión con el axioma 6: los eventos siguen siendo la verdad y el disparo es reproducible
server-side para el replay, pero el cliente no lo predice. Es la disciplina de PRNG del proyecto
aplicada al azar oculto, en vez de meter una fuente de entropía suelta.
**Se descartó:** `random_int` para el dado (irreproducible, incoherente con el resto del azar del
juego); mandar el dado o su semilla al cliente (lo predeciría). **Pendiente (no en este paso):**
puertas/llaves en el servidor — hoy la validación del paso es adyacencia + pared (más laxa, nunca
incorrecta: no rechaza cruzar una puerta que el cliente considera cerrada); y **resolver el
combate dentro del maze** cuando el encuentro salta (el encuentro hoy solo se registra).

## 018 — Combate en el maze con ida y vuelta; hoja de personaje persistida — 2026-07-11
**Decisión:** Implementa el combate dentro del laberinto (Fase 4, cierra el loop
caminar → encuentro → pelea → botín) y trae la hoja de personaje a la partida real.

- **La pelea la resuelve el servidor, acción por acción** (concreta 016). El encuentro que
  dispara el ping (017) abre un combate: el monstruo, su vida, el daño, la muerte y el drop se
  derivan y deciden en el servidor. El cliente **no tiene ninguna verdad de combate** (axioma 4):
  manda `{accion, gemaId}` a `POST /jugar/{token}/combate` y recibe el estado nuevo.
- **`MazeCombate`** (app/Game/, puro y testeable sin DB) orquesta: deriva el monstruo de la celda
  como función del seed (arquetipo por elemento del encuentro, vida escalada por la probabilidad),
  resuelve cada turno reusando `CombatResolver` (la autoridad del daño, 012), y genera el drop al
  matarlo. La **semilla de combate** sale de `(seed, celda, índice del encuentro)` y **nunca viaja
  al cliente** — el estado que se devuelve está curado (sin semilla ni contador de pasos).
- **El talismán se persiste en la caché `runs`** (columna `talisman`): la hoja de personaje real
  (vida, cap, esencia, gemas, progreso), arrancando con el mago inicial (Fuego n5/Agua n4/Tierra
  n3). El combate activo vive en `combate` (o null). Ambos son proyección chica (axioma 5).
- **La hoja aparece en la partida** (`resources/views/jugar.blade.php`): vida, poder del talismán,
  gemas fieldeadas e inventario, siempre visibles; el combate se resuelve en un panel al lado del
  canvas. No se camina con un combate abierto ni con un botín sin cerrar.

**Por qué:** Era la meta desde el arranque de Fase 4: sentir el loop "consigo una gema, la peleo,
decido qué hago con ella". Poner toda la resolución en el servidor con semilla propia respeta el
axioma 4 (nunca confiar en el cliente para daño/muerte/botín) sin volver la pelea un ladrillo: el
movimiento sigue local, solo las acciones de combate viajan. Persistir el talismán hace que los
drops se acumulen de verdad entre encuentros.
**Se descartó:** resolver el combate en el cliente y que el server "guarde y liste" (toqueteable —
el cliente decidiría cuánto pegó o si el bicho murió); mandar la semilla de combate al cliente
(predeciría críticos). **Pendiente (no en este paso):** la gestión del talismán *durante* la
corrida (fieldear/guardar/desguazar→esencia→cap del /mago — hoy el drop cae al inventario y ahí
queda); las **revividas** (hoy la derrota termina la partida, placeholder — docs/DISENO.md §4);
puertas/llaves en el servidor (heredado de 017); la rueda elemental concreta (sigue placeholder).

## 019 — Tuning del campo de encuentros + multi-drop — 2026-07-11
**Decisión:** Ajuste de números del campo (016) y del botín, jugando.

- **Ambiente 3% → 1%** y **densidad de colmenas 1/400 → 1/250 celdas** (`EncuentroBuilder`
  + espejo JS). El piso parejo casi no dispara (la mayoría de los pasos son tranquilos) y las
  colmenas son más frecuentes y marcadas: el peligro está *localizado*, no repartido.
- **Un bicho puede soltar más de una piedra** (`MazeCombate::victoria`): la cantidad sale del
  mismo PRNG de combate (determinista, replayable) y escala con la dificultad del monstruo.
  `drop` pasa de gema única a **lista de gemas**.

**Por qué:** El ambiente al 3% ahogaba de encuentros el mapa entero; bajarlo a 1% con colmenas
más densas concentra la amenaza donde el campo la telegrafía. El multi-drop le da sentido a
buscar los bichos difíciles.
**Cascada asumida:** cambiar el campo **invalidó los seeds y hashes de paridad** — los 4 vectores
de `EncuentroBuilderTest`/`encuentroBuilder.test.js` se regeneraron y recommitearon. Es el tipo de
cambio destructivo que CLAUDE.md manda avisar. **Se descartó:** dejar el ambiente alto (mataba la
exploración tranquila); drop siempre único (no premiaba la dificultad).

## 020 — Niebla de guerra + números de combate a la vista — 2026-07-11
**Decisión:** El mapa arranca en negro y se revela caminando; el combate muestra qué va a pasar.

- **Niebla client-side** (`game.js`, `dibujarNiebla`): negro en lo no explorado, velo gris en el
  rastro ya pisado, visión total en el radio Chebyshev 1 alrededor del mago. Se trackea `visitadas`
  en el cliente (no viaja al servidor: es puro render).
- **Las marcas son faros:** entrada, salida, puertas y llaves se dibujan **por encima** de la
  niebla, visibles desde el arranque aunque no hayas llegado. Decisión explícita del usuario:
  "quiero saber a dónde tengo que ir sin saber cómo llegar". La atmósfera de ocultarlas es otra
  cosa y se verá más adelante; no ahora.
- **Preview de combate en la vista** (`danioEstimado`/`costoBloqueoEstimado`, espejo de
  `CombatResolver` con tirada media): el botón de atacar muestra `~N dmg` y el matchup (y se tiñe
  verde/gris según ventaja/revés); el de bloquear muestra el costo en esencia. Más una caja fija
  con la **rueda elemental** (quién le gana a quién). Es SOLO display — la resolución sigue en el
  servidor (axioma 4); el cliente ya conoce el seed y el campo, mostrar el matchup no le da nada
  que no tuviera.

**Por qué:** Sin niebla el mapa se ve entero y no hay exploración; los faros dan objetivo sin
regalar el camino. Los números a la vista hacen que la decisión de con qué gema pegar/bloquear sea
informada en vez de a ciegas — que es donde vive el juego del talismán.
**Se descartó:** ocultar también las llaves/puertas (se decidió mostrarlas por ahora); calcular el
daño en el servidor para el preview (el cliente ya tiene todo lo necesario; un round-trip por hover
sería absurdo).

## 021 — Pagar hechizos con vida (parcial) + curarse con esencia — 2026-07-11
**Decisión:** La vida entra en la economía de combate por los dos lados: se puede gastar para
seguir atacando sin esencia, y se puede recomprar con esencia entre peleas.

- **Pago parcial con vida al atacar** (`MazeCombate::resolver`, `CombatResolver::costoVida`):
  atacar cuesta esencia igual al nivel de la gema; si la esencia **no alcanza**, se gasta la que
  haya y **el faltante se paga con vida a 3:1** (cada punto de esencia faltante = 3 de vida). Esto
  **generaliza y reemplaza el "último sacudón"** de la 012 (gema extinta → `nivel × C`, C=2): ahora
  la gema extinta es solo el caso donde el faltante es el nivel entero (`nivel × 3`), y la gema con
  algo de esencia paga solo la diferencia. La constante `C` sale; entra `vidaPorEsencia = 3`.
- **Curar fuera de combate** (`Talisman::curar`): convierte **esencia pura → vida, 1:1**, hasta el
  tope de vida. No malgasta (sana el mínimo entre la esencia que tenés y lo que te falta) y **no se
  puede curar con un combate abierto** (el controlador ya lo bloquea, como el resto del talismán).

**Por qué:** Cierra el bucle de atrición que el diseño pedía (013: la vida se gasta *por decisión*
en combate). Pagar con vida es la salida desesperada cuando la gema correcta está seca — con
penalidad para que sea un recurso, no un plan. Curar 1:1 le da un uso a la esencia pura que compite
con subir cap: ¿progreso o supervivencia? Es la clase de decisión donde vive el juego. Números de
arranque (3:1 y 1:1), tuning.
**Cascada:** tocó el combate autoritativo (`CombatResolver`, `MazeCombate`), la gestión de talismán
(`Talisman`, validación del endpoint), el espejo de preview en JS (`game.js`: `costoVidaAtaque`,
`cuantoCura`) y la vista (costo de vida en el botón de atacar, botón `curar` en la hoja). Tests
nuevos en los tres `app/Game/`. **Se descartó:** penalidad 2:1 (se eligió 3:1, más dura); curar en
combate (quedó solo fuera, para no volver trivial el "sanar antes de comer el golpe").

## 022 — El cliente maneja el movimiento; el servidor resuelve y persiste — 2026-07-11
**Decisión (revisa los axiomas 3 y 4):** El ping por paso se elimina. Caminar y la tirada de
encuentro pasan a ser 100% del cliente; el servidor solo interviene en eventos discretos
(abrir combate, resolver combate, salir). Motivo: en un servidor chico (1 CPU, 1 GB) el
round-trip por paso, con dos regeneraciones de grid por request y el gate que serializaba el
movimiento a un-paso-por-latencia, hacía injugable caminar.

- **Movimiento local, sin server.** El cliente ya era optimista; ahora directamente no pinguea
  cada paso. Camina, actualiza la niebla, y tira el dado de encuentro él mismo (PRNG del
  proyecto sobre seed+celda+pasos). Instantáneo.
- **El dado de encuentro deja de ser secreto.** Antes vivía en el servidor (semilla_secreta)
  para que el disparo no se predijera. Se acepta perder esa sorpresa: es single player sin
  stakes, y encuentros deterministas+públicos encajan MEJOR con el pilar cerrado de
  **planificación** (DISENO §2) — ves el campo pintado y ruteás — que el dado oculto, que
  arrastraba sabor de deducción (descartada como primaria).
- **El servidor sigue siendo autoridad de combate (axioma 4 intacto donde importa).** Cuando
  el cliente decide que saltó un bicho, llama a `POST /encuentro` con la celda y los pasos; el
  servidor deriva el monstruo del seed (no confía en el cliente para eso), abre el combate y lo
  persiste. Atacar/bloquear/comer siguen resolviéndose en el servidor. Lo que se resigna es la
  validación de legalidad *por paso* (axioma 3): la posición se cree salvo chequeos de borde
  (dentro del grid, celda con riesgo). Tradeoff consciente: sin stakes, un cliente toqueteado
  solo se hace trampa a sí mismo.
- **Persistencia lazy.** La posición se guarda al abrir combate y al salir; entre peleas no.
  Cerrar la pestaña reanuda en la última pelea. La niebla (visitadas) queda client-side por
  ahora (DISENO §3 la quiere persistida; queda pendiente, no bloquea).

**Cascada:** desaparece `POST /paso` (y `pasoLegal`/`tirarEncuentro`); nace `POST /encuentro`.
`semilla_secreta` queda vestigial. Tests de paso reescritos a encuentro. Cliente: se saca el
ping por paso, entra la tirada local y `abrirCombate`.
**Se descartó:** C (cliente motor entero, combate incluido) — over-engineering para un juego sin
stakes, obligaba a combate en dos lenguajes con test de paridad. A (solo cachear + pipeline de
pings) — paliativo: traía encuentros tarde, rompía el "la pelea te frena acá".

## 023 — Spinner en los botones que esperan al servidor — 2026-07-11
**Decisión:** Todo botón que dispara una llamada al servidor (combate, talismán, salir) muestra
un spinner mientras la respuesta está en vuelo, y el resto de los botones se deshabilitan hasta
que vuelve. **Por qué:** en el servidor chico las llamadas discretas que quedan (combate) igual
tardan; sin feedback el jugador clickea dos veces o cree que se colgó. Un `cargando` global
bloquea acciones concurrentes y `accionActiva` marca cuál botón gira.

## 024 — La hoja de personaje se construye: nivel del talismán + acople gema→stat (modelo A) — 2026-07-12
**Decisión:** Cierra la charla que la 014 dejó dibujada pero sin construir. La 014 prometió que el
talismán es la hoja de personaje (ataque, defensa, visión, memoria…) potenciada por las gemas, pero
el código nunca lo implementó: el ataque era gema-a-gema puro (012) y la defensa una constante (8)
que las gemas no tocaban. Se cierra **cómo** se arma la hoja, en dos ejes:

- **La espina — nivel del talismán como progresión maestra (implementa la 014, revierte el cap
  punto-a-punto de la 011).** El talismán gana un `nivel` (arranca en 1). El **cap** y los **stats
  base** se **derivan del nivel** (escalonado), no se afinan punto a punto. Subir de nivel se compra
  con esencia pura (015) y reemplaza el `subirCap`/`COSTO_CAP` de la 011 —que la 014 ya había
  revertido en diseño y el código todavía arrastraba—. `cap` y `defensa` pasan a ser **proyección
  cacheada** en el blob del talismán, recomputada tras cada mutación desde el nivel (y, con el
  roll-up, desde las gemas fieldeadas): la vista, el JS y `MazeCombate` los siguen leyendo igual.

- **El acople gema→stat es dirigido por elemento (modelo A), no por afijo por gema (modelo B).**
  Las gemas fieldeadas aportan a los stats de la hoja según su **elemento y nivel** (fuego~ataque,
  agua~defensa, aire~visión, tierra~memoria), con acople suelto (una gema puede tocar varios). El
  **ataque** se acopla como **multiplicador** sobre el daño gema-a-gema de la 012
  (`daño = nivel×F × elemental × variación × (1 + bonusAtaque)`); la **defensa** como **sumando** al
  ratio existente (`K/(K+defensa)`, que ya da los rendimientos decrecientes). Así la 012 (resolución
  por golpe) y la 014 (capa pasiva de hoja) quedan como dos capas que no chocan. Fieldear deja de ser
  "qué gemas me dan ataque + esencia bajo el cap" y pasa a ser una decisión de hoja completa.

- **Se construye en dos pasos:** (1) la espina (nivel → cap + defensa base, subir nivel con esencia);
  (2) el roll-up gema→stat sobre esa espina. La rueda elemental concreta sigue siendo tuning
  independiente (no bloquea el acople). El modelo B (afijos por gema instancia) queda como profundidad
  futura si el loop base lo pide, sin decisión tomada.

**Números de arranque (tuning, se ajustan jugando — regla 010/011/012):** `cap(nivel) = 12 + (nivel−1)×10`
(nivel 1 = 12, mantiene el mago inicial de 4×n3); `defensa(nivel) = 8 + (nivel−1)×4` (nivel 1 = 8,
mantiene el actual); subir de nivel N→N+1 cuesta `N×10` de esencia.
**Por qué:** La 014 estaba decidida y sin construir; el fielding no era una decisión de hoja porque
defensa/visión/memoria eran constantes. El modelo A entrega esa decisión con el mínimo de mecánica
(YAGNI técnico, profundidad en la mecánica). Derivar cap y stats de un solo `nivel` evita la tercera
capa de stats que la 014 ya rechazó, y mata la deuda del cap punto-a-punto. Ataque multiplicativo /
defensa aditiva caen naturalmente sobre la fórmula que ya existe.
**Se descartó:** el modelo B (afijos por gema) como arranque —es un sistema de loot completo (stat
block por gema, tabla de tiradas, UI), profundidad antes de probar el loop—; seguir con el cap
punto-a-punto (deuda de la 011 revertida por la 014); un `nivel` como tercer eje aparte del cap (se
derivó del nivel, coherente con la 014).

## 025 — Doble tope de fielding (cap + ranuras), fusionador de gemas, y las cuatro gemas potencian atk/def — 2026-07-12
**Decisión:** Tres features sobre la hoja construida en la 024, para darle vida al sistema de gemas
sin tocar la economía de fondo:

- **Doble tope de fielding: cap Y ranuras conviven.** Fieldear una gema exige ahora dos condiciones:
  que la **suma de niveles** fieldeados no supere el `cap` (011, presupuesto que crece con el nivel del
  talismán) **y** que el **conteo** de gemas fieldeadas no supere las **ranuras** (constante `RANURAS = 6`,
  fija por ahora, no escala con nivel). Con pocas gemas grandes ata el cap; con muchas chicas atan las
  ranuras; la fusión empuja de un extremo al otro. Errores distintos: `no hay ranura libre` vs
  `no entra en el cap` (la ranura se chequea primero). El cap sigue siendo el sustrato sobre el que va
  a competir la visión (013/024, paso 3): por eso NO se reemplazó por un contador de slots.

- **Fusionador.** Dos gemas **del mismo elemento y nivel** se funden en una de **nivel+1** con la
  **esencia sumada** (ej.: fuego n3 con 10 es. + fuego n3 con 2 es. = fuego n4 con 12 es.). Sin
  penalización y **sin techo de nivel** (queda abierto). Solo entre gemas **guardadas** (no fieldeadas),
  como desguazar — es manejo de loadout entre peleas; la gema resultante nace guardada con un id fresco
  (`proximoId`). Le da a una gema chica un tercer destino además de cargar/desguazar, y una decisión:
  fundir por esencia ya, o guardar para fusionar y densificar. Bajo el cap suma-de-niveles, fusionar
  **libera cap** (2×n3 suma 6 → 1×n4 suma 4) a cambio de **perder acople pasivo** (2 gemas de aporte → 1)
  y una ranura menos ocupada: tradeoff elegido, no accidente.

- **Las cuatro gemas potencian la hoja (eje ofensivo/defensivo interino).** `recomputar` acopla
  **fuego + aire → ataque** y **agua + tierra → defensa** (reusando las constantes de la 024, sin números
  nuevos). Hasta ahora aire y tierra se podían fieldear pero no aportaban nada a la hoja (solo pegaban
  gema-a-gema): quedaban como peso muerto en la decisión de loadout. **Es interino y va a cambiar:** cuando
  existan visión y memoria (024, paso 3), aire y tierra van a llevar esos stats y su aporte a atk/def
  probablemente se achique para no ser doble función.

**Cascada de stats iniciales:** al darle atk a aire y def a tierra, el mago inicial (fieldea las cuatro n3)
pasa de ataque +15% / defensa 17 a **ataque +30% / defensa 26**. Esperado; reflejado en los tests.
**Cascada técnica:** `Talisman::aplicar` gana un 4º parámetro opcional (`gemaId2`, solo lo usa fusionar);
`JugarController` valida `fusionar` y `gemaId2`; `game.js` suma el flujo de fusión de dos toques
(`fusionSel`/`modoFusion`) y el doble tope en `puedeFieldear`; la vista muestra `ranuras X/6` y un botón
de fusión por fila del inventario.
**Por qué:** las tres tocan la misma capa (loadout de gemas) y ninguna toca la economía de fondo
(esencia como vida/poder, cap como presupuesto de visión). Todas agregan o afinan una decisión del jugador
(CLAUDE.md: una feature que no cambia una decisión no se construye).
**Se descartó:** reemplazar el cap suma-de-niveles por un contador de 6 slots (mata el sustrato de la
visión); penalizar o poner techo a la fusión (por ahora sin fricción, se agrega si el loop lo pide);
darle a aire/tierra su stat definitivo (visión/memoria) ahora (no existen como stats todavía — es el paso 3).
Iniciativa/destreza → doble ataque queda aparcado hasta el manual de monstruos (depende de datos que no existen).

## 026 — "Carga" (⚡) como recurso de la gema, tope de carga N×6, y drops pesados por la rueda — 2026-07-12
**Decisión:** Dos ajustes que le dan cuerpo al sistema de gemas sin tocar la arquitectura ni la
economía de fondo:

- **La esencia interna de la gema pasa a llamarse `carga` (⚡).** Había dos recursos con el mismo
  nombre `esencia`: la del **talismán** (pura — sube nivel, cura, sale de desguazar una gema) y la de
  **adentro de la gema** (el combustible que se gasta al lanzar/bloquear). Confuso, sobre todo porque
  desguazar convierte una gema en esencia pura = su nivel, no en "la esencia que tenía". Ahora el
  recurso almacenado en la gema es **`carga`**, con signo ⚡ en la UI; `esencia` queda reservada para
  la moneda pura del talismán. Es un rename de la **clave almacenada** (`gemas[].esencia` → `gemas[].carga`)
  y del texto de jugador; **no** toca la `esencia` del talismán. Frontera deliberada: los internos del
  `CombatResolver` (`costoEsencia`, `vidaPorEsencia`) se dejan como están — son "costo medido en carga",
  no el recurso almacenado, y renombrarlos ampliaría el rename a su API testeada sin ganar claridad.

- **Tope de carga por gema: N×6.** Una gema de nivel N topea a `N×6` de carga (constante
  `Talisman::CARGA_POR_NIVEL = 6`). No es número nuevo: ya era el "lleno" de facto —las gemas iniciales
  nacen con `nivel×6`, los drops también, y la barra de la UI ya se llenaba contra `nivel×6`—. La
  **fusión** era el único lugar que podía pasarse (sumaba dos cargas sin recortar); ahora **recorta al
  tope de la gema nueva y el sobrante se pierde** (ej.: dos n3 con 15+15=30 → n4 con `min(30, 24)=24`).
  Es el techo blando que le faltaba a la fusión de la 025: fundir gemas muy cargadas cuesta carga, y ese
  costo escala. La 025 quedaba "sin techo"; esto lo acota sin poner penalización explícita.

- **Los drops se pesan por la afinidad del monstruo (rueda de `CombatResolver`).** El botín ya no sortea
  el elemento uniforme: para un monstruo de elemento E, el drop pesa **60 el mismo E**, **25 el que E
  vence**, **10 el cruzado neutral**, **5 el que vence a E** (suman 100). Ej. fuego: 60 fuego / 25 aire /
  10 tierra / 5 agua. Lee la rueda de `CombatResolver::matchup` (única fuente; `VENCE_A` sigue privada),
  con el mismo PRNG de combate (determinista, replayable). Cambia una decisión del jugador: **dónde
  farmeás importa** — una colmena rinde sobre todo su propio elemento y casi nunca el que la derrota, en
  vez de que mover izquierda-derecha en un nido de fuego te llene de agua. Además enseña la rueda por los
  drops. La estructura de la rueda ya estaba fija en código (la ficción sigue ❓ DISENO.md §3); esto no
  abre una rueda nueva, la usa.

**Cascada técnica:** rename `esencia`→`carga` en `MazeCombate` (inicial, drop, resolver, gastarGema),
`Talisman` (recomputar, fusionar) y todo el front (game.js: sort key, helpers, labels; blade: bindings,
símbolo ⚡, y desambiguación de la esencia pura que usaba "es." abreviado). `MazeCombate::drop` recibe el
elemento del monstruo y usa el nuevo `elementoDrop`. Los blobs de runs viejas de dev quedan con la clave
`esencia` en las gemas (desechables). **Sin impacto en el generador ni en el test de paridad.**
**Por qué:** el rename mata una ambigüedad real de dos monedas homónimas; el tope N×6 formaliza lo que la
UI ya asumía y le da a la fusión el techo que le faltaba; los drops pesados atacan un farmeo absurdo
(el elemento que te vence lloviendo desde su propia colmena). Ninguna toca la arquitectura.
**Se descartó:** derramar el sobrante de la fusión a esencia pura (se elige perderlo — más simple, y
evita acoplar fusión con progresión); pesos que sumaran 90 (se subió el mismo elemento a 60 para cerrar
100); renombrar los internos del `CombatResolver`; exponer `VENCE_A` en vez de reusar `matchup`.

## 027 — Dificultad y loot por distancia a la entrada; fusión con costo de esencia — 2026-07-13
**Decisión:** Cierra la charla sobre escalar dificultad y botín dentro de un mismo maze. Cuatro puntos; los
cuatro implementados (los dos primeros en una etapa Senior posterior a la misma fecha).

- **La dificultad escala con la distancia a la entrada, como rampa suave (~2x entrada→salida)
  [IMPLEMENTADO].** `t = dInicio / salida.distancia` ∈ [0,1]. El **arquetipo por elemento es el ESTILO**
  (fuego glass cannon, tierra tanque) y la **distancia es el TIER**: los stats del monstruo (vida, defensa,
  nivelAtaque) escalan por `1 + t` — ~1× en la entrada, ~2× en la salida ([MazeCombate::iniciar]). El arquetipo
  **conserva su offset** de dificultad (tierra más duro que aire al mismo tier, porque el factor multiplica su
  base). El calor de colmena (`prob`) queda como **bump local** que suma vida antes del factor, no como el eje
  principal. No contradice la 011: los **saltos** gruesos de dificultad viven ENTRE mazes (secuencia), esto es
  la **rampa continua DENTRO** de un maze — dos ejes que componen. El dato de distancia ya existía paritario en
  `MapaBuilder::distancias`; se expuso como `MapaBuilder::dificultadCelda($matriz,$x,$y)` (una BFS desde la
  entrada) y lo pasa `JugarController::encuentro` a `iniciar`, que lo guarda en el blob del combate para el
  drop. Se consume solo en el servidor (axioma 4): no necesita espejo en JS. Números de arranque.

- **El nivel del loot desliza con la distancia [IMPLEMENTADO].** Reemplazó `nivel = dificultad + randBelow(3)`
  (drop por arquetipo) por `MazeCombate::nivelDrop($prng,$t)`: una distribución "tienda de campaña" sobre los
  niveles 1..7 cuyo centro corre de 2.5 (entrada → N2/N3) a 5.5 (salida → pico N5/N6), pendiente 14 → N7 ~14%
  en el fondo (cola rara ≤15%). El campo `dificultad` del arquetipo se queda para el multi-drop (019), deja de
  fijar el nivel del drop. Los drops grandes del fondo **superan el cap** del talismán (024) y alimentan la
  meta-progresión (desguace→esencia→nivel), no el loadout inmediato — coherente con DISENO §3. Números de
  arranque.

- **Fusionar cuesta 1 de esencia pura [IMPLEMENTADO].** Además del costo de oportunidad que ya tenía (dos n3
  desguazadas rinden 6 de esencia; fusionadas a n4 rinden 4 → se pierden 2), fusionar ahora **descuenta 1 de
  esencia pura** y se **bloquea si no hay**. Acopla la fusión al pozo que también paga niveles (024) y curar
  (021): fusionar compite por el mismo recurso, y hay un **gate temprano natural** (el mago inicial arranca con
  0 esencia → no se fusiona en el turno 1 sin desguazar antes). **Revierte la línea de la 026** ("evita acoplar
  fusión con progresión"): aquella era sobre derramar el *sobrante* a esencia; esto es un *costo*, pero cruza la
  misma frontera **a propósito** — el acople agrega una decisión (fusionar vs subir nivel vs curar) y frena
  holdear gemas para fusionarlas gratis en masa. `1` fijo es número de arranque; si el hoardeo tardío resulta
  real, se escala.

- **Panel de datos de celda en la caja de la rueda [IMPLEMENTADO, tooling de dev].** En la caja de la rueda
  elemental se muestran los datos de la celda actual (prob de encuentro %, elemento, tipo: entrada / salida /
  puerta / llave / colmena / normal) y se saca el texto explicativo de la rueda. Es tooling de
  desarrollo/imaginarium: hoy se ve todo. No es mecánica nueva — es el **revelado de datos de celda de la 014**
  (la visión los revela gradualmente) todavía **sin gatear**; el gateo por visión y la niebla se ponen encima
  más adelante ("primero el maze, después la niebla").

**Por qué:** El escalado por distancia resuelve que hoy la dificultad la manda solo el elemento (una Sílfide
cerca de la salida es tan trivial como en la entrada, un Gólem es pesadilla en todos lados) — con la rampa, el
fondo del maze pega tan duro como grande está tu talismán para entonces, que es el treadmill que el pilar de
planificación (§2) quiere. El loot deslizante le da el premio creciente que tira a pelear el fondo peligroso. El
costo de fusión mete a la fusión en la economía de esencia, donde compite por decisiones.
**Se descartó:** hacer del eje intra-maze la progresión primaria (los saltos gruesos quedan para la secuencia de
mazes, 011 — esto es la rampa fina); aplanar los arquetipos a puro estilo sin offset de dificultad (se conserva
el offset); costo de fusión escalado desde el arranque (se elige `1` fijo, legible; se escala solo si el hoardeo
lo pide); fijar ya los números del escalado (tuning, se ajustan jugando).

## 028 — Subir nivel da vida; recargar gemas con esencia — 2026-07-13
**Decisión:** Dos ajustes a la economía del talismán que salieron de jugar la versión que "tira mucha
basurita".

- **Subir nivel sube el tope de vida (+10) y cura al 100% [IMPLEMENTADO].** Hasta ahora `vidaMax` era un borde
  fijo (40) que no tocaba nada: el nivel derivaba cap y defensa, no vida. Ahora cada subida suma `VIDA_POR_NIVEL`
  (=10, arranque) al tope y **cura al 100%** — subir de nivel es también el sanador grueso, además de curar 1:1
  con esencia (021). `vidaMax` **no** se mete en `recomputar()` (que corre en cada acción y pisaría la vida): se
  muta incremental en `subirNivel`. Es un stat nuevo que crece con la progresión maestra (024), coherente con
  "el talismán es a la vez poder y vida" (DISENO §4).

- **Recargar una gema al tope cuesta su nivel en esencia [IMPLEMENTADO].** Nueva acción `recargar`: lleva la
  carga (⚡, combustible de combate) al tope `nivel × 6` (026) pagando `nivel × COSTO_RECARGA_POR_NIVEL` (=1,
  arranque) de esencia pura. **Sin cargas parciales**: va al tope entero y cuesta el nivel completo tenga 0 o
  casi lleno (una N4 en 15/24 igual cuesta 4 y queda en 24). Se bloquea con la carga llena (no malgasta esencia,
  como curar con la vida al tope) o sin esencia. Vale para cualquier gema, fieldeada o no. Reengancha la esencia
  con el ⚡: la versión que dropea mucha piedra chica dejaba sin carga ni para farmear, y recargar es la válvula.
  El candidato de tuning si resulta muy barato es **×2** (doble del nivel); se arranca en ×1 y se observa.

**Por qué:** Las dos cierran huecos que aparecieron jugando. La vida por nivel le da a "subir nivel" un segundo
motivo (no solo cap/defensa) y hace que la progresión también sostenga la supervivencia, que es el treadmill del
pilar de planificación. La recarga resuelve que el combustible de combate se secaba: sin ⚡ no hay hechizos, y
juntar gemas nuevas no alcanzaba si nacían para desguace. Ahora la esencia es el pivote entre nivel, cura,
fusión (027) y carga — cuatro sumideros que compiten, que es exactamente la tensión que el talismán busca.
**Se descartó:** derivar `vidaMax` en `recomputar` (pisaría la vida actual en cada acción); recarga parcial
proporcional (más "justa" pero borra la decisión de cuándo recargar); costo de recarga plano en vez de por nivel
(el por-nivel hace que mantener gemas grandes cargadas sea caro, coherente con que son las que rinden). Números
de arranque: `+10` de vida, `×1` de recarga; ambos se ajustan jugando.

## 029 — El monstruo tiene nivel; defensa unificada por el talismán — 2026-07-13
**Decisión:** Reescribe la economía de defensa y el modelo de escala del monstruo. Salió de jugar: el costo de
bloqueo era plano (peso 2-3) mientras la carga crece con el nivel de la gema (N7 = 42 ⚡), así que frenar era
prácticamente gratis y lo único que dolía era el golpe sin frenar. Dos cambios acoplados.

- **El monstruo tiene un `nivel` entero 1-7, derivado de la distancia [IMPLEMENTADO].** `nivel =
  clamp(round(1 + t×6), 1, 7)` → N1 en la entrada, N7 en el fondo (puede tocar N7 o no). Es el **tier único** del
  que sale todo, como el nivel de una gema. Reemplaza el factor continuo `×(1+t)` de la 027 por un eje discreto:
  vida y defensa escalan por `factor = 1 + (nivel−1)/6` (1.0 a N1, 2.0 a N7) sobre las bases del arquetipo. El
  arquetipo pasa a llevar `coefPeso` (peso POR NIVEL) en vez de un `peso` plano, y **se le saca `nivelAtaque`**:
  `peso = round(coefPeso × nivel)` con tierra 1.25 / fuego 1.0 / agua 1.0 / aire 0.75 (N4 → tierra 5, fuego/agua
  4, aire 3). Así el costo de frenar crece con la profundidad y le da identidad al peso (tierra golpea pesado y
  caro de parar, aire es una brisa). El loot sigue usando `t` directo (027), no el nivel discreto.

- **La defensa es una sola acción: el golpe SIEMPRE se paga por el talismán [IMPLEMENTADO].** Se va **comer**.
  Bloqueás con una gema; el costo en carga es `round(peso × elemento)` (×0.5 si tu gema le gana al golpe, ×1
  neutro, ×2 si pierde), determinista, sin crítico ni banda — es un recurso a presupuestar. La **carga paga
  primero y el déficit va a vida ×3** (`vidaPorEsencia`), idéntico a castear una gema sin carga (021). Bloquear
  **nunca se rechaza**: una gema seca simplemente paga todo con vida. El golpe del bicho deja de tener "daño
  extra" propio (por eso se fue `nivelAtaque`): el daño *es* el costo de bloqueo que no cubriste con carga. En el
  cliente, la pantalla del bicho pasó a ser una **hoja de stats / maqueta de modelo centrada sobre el mapa**
  (nombre, elemento, nivel, vida, defensa, peso), sin la caja de telegrafía ni botón de comer.

**Por qué:** Atar el peso al nivel del bicho arregla la tijera carga/bloqueo (a más profundidad, más caro frenar)
y de paso convierte al peso en el eje de personalidad ofensiva de cada arquetipo, que era lo que buscábamos al
rebalancear. Unificar defensa en una sola acción con caída a vida borra la microdecisión comer-vs-bloquear (que
en la práctica era casi siempre bloquear) y la reemplaza por decisiones más ricas: **con qué elemento** bloqueás
(la eficiencia ×0.5/×2) y **cuánta carga** tenés para gastar — que enganchan con el loadout y con la economía de
recarga (028). El daño a vida es 1:1 con el costo no cubierto ×3, misma regla que el ataque: una sola mecánica
de "carga primero, vida después" para las dos puntas del combate.
**Se descartó:** dejar el peso plano y escalar solo el resto (la tijera seguía); conservar `nivelAtaque` como
daño aparte del peso (dos ejes ofensivos que se pisaban; se fundieron en el peso); crítico/banda en el golpe
entrante (defensa impredecible sobre un recurso que estás presupuestando); mantener comer como opción (la
decisión real es elemento + carga, no comer-vs-bloquear). Números de arranque: `coefPeso` 1.25/1.0/0.75, nivel
`round(1 + t×6)`, vida×3 por déficit; todos se ajustan jugando.

## 030 — Escapar de un combate pagando esencia — 2026-07-13
**Decisión:** Se agrega una tercera acción de combate, `escapar`, y con ella un stat nuevo de arquetipo que va a
ser el primer ladrillo del compendio de monstruos.

- **Escapar cuesta esencia `= max(1, round(coefDestreza × nivel))` [IMPLEMENTADO].** Solo en **tu turno**: pagás
  la esencia y el combate se cierra como `huida` — sin botín, sin contar el bicho como caído, y **la partida no
  termina**. La colmena queda viva (es una colmena, no un bicho único): podés volver a cruzarla y pelearla o
  huir de nuevo, pagando otra vez. El `coefDestreza` es un stat del arquetipo, definido como **`2 − coefPeso`**:
  la inversa del peso. Tierra 0.75 (el gólem lento es barato de esquivar), aire 1.25 (la sílfide veloz es cara),
  fuego/agua 1.0. A N4: tierra 3, fuego/agua 4, aire 5. Se guarda como `monstruo.escape` en el estado de combate
  para mostrarlo y, más adelante, listarlo en el compendio.

**Por qué:** El bloqueo (029) resolvió *aguantar* el combate; faltaba poder *no darlo*. Escapar le da una salida
a los encuentros que no valen la pena —un bicho de un elemento contra el que tu loadout está flojo, o una pelea
que te va a costar más carga/vida de la que rinde— y convierte a la esencia en la moneda de esa decisión, otro
sumidero que compite con nivel/cura/fusión/recarga. Atar el costo a la **inversa del peso** cierra el arquetipo
como personaje: tierra pega fuerte y es caro de frenar pero fácil de dejar atrás; aire pega flojo y es barato de
frenar pero difícil de esquivar. Peso y destreza son los dos primeros ejes del compendio, y son opuestos por
diseño, así que ningún bicho es fuerte en las dos puntas.
**Se descartó:** permitir escapar durante la ventana de defensa (sería un "esquivar gratis" que vacía la
economía del bloqueo — si el bicho ya arremetió, primero se paga ese golpe); escape gratis o con cooldown en vez
de con esencia (sin costo no es una decisión); que huir consuma/mate la colmena (rompería el modelo de colmena
persistente de 016). Números de arranque: `coefDestreza = 2 − coefPeso`, costo `round(destreza × nivel)` con piso
1; se ajustan jugando.
