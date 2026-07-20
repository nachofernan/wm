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

## 031 — El elemento del encuentro de ambiente no puede derivar del dado de disparo — 2026-07-13
**Decisión:** El sorteo del elemento de un monstruo de ambiente (celda sin colmena, `elem = null`) avanza el PRNG
un paso antes de tirar: se descarta el primer output de la semilla de combate y el elemento sale del segundo
`[IMPLEMENTADO]`.

**Por qué:** Bug real, no percepción. El dado de disparo del encuentro (cliente, DECISIÓN 022) y el sorteo del
elemento (servidor) reconstruyen la **misma** semilla `seed ^ x·C1 ^ y·C2 ^ pasos·C3` y ambos consumían el
**primer** output de `Prng(semilla)`. Una celda de ambiente tiene `prob = 1`, así que el encuentro solo dispara
cuando `first % 100 == 0`; y como `100 = 4×25`, todo múltiplo de 100 es múltiplo de 4, con lo cual `first % 4 == 0`
siempre → `ELEMENTOS[0]` → **fuego, el 100% de las veces**. Medido: 8944 encuentros de ambiente sobre 5 seeds,
todos fuego. Las colmenas no lo mostraban porque ahí el elemento viene fijado por el núcleo y el sorteo ni corre;
el sesgo vivía solo en las celdas de ambiente, que son las únicas que sortean. Quemar el primer output desacopla
el elemento de la condición de disparo y devuelve el reparto ~uniforme entre los cuatro. No toca la paridad
PHP/JS: el cliente nunca calcula el elemento, lo recibe en la respuesta del servidor; el generador y el hash del
laberinto quedan intactos.
**Se descartó:** derivar el elemento de una semilla aparte con otra constante de mezcla (funciona, pero avanzar
un paso es más simple y auto-documenta el acople con el dado); bajar el `prob` de ambiente o cambiar el módulo
del dado (parches al síntoma que dejaban el acople latente para el próximo par de números coprimos que no lo
fueran).

## 032 — Las llaves son bosses telegrafiados sin escape; la cadena de puertas cierra el loop del maze — 2026-07-13
**Decisión:** Cierra §7 (llave y salida) de DISENO y le da la primera forma concreta a §4 (salir con algo).
El maze deja de ser un campo de farmeo sin objetivo y pasa a ser una cadena lock-and-key con guardianes.

- **Cadena lineal de tres llaves y tres puertas.** llave1 abre la puerta a distancia 100, llave2 la de
  distancia 200, llave3 abre la salida. Sin la llave del tramo no se cruza su puerta: el maze queda partido
  en tres cámaras que se recorren en orden. **La colocación YA EXISTE en `MapaBuilder`** (`PUERTAS_EN=[100,200]`,
  una llave por segmento), determinista y paritaria — **no se toca el generador, no hay cascada**. Lo nuevo es
  el gate en runtime (la puerta bloquea el paso sin la llave) y el mapeo llave3→salida (hoy la tercera llave no
  abre nada).

- **Cada llave la guarda un boss telegrafiado, sin escape.** Al entrar a la celda de la llave se revela el
  guardián (nombre, elemento, nivel, stats) con el combate todavía cerrado: es un **staging seguro** donde
  reordenás el talismán. Recién al apretar "pelear" arranca el combate, y ahí **no hay escape** (a diferencia de
  los encuentros de ambiente, 030): o lo matás o te mata. Solo matándolo cae la llave. Podés entrar, verlo y
  **no** pelear — comprometerse es opt-in, pero irreversible una vez iniciado.

- **Nivel fijo por índice de llave: 3 / 5 / 7.** Overridea la rampa por distancia (029, que deriva el nivel de
  `t`): el guardián es un **tier fijo**, no ambiente. Los 3/5/7 son la curva de la 029 muestreada en tercios
  (t=1/3→N3, 2/3→N5, 1→N7), así que no son arbitrarios; se fijan por índice como arranque (se pueden derivar del
  `t` de la puerta si las distancias dejan de ser 100/200). El guardián es un **arquetipo elemental escalado y
  telegrafiado**, no un statblock propio — eso queda para el compendio con la historia.

- **Boss final N9 en la salida.** Además de las tres llaves, la salida tiene su propio guardián a **N9** —
  deliberadamente por encima del techo 1..7 de gemas y monstruos. No se gana out-levelándolo (el jugador topa en
  ~N7): se gana por **matchup elemental + carga**, apoyado en el daño por ratio (012, "el alfeñique siempre
  araña"). Es el clímax del maze.

- **Perder contra un boss = muerte = mapa nuevo.** Sin escape y sin red: perder cualquier boss termina la
  corrida y resetea a un maze nuevo (coherente con 011, "la muerte resetea el maze"). Las **revividas** (§4)
  quedan para después; hoy la derrota es game over. Ganar el boss final y salir = **victoria** (salir con algo:
  el botín acumulado).

**Por qué:** El combate (024–031) estaba construido con profundidad pero sin objetivo — un verbo sin oración. La
cadena de llaves-boss le da a la corrida un para-qué (§4) **reusando la colocación que `MapaBuilder` ya producía**.
Los guardianes telegrafiados sin escape son la forma más pura del pilar de planificación (§2): cuatro peleas,
cuatro contras distintas, reseteás el talismán en cada staging. Cuando se apague la niebla (014/016), las tres
cámaras encadenadas se vuelven tres mazes difíciles unidos.
**Se descartó:** llaves dispersas/opcionales (se eligió la cadena lineal, stakes firmes); statblocks de boss
propios ahora (arquetipos escalados como arranque; compendio después); derivar el nivel del boss del `t` de la
celda de la llave (da números sucios; se fija por índice); permitir escape en los bosses (rompería el "commit
total"); revividas antes del boss (game-over como placeholder). **Cascada:** ninguna en el generador — la
colocación ya existe y es paritaria. El trabajo es gameplay: gate de puertas en el cliente (movimiento, 022),
combate de boss en el servidor (`MazeCombate`/controller, autoridad — axioma 4), y el set de llaves en la caché
`runs` (proyección, axioma 5). Números de arranque: niveles 3/5/7/9.

## 033 — El rastro explorado se tapa opaco (config); bosses y sílfide más duros — 2026-07-14
**Decisión:** Tres ajustes de dificultad, ninguno toca el generador ni la paridad.

- **Camino explorado opaco (nueva niebla dura).** Las celdas ya pisadas fuera del radio de visión se tapan con
  un gris **sólido** en vez del velo translúcido de antes: se ve **por dónde** pasaste, pero no las paredes ni
  el tinte de colmena. La vuelta hay que recordarla, no leerla del mapa — y quedar sin salida en una colmena
  duele. Es puro cliente (`dibujarNiebla`), y va detrás de un **toggle on-off en el panel de configuración**,
  persistido global en `localStorage` (no por token) para poder comparar mientras se testea. Default: **on**.
  Un **segundo toggle** repinta el tinte de colmena sobre el gris opaco (ves dónde había peligro sin recuperar
  las paredes) — herramienta de análisis, default **off**, solo aplica con las paredes ocultas.

- **El panel de configuración reemplaza al de stats.** El cuadro del seed dejó de mostrar bichos/gemas (no
  aportaban a ninguna decisión) y ahora hospeda las perillas de testeo. Es el lugar donde van a vivir las
  próximas configuraciones de prueba.

- **Guardianes subidos de 3/5/7/9 a 4/6/8/10.** Sube el piso de los cuatro bosses (032): rampa creciente, la
  salida como pico deliberado a N10, más lejos todavía del techo N7 del jugador. Son números de arranque; esto
  revierte-en-espíritu los niveles de la 032 (bitácora append-only: entrada nueva, no se reescribe la vieja).

- **Sílfide del aire: defensa 12 → 16.** Farmear una colmena de aire era demasiado barato. Sigue siendo el
  arquetipo más blando (fuego 18, agua 22, tierra 30), pero deja de ser gratis. Número de arranque.

**Por qué:** El maze se leía como un plano una vez caminado — la planificación (§2) se abarataba porque la vuelta
estaba siempre servida. Taparlo opaco convierte la memoria del recorrido en un recurso. Los bumps de boss y de
sílfide son tuning de sesión de juego, no rediseño. **Se descartó:** tapar también las marcas de objetivo
(llaves/puertas/salida siguen siendo faros, se dibujan por encima de la niebla); hacer el opaco fijo sin toggle
(se quiere comparar mientras se ajusta el resto). **Cascada:** ninguna — nada de esto toca `MazeGenerator`, el
PRNG ni el test de paridad. Los tests de `MazeCombate`/`JugarController` que asserteaban 3/5/7/9 se actualizaron
a 4/6/8/10.

## 034 — Golpe mártir y revivir pagando esencia: la muerte deja de ser el final — 2026-07-19
**Decisión:** Dos reglas de vida/muerte en combate, ninguna toca el generador ni la paridad.

- **Golpe mártir.** Si tu ataque mata al monstruo *y* el pago en vida de ese mismo golpe (la penalidad por
  castear sin carga, 021) te habría dejado en 0 o menos, **sobrevivís clavado en 1 de vida**, no en 0, con su
  propia línea de log (`tipo` `martir`). Antes era un accidente de orden: la vida se clampeaba a 0 y el combate
  cerraba en victoria sin volver a mirarla, así que quedabas "vivo" en 0 y el endpoint `curar` (021, exige
  combate cerrado) te dejaba comprar vida y seguir. Ahora es regla explícita. Vive **antes** del despacho
  `victoria() → victoriaBoss()`, así vale igual para un bicho de ambiente y para un guardián. Efecto colateral
  buscado: con vida ≥ 1 garantizada en toda victoria, **`vida ≤ 0` pasa a implicar siempre derrota** — el
  invariante que hace innecesaria una columna nueva para el revivir.

- **Revivir pagando esencia.** La derrota ya **no termina la corrida**. Caés en un limbo "muerto, pendiente de
  decisión" (`combate = null`, `vida ≤ 0`, `!terminado`) y podés **revivir pagando esencia**, **ilimitado
  mientras haya esencia** (sin tope de cantidad). El costo **escala con la profundidad** de la celda donde
  moriste —la misma `t` que escala nivel de bicho (029) y loot (027)—: 1 esencia en la entrada, 10 en el fondo,
  no por cantidad de revividas. `Talisman::costoRevivir(t)` es puro y 100% servidor (axioma 4, sin espejo JS,
  igual que `dificultadCelda`). Revivir te devuelve con **1 de vida y no toca nada más del talismán**: recargar
  las gemas para volver a pelear es el endpoint `recargar` (028), aparte — la tijera es que el mismo pozo de
  esencia paga revivir *y* recargar. Si la esencia **no alcanza** el costo, es **game over real**: ahí sí la
  corrida termina (evento `derrota_final`). Aplica igual a ambiente y a guardián.

**Revisa la 032** (append-only, no la reescribe): la 032 cerraba "perder contra un boss = muerte, sin red, sin
escape". Sigue sin haber *escape* de un guardián (no podés huir del combate), pero **un boss ahora es
revivible** como cualquier muerte — igual que la 033 matizó en espíritu a la 032 sin tocarla. El guardián no
guarda estado fuera del combate activo: `MazeCombate::guardian()` lo reconstruye del seed a vida completa en
cada encuentro fresco, así que revivir y volver a pelearlo lo enfrenta entero y sin llave otorgada, sin
construir nada extra.

**Por qué:** El talismán es a la vez poder y vida (hipótesis del §2/DISENO): un mago maximizado es un mago que
está por morir, y morir en el fondo tras armar todo era un acantilado sin gradiente. El revivir con costo
escalado convierte la muerte profunda en una **decisión económica** —pagás caro por seguir, y capaz revivís
pero te quedás sin esencia para recargar— en vez de un corte seco. El golpe mártir premia el cálculo fino
(matar justo cuando te desangrás) en lugar de castigarlo con una muerte que el jugador no eligió.

**Se descartó:** tope de cantidad de revividas y costo por cantidad (se eligió costo por profundidad,
ilimitado — la esencia ya es el tope natural); una columna de estado para "muerto sin decidir" (el invariante
`vida ≤ 0 ⇒ derrota` del golpe mártir la hace innecesaria); resetear/recargar el talismán al revivir (la vida
se paga sola; recargar es gasto aparte, a propósito).

**Cascada:** ninguna sobre `MazeGenerator`, el PRNG ni el test de paridad. Dentro del servidor sí: con la
muerte dejando la corrida viva, `talisman()` y `salir()` se **bloquean con `vida ≤ 0`** (si no, `curar` sería un
revivir barato 1:1 que saltea el costo escalado, y perder contra el guardián de la salida —que custodia la
celda de salida— dejaría "ganar" tildado desde el limbo). Cliente: la pantalla de derrota ofrece revivir cuando
la esencia alcanza; el movimiento se congela con `vida ≤ 0` igual que con `terminado`; el costo viaja en
`estado.revivir` solo en el limbo. El test de `JugarController` que antes asumía `terminado = true` tras una
derrota cambia: ahora la corrida queda viva.

## 035 — Cofres en las puntas de brazo: hasta 8, top-N global, nivel por profundidad — 2026-07-20
**Decisión:** El laberinto tiene **hasta 8 cofres**, ubicados en las **puntas de brazo** — los callejones sin
salida que cuelgan del camino entrada→salida. Reusa la maquinaria de las llaves (`MapaBuilder`, extensión `m` de
cada celda como brazo colgado del camino), pero en vez de "una punta por segmento" (llaves) toma las **8 puntas
de mayor `m` de TODO el maze**, sin partir por segmento, con piso `BRAZO_MINIMO` (25). Una punta es un callejón
sin salida real (una sola celda vecina navegable). Si hay **menos de 8** candidatas que cumplan el piso, van
menos — el número no se fuerza. Se **excluyen** las celdas ya ocupadas (entrada, salida, puertas, llaves): las
llaves también son puntas y colisionarían. Desempate determinista y paritario (m desc, y asc, x asc), sin
depender de la estabilidad del sort de cada lenguaje.

**Nivel de la gema del cofre:** sale de la **profundidad de la celda** vía el eje de `dificultadCelda`
(`t = distancia a la entrada / total`), escalado a **1..7 con la misma fórmula que el nivel de un monstruo**
(`round(1 + t·6)`, clampeada, 027/029). Un cofre en el fondo rinde como un bicho del fondo. **Elemento:** se
sortea al abrir con el **mismo mecanismo que un drop de combate** (`MazeCombate::elementoDrop`, rueda 026): el
cofre tiene una afinidad elemental derivada del seed (como un guardián) y el botín se sesga hacia ella. La gema
nace con **carga llena** (nivel × `CARGA_POR_NIVEL`), como el drop de un boss. Abrir un cofre **no cuesta nada**
hoy (ni turno ni riesgo).

**Por qué:** El cofre premia el **desvío**. Los brazos largos son, por definición, los que más cuesta ir a
buscar y volver; poner ahí el mejor loot garantizado convierte cada brazo en una **decisión** (¿vale la pena el
detour por su nivel, sabiendo que ver más es durar menos?) en vez de relleno. Tomar el top-N **global** (y no
uno por segmento como las llaves) concentra los cofres donde el maze realmente tiene brazos profundos, que es
donde el desvío se siente. Enganchar el nivel a la profundidad reusa el único eje de dificultad del proyecto
(027) sin inventar una escala nueva.

**Se evaluó y se descartó (por ahora):** un **boss en el centro de colmena** y un **cofre-con-gema-de-ventaja-
elemental en el núcleo de colmena**. Se dejan anotados acá para que no reaparezcan como pendiente fantasma: la
versión que se construyó es el **sistema simple de 8 cofres en brazos**, nada atado a las colmenas. Si más
adelante se quiere premiar despejar una colmena, es una decisión nueva, no un olvido de esta.

**Arquitectura / paridad:** la **ubicación** de los cofres es función pura del seed y va en `marcas()` (posición
+ nivel), **espejada bit a bit** en `resources/js/mapaBuilder.js` (verificada a mano contra PHP en los 4 seeds
del vector; el suite Vitest sigue caído por el tooling preexistente —`Cannot read properties of undefined
(reading 'config')`—, ajeno a esto, así que el lado JS queda ciego por esa vía). El nivel es posición-derivada
(no secreto: el cliente ya conoce el maze entero, axioma 4), pero el **botín** (elemento + gema) lo tira el
servidor al abrir y **no viaja en las marcas**. El generador, el PRNG y su test de paridad **no se tocan**.

**Cascada:** `marcas()` gana la clave `cofres`; columna `cofres` en `runs` (índices ya abiertos, patrón de
`llaves`); endpoint `POST /jugar/{token}/cofre` (valida posición contra el seed, rechaza celda sin cofre / cofre
ya abierto / en combate / caído); cliente pinta el 📦 **respetando la niebla** (a diferencia de las llaves, que
son faros: el cofre es loot opcional, solo se ve una vez descubierto) y ofrece un prompt "abrir" no bloqueante.

**Nota de implementación a revisar:** el otorgamiento de la gema fuera de combate (`MazeCombate::abrirCofre`)
suma a `gemasJuntadas` pero **no** a `bichosCaidos` (no cayó ningún bicho) — se tomó como la lectura más simple
y consistente del patrón de `victoriaBoss`, no como decisión de diseño.

## 036 — La defensa del mago deja de ser stat muerto: descuenta el costo de bloquear — 2026-07-20
**Decisión:** La defensa del talismán (`talisman.defensa`, calculada por `Talisman::recomputar` como
`defensaDeNivel(nivel) + defensaGema` de las gemas agua/tierra fieldeadas) pasa a **descontar el costo en carga
de bloquear** un golpe entrante. Entra como un **tercer factor multiplicativo** sobre `CombatResolver::costoBloqueo`,
reusando `mitigacion()` —la MISMA curva `K/(K+defensa)`, K=50, con que el monstruo mitiga el daño que le entra:

`costo = max(1, round(peso × factorMatchup × mitigacion(defensaDelMago)))`

El matchup elemental (×0.5/×1/×2) sigue siendo la **palanca fuerte** que el jugador elige activamente cada golpe;
la defensa del talismán es un **descuento parejo de fondo** encima, exactamente como el `ataqueMult` es un bono
parejo de fondo sobre el daño. Con el mago inicial (defensa 26) el factor es `50/76 ≈ 0.66`: bloquear cuesta ~un
tercio menos, y sube fieldeando gemas agua/tierra. [IMPLEMENTADO en PHP y en el espejo JS
`costoBloqueoEstimado`.]

**Qué estaba roto:** la defensa era una **stat muerta desde la 029**. La 029 reemplazó el viejo "comer un golpe =
mitigación directa vía `K/(K+defensa)`" por el bloqueo obligatorio actual, cuyo costo depende del `peso` del
monstruo y del matchup de la gema — **nunca** de la defensa del talismán. Desde entonces `defensa` se calculaba,
se mostraba en la ficha del MAGO, y no entraba en ningún cálculo real: el eje defensivo del talismán (agua/tierra)
no cambiaba ninguna decisión.

**Por qué se reusó la curva existente y no una nueva:** darle a la defensa del mago la **misma forma** que ya
tiene el ataque — ambos porcentajes, ambos con retornos decrecientes visibles al fieldear gemas — sin agregar una
tercera curva de tuning al proyecto. `K/(K+defensa)` ya está probada y entendida (es la del monstruo); que la
defensa del mago "se sienta" igual que su ataque es coherencia, no coincidencia. El descuento va sobre el costo de
bloquear (no sobre el daño, que ya no existe como número aparte: en la 029 el daño *es* el costo que no cubriste
con carga), así que reengancha el eje defensivo con el recurso que la 029 puso en el centro: la carga.

**Se descartó:** dejar la defensa como **stat decorativo** (el problema que esta decisión arregla); inventar una
**curva o constante nueva** para el mago (una tercera palanca de tuning sin ganancia sobre reusar la que ya
modela mitigación); hacer que la defensa incidiera sobre el matchup en vez de encima de él (borraría la palanca
fuerte que el jugador elige cada golpe, que es lo que hace interesante el bloqueo).

**Cascada:** firma de `CombatResolver::costoBloqueo` (+`defensaMago`); llamador en `MazeCombate::resolver` (rama
`bloquear`, pasa `$talisman['defensa']`); espejo JS `costoBloqueoEstimado` (mismo tercer factor con
`talisman.defensa`); endpoint de tuning `/pj` (reusa el slider `defensa` que ya validaba); tests de
`CombatResolver` y `MazeCombate` recalculados a mano con la fórmula nueva. El generador, el PRNG y su test de
paridad **no se tocan**. Pendiente de UI (lo sigue el usuario, no es decisión de economía): la ficha del MAGO hoy
muestra `talisman.defensa` como número crudo sin `%` — ahora que hace algo real, quizás corresponda darle el
mismo tratamiento `-X%` que ya tiene el resto.

## 037 — Los cofres se reparten por segmento y se sortean con separación mínima — 2026-07-20
**Decisión:** La ubicación de los cofres deja de ser el **top-8 global por longitud de brazo** (035)
y pasa a un **reparto por segmento + sorteo determinista + separación mínima** (opción C de la charla).
Cada punta de brazo elegible (callejón sin salida con `m ≥ BRAZO_MINIMO=25`, no ocupada) se agrupa por su
**punto de desprendimiento** `k = dInicio - m` en uno de los **tres segmentos** del camino (los mismos que
cortan las puertas, `PUERTAS_EN=[100,200]`). `MAX_COFRES=8` se reparte lo más parejo posible en orden
`[seg0, seg1, seg2]` → **3/3/2**; si un segmento no completa su cupo, el faltante se **traslada hacia
adelante** al siguiente (nunca hacia atrás). Dentro de cada segmento se **sortea sin reemplazo** con un PRNG
determinista (`new Prng(seed ^ SEMILLA_COFRES)`, `SEMILLA_COFRES=0xC2B2AE35`, mismo patrón de sub-semilla que
`EncuentroBuilder`), y se descarta toda candidata a menos de `SEPARACION_MINIMA_COFRES` de una **ya aceptada
de cualquier segmento**, medida como `|dInicio_a - dInicio_b|` (proxy barato de distancia en el árbol,
consistente con cómo `extensionDesdeCamino` ya usa `dInicio`). Sigue siendo "hasta 8": si la estructura no da,
van menos. [IMPLEMENTADO en PHP y en el espejo JS `resources/js/mapaBuilder.js`.]

**Qué estaba roto (reportado jugando):** dos problemas reales del top-8 global. (1) **Agrupamiento al final**:
las ramas más largas caen cerca de la salida, así que el top-8 por `m` metía casi todos los cofres al fondo del
maze —no había reparto temprano/medio/tardío. (2) **Cofres pegados**: cuando una rama larga se bifurca cerca de
la punta en varios callejones, esos callejones comparten casi toda la extensión (`m` casi idéntico) y el top-8
los agarraba a todos juntos, dejando 3 cofres pegados unos a otros.

**El valor de la separación (8):** la propuesta original era 15, pero sobre los 4 seeds de vector fijo daba
resultados degenerados (3 de 4 seeds con solo 4 cofres); 10 seguía cayendo (4/4/6/7). Se barrió el rango y hay un
acantilado entre 8 y 10. **8** es el valor más alto que mantiene los 4 seeds en un conteo sano (6/5/7/8) y alcanza
de sobra para partir las bifurcaciones apretadas: los callejones que comparten casi todo el brazo caen a un puñado
de unidades de `dInicio` entre sí. Tiene que ser `< BRAZO_MINIMO` (25) para ser alcanzable, y lo es. Reparto 3/3/2
= `intdiv(8,3)=2` base con los primeros `8%3=2` segmentos +1.

**Se descartó:** (A) **solo un piso de separación** sin reparto por segmento —no arregla el agrupamiento al final,
que es estructural (dónde caen las ramas largas), no de proximidad; (B) **sorteo global ponderado + separación**
sin partir por segmento —mitiga el pegado pero no garantiza reparto temprano/medio/tardío. Se eligió (C) porque
reusa el mecanismo que las **llaves** ya usan (una por segmento) y ataca los dos problemas de raíz.

**Paridad:** el algoritmo de sorteo es **token por token idéntico** en PHP (`MapaBuilder::seleccionarCofres`) y JS
(`seleccionarCofres` en `mapaBuilder.js`): mismo orden de llamadas a `randBelow`, misma remoción por
**swap-con-el-último** en O(1), mismo criterio de pool (recorrido `y` asc, `x` asc, sin sort para no depender de su
estabilidad). Verificado a mano sobre los 4 seeds del vector con un script Node standalone contra el output de PHP
(Vitest sigue caído por el tooling preexistente, ajeno a esto): output **idéntico** elemento por elemento y en el
mismo orden. Conteos antes→después: seed 1 8→6, seed 42 7→5, seed 12345 8→7, seed 2026 8→8.

**Cascada:** firma `MapaBuilder::marcas($matriz, $seed)` (necesita el seed para el PRNG) → llamador interno en
`buscarSeed`, 3 call sites en `JugarController` (`$run->seed`), espejo `marcas(matriz, seed)` en `mapaBuilder.js`
y su call site en `game.js`; vectores fijos de `cofres` en `MapaBuilderTest.php` y `mapaBuilder.test.js`
regenerados; feature tests de `/cofre` reapuntados al nuevo índice 0 del seed 42 (28,5). El generador, el PRNG de
generación del laberinto, las llaves, la apertura de cofres (`MazeCombate::abrirCofre`) y el sorteo de afinidad
**no se tocan**: solo cambia qué celdas quedan elegidas como cofre. Test de paridad del generador (`maze:hash`)
sigue en verde.
