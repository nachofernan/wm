# Wizard's Maze — Reglas del proyecto

Roguelike de laberinto, single player, por turnos, jugable en el navegador. Es la
reconstrucción de un proyecto viejo: un laberinto generado por backtracking recursivo,
un talismán de cuatro gemas elementales que define las reglas del mago, monstruos
sueltos, cofres, una llave y una salida. Aquella versión funcionaba y se abandonó por un
problema de arquitectura, no de diseño: persistía la matriz completa y recargaba estado
en cada movimiento.

Esta versión existe para hacerlo bien. No es un proyecto escuela ni un producto a vender:
es un juego que tiene que ser bueno y una arquitectura que tiene que ser correcta. El
criterio para construir algo no es "completitud técnica", es "¿esto hace que la partida
sea más interesante?". Una feature que no cambia una decisión del jugador no se construye.

La ficción todavía no está cerrada (ver `docs/DISENO.md`). La mecánica manda sobre la
ficción: primero se sabe qué habilidad mide el juego, después se decide de qué está hecho
el mundo.

## Los modos de trabajo

Este proyecto se conversa antes de codearse. Hay tres roles, encarnados como agentes en
`.claude/agents/`, y hay que saber en cuál se está:

- **Mentor** (`mentor` — Opus, effort xhigh, solo lectura). La voz de asesor del proyecto:
  se discute diseño, economía, estructura, proyecciones, roadmap y decisiones de largo
  plazo. No se escribe código ni se "aprovecha" la charla para dejar un archivo hecho. La
  sesión principal trabaja por defecto con este stance; el agente `mentor` es para clavarse
  en una decisión pesada y devolver un análisis de un tiro. Si de la charla sale una
  decisión, se anota en `docs/DECISIONES.md` y ahí termina.
- **Senior** (`senior` — Opus, effort high, todas las tools). Implementa las estructuras
  fuertes: el generador, el PRNG, la paridad PHP/JS, la economía de combate y del talismán
  — lo de `app/Game/`. Con criterio, en pasos chicos explicados antes. Acá los tests
  importan y mucho: la paridad es sagrada. Commitea al cerrar una etapa lógica.
- **Junior** (`junior` — Sonnet, effort medium, todas las tools). Ejecuta ediciones
  directas: arreglos a dedo, tweaks, texto. Cumple lo que se le pide, sin dudar mucho y con
  poco preámbulo. Por defecto no commitea ni corre tests — solo si se lo piden. Si siente
  que tocó un nervio (algo de `app/Game/`, el generador, la paridad), corta y avisa en vez
  de seguir de largo.

Si el rol no está claro, se pregunta cuál corresponde antes de hacer nada. Ante la duda,
mentor: un archivo escrito de más cuesta más que una pregunta de más.

### Cómo se pregunta

- Si algo no se entiende o hay ambigüedad, se pregunta **con opciones concretas**
  (A / B / C), no con un "¿cómo querés que lo haga?" abierto.
- Si el concepto es lo bastante grande como para que la respuesta correcta dependa de
  cosas que todavía no están decididas, no se ofrecen opciones: se pide charlarlo.
  Ejemplo: "esto toca la economía de gemas, que no está cerrada — conviene hablarlo
  antes de que escriba nada."
- Nunca se resuelve una ambigüedad de diseño eligiendo por cuenta propia y avisando después.
  Una ambigüedad de implementación (nombre de una variable, orden de dos métodos), sí.

## Cómo se trabaja

- Antes de tocar archivos, se explica qué se va a crear/modificar y por qué. Se espera
  confirmación antes de avanzar con un paso de alcance nuevo.
- Cada paso es un cambio lógico chico (una migración, un generador, un endpoint), no
  varios cambios de golpe.
- Si un cambio obliga a tocar otra capa, se marca explícitamente como efecto en cascada
  antes de hacerlo. Nunca silencioso.
- **Cambio en el generador = efecto en cascada garantizado.** Tocar el algoritmo o el PRNG
  invalida todos los seeds guardados y rompe la paridad PHP/JS. No se toca sin avisarlo
  como lo que es: un cambio destructivo.
- Si en el camino aparece algo necesario que no estaba pedido, se explica después, con
  el motivo.
- Git: commit al cerrar una etapa lógica. No se deja una etapa terminada sin commitear,
  ni se commitea a mitad de un cambio. Antes de tocar archivos con cambios sin commitear,
  se revisa `git status` / `git diff`.

## Axiomas de arquitectura

Son las reglas que no se negocian sin una conversación explícita. Todo lo demás es táctica.

1. **El laberinto es una función pura del seed.** `seed → algoritmo → laberinto`. La matriz
   nunca se persiste, nunca se serializa, nunca viaja por la red. Lo que se guarda es un
   entero.

2. **El generador existe dos veces y produce output idéntico.** Una implementación en PHP
   (autoridad) y una en JS (render). Bit a bit. Esto obliga a:
   - PRNG determinista propio, implementado igual en los dos lenguajes. Prohibido
     `mt_rand`, `rand`, `random_int`, `Math.random`, `shuffle`.
   - Un test de paridad que corre el generador en ambos lenguajes sobre un set de seeds
     conocidos y compara el hash del laberinto resultante. Si ese test se pone en rojo,
     nada más importa hasta que vuelva a verde.
   - Iteración con orden explícito. Nada que dependa del orden de claves de un hash, del
     orden de `Object.keys`, ni de ninguna implementación interna.

3. **El servidor es autoritativo; el cliente es optimista.** El movimiento no viaja al
   servidor. El cliente regenera el laberinto desde el seed, mueve al mago localmente y
   dibuja. Al servidor solo suben **eventos que importan**: abrir un cofre, entablar
   combate, usar un hechizo, salir. El servidor regenera el laberinto desde el seed,
   valida que el evento sea legal (que el jugador pudiera estar ahí, que la celda tenga
   lo que dice tener) y aplica el resultado.

4. **Nunca se confía en el cliente para nada que importe.** El cliente conoce el seed, y
   por lo tanto conoce el laberinto entero: dónde está la llave, dónde están los cofres,
   dónde la salida. Eso es aceptable y es un tradeoff consciente: el juego es single
   player y no hay ranking. Lo que **no** es aceptable es que el cliente decida qué había
   en el cofre, cuánto daño hizo un golpe, o si un hechizo alcanzó. Eso lo resuelve el
   servidor, siempre.

5. **El estado de partida es chico.** seed, posición, talismán, HP, y sets de cosas ya
   consumidas (celdas visitadas, cofres abiertos, monstruos muertos). Si el estado de
   partida empieza a crecer hacia algo del tamaño del mapa, algo se hizo mal.

6. **Los eventos son append-only y el estado se deriva de ellos.** La tabla de eventos es
   la fuente de verdad; el estado en `runs` es una proyección (cache) para no rehacer el
   replay en cada request. Ninguna fila de eventos se actualiza ni se borra.

7. **Sin Livewire.** Livewire renderiza en el servidor y difunde DOM. Es, con mejor ropa,
   exactamente el problema que hundió la versión anterior. El front es Alpine + `fetch`
   contra endpoints JSON. Esta decisión no se revisa por conveniencia de una pantalla
   puntual.

## Principios de código

- La sencillez que se busca es de implementación, no de diseño de juego. YAGNI aplica a la
  capa técnica; no aplica al alcance de las mecánicas. Si el valor está en la economía del
  talismán, eso se construye con la profundidad que haga falta.
- YAGNI explícito: sin Observers, Jobs, Events/Listeners, Repositories, Services, hasta
  que algo concreto los pida. Simple gana mientras alcance.
- **Excepción deliberada:** el generador y las reglas de combate/economía viven en clases
  propias bajo `app/Game/`, no en controladores ni en modelos. No es una abstracción
  prematura: es lógica pura, determinista y testeable que no tiene por qué saber que
  existe HTTP ni una base de datos. Se testea sin tocar la DB.
- Convenciones Laravel estándar en todo lo demás. Nada donde Laravel no lo espera.
- Sin código defensivo para casos que no pueden pasar. Validación en los bordes.
- Tres líneas repetidas son mejores que una abstracción prematura.

## Testing

- Pest. Toda feature nueva lleva al menos un test antes de considerarse terminada. Nada
  de "test pendiente para después".
- **El test de paridad PHP/JS del generador es el test más importante del proyecto.** Corre
  el generador en ambos lenguajes sobre seeds fijos y compara hashes. Si está en rojo, el
  proyecto está roto aunque todo lo demás pase.
- Los seeds de test son fijos y están commiteados. Nunca aleatorios.
- La lógica de `app/Game/` se testea unitariamente, sin base de datos. Si un test del
  generador necesita levantar la DB, el generador está mal escrito.
- Los endpoints se testean contra intentos ilegales, no solo contra el camino feliz: un
  evento de cofre en una celda sin cofre tiene que ser rechazado.

## Documentación

- Comentarios inline solo cuando el *por qué* no es obvio.
- Toda función/método nuevo, o modificado sustancialmente, lleva docblock (PHPDoc / JSDoc)
  que explica para qué sirve y cómo se conecta con el resto: quién la llama, qué dispara,
  de qué depende.
- `docs/DISENO.md` — el documento vivo del juego: qué habilidad mide, la economía del
  talismán, qué hacen los monstruos, qué hay en los cofres, qué significa ganar. Es el
  documento que más va a cambiar y el que manda sobre el código.
- `docs/DECISIONES.md` — bitácora append-only de decisiones de diseño y arquitectura, con
  el motivo y lo que se descartó. Cuando una charla cierra algo, se anota acá. No se
  reescribe el pasado: si una decisión se revierte, se agrega una entrada nueva que la
  revierte.
- `docs/PROTOCOLO_GENERADOR.md` — la especificación del PRNG y del algoritmo de laberinto,
  en prosa y pseudocódigo, independiente de PHP y de JS. Es el contrato que las dos
  implementaciones cumplen. Si el algoritmo cambia, cambia acá primero.
- `ROADMAP.md` — fases del proyecto, se tachan a medida que avanzan.
- `README.md` — se actualiza cuando se agrega un paso de setup relevante.

## Stack

- Laravel 12. Sin Jetstream, sin Breeze, sin Livewire.
- Alpine.js para el estado del cliente. `fetch` contra endpoints JSON.
- Tailwind para estilos.
- Render del laberinto: `<canvas>`. Un grid de 100x100 en DOM no es viable y no hay razón
  para intentarlo.
- SQLite en desarrollo. Sin decisión tomada para producción, y no hace falta tomarla ahora.
- Pest para tests. Vitest (o equivalente) para el lado JS del test de paridad.
- Sin autenticación por ahora. Una partida se identifica por un token opaco en la URL.
  Cuando haga falta login, se agrega; no antes.

## Estado

Fase de diseño. **No hay código.** Lo que está cerrado son los axiomas de arquitectura de
arriba. Lo que está abierto, y se decide charlando:

- Qué habilidad del jugador mide el juego (attrition / deducción / planificación).
- La economía del talismán. La hipótesis en juego: **el talismán es simultáneamente el
  poder y la vida**. Cada hechizo consume nivel de gema. El radio de visión sale del
  talismán, así que ver más es durar menos. Un mago maximizado es un mago que está por
  morir. Salir del laberinto no es la victoria; la victoria es salir *con algo*.
- La ficción. Tentativamente arcana por herencia del proyecto viejo, pero no cerrada.
- El comportamiento de los monstruos. Un monstruo que persigue por A* convierte el juego
  en un juego de reflejos, que es justo lo que este stack no puede dar. Probablemente
  tengan que ser estáticos, o telegrafiados, o moverse solo cuando el jugador se mueve.

Ver `ROADMAP.md` para el orden. Regla de arranque: **el generador y su test de paridad
vienen antes que cualquier pixel.** Si eso no está sólido, nada de lo demás vale la pena.
