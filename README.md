# Wizard's Maze

Roguelike de laberinto, single player, por turnos, jugable en el navegador.

Un mago entra a un laberinto que no conoce. Ve pocas celdas a la redonda. Adentro hay
cofres, monstruos, gemas, una llave y una salida. El talismán que lleva encima tiene
cuatro gemas elementales, y la combinación de sus niveles define de qué es capaz: cuánto
ve, cuánto aguanta, qué hechizos puede lanzar.

El talismán es también lo que gasta. Cada hechizo consume nivel de gema. Ver más lejos
cuesta. Bajar más adentro cuesta. El laberinto no se gana llegando a la salida: se gana
saliendo con algo, y decidir cuánto laberinto se puede pagar es el juego entero.

> **Estado: fase de diseño. No hay código todavía.**
> Lo que está definido es la arquitectura. La mecánica se está discutiendo.
> Ver `CLAUDE.md` para las reglas del proyecto y `docs/DISENO.md` para el diseño vivo.

---

## De dónde viene

Existió una primera versión, hace años, escrita mientras se aprendía POO. Generaba el
laberinto con backtracking recursivo sobre una matriz bidimensional, tenía el talismán de
cuatro gemas, los monstruos, los cofres y la llave. Funcionaba, y era hermosa.

Se abandonó por una razón que no tenía nada que ver con el juego: **persistía la matriz**.
Un mapa de 100x100 son diez mil objetos-celda que había que cargar, serializar y devolver
en cada movimiento. Sin AJAX, eso era una recarga de página completa por cada paso. Con
AJAX, era el mismo peso viajando por la red cada 200 milisegundos. Se optimizó mandando
solo el borde visible, y aun así el problema de fondo seguía ahí.

Esta versión arranca desde la corrección de ese error.

## La idea que lo cambia todo

Un laberinto generado por un algoritmo determinista **no es un dato: es una función**.

```
seed → algoritmo → el mismo laberinto, siempre
```

No hay que guardar diez mil celdas. Hay que guardar un entero.

De ahí sale toda la arquitectura:

- El cliente recibe el seed, regenera el laberinto **una sola vez** al cargar, y lo mantiene
  en memoria. El movimiento nunca toca el servidor.
- Al servidor solo suben los eventos que importan: abrir un cofre, entablar combate, lanzar
  un hechizo, salir.
- El servidor regenera el laberinto desde el mismo seed, valida que el evento sea legal
  (¿podía estar ahí?, ¿esa celda tiene un cofre?) y resuelve el resultado. El cliente nunca
  decide nada que importe.
- Una partida guardada son unos cientos de bytes: seed, posición, talismán, HP, y los sets
  de cosas ya consumidas.

El precio de esto es que **el generador tiene que existir dos veces** —en PHP y en
JavaScript— y producir exactamente el mismo laberinto. Bit a bit. Con un PRNG propio,
implementado igual en los dos lenguajes. Esa paridad es el corazón del proyecto y tiene su
propio test, que es el test más importante del repositorio.

## Stack

| Capa | Elección | Por qué |
|---|---|---|
| Backend | Laravel 12 | Migraciones, Eloquent, Pest, comandos Artisan para validar seeds offline |
| Frontend | Alpine.js + `fetch` | El estado del juego vive en el cliente; el servidor solo arbitra |
| Render | `<canvas>` | Un grid de 100x100 en DOM no es viable |
| Estilos | Tailwind | — |
| DB | SQLite (dev) | Suficiente. La decisión de producción no hace falta todavía |
| Tests | Pest + Vitest | Vitest existe solo para el lado JS del test de paridad |

**Sin Livewire.** Livewire renderiza componentes en el servidor y difunde DOM. Es, con mejor
ropa, exactamente el problema que hundió la versión anterior. Esta decisión está cerrada.

**Sin autenticación.** Una partida se identifica con un token opaco en la URL. Cuando haga
falta login, se agrega.

## Estructura prevista

```
app/
  Game/                  Lógica pura: sin HTTP, sin DB, testeable sola
    Prng.php             PRNG determinista
    MazeGenerator.php    Backtracking recursivo desde un seed
    Talisman.php         Economía de gemas
    Rules.php            Combate, visión, costos
  Http/Controllers/      Endpoints delgados: validan y delegan
  Models/
    Run.php              Proyección del estado de partida
    Event.php            Log append-only, fuente de verdad

resources/js/
  maze.js                Puerto en JS del generador. Espejo de app/Game/
  prng.js                Puerto en JS del PRNG. Espejo de app/Game/Prng.php
  game.js                Estado del cliente, input, render sobre canvas

docs/
  DISENO.md              Diseño vivo del juego. Manda sobre el código
  DECISIONES.md          Bitácora append-only de decisiones y descartes
  PROTOCOLO_GENERADOR.md Spec del PRNG y del algoritmo, agnóstico de lenguaje
```

## Setup

Todavía no hay nada que levantar. Cuando lo haya, va acá.

## Orden de trabajo

**El generador y su test de paridad vienen antes que cualquier pixel.** Si un mismo seed no
produce el mismo laberinto en PHP y en JS, todo lo demás es humo. Recién con eso en verde
tiene sentido dibujar algo.

Ver `ROADMAP.md`.

## Lo que falta decidir

Está anotado en `CLAUDE.md` y se discute antes de codearse:

- Qué habilidad del jugador mide el juego.
- La economía exacta del talismán.
- La ficción. Tentativamente arcana, por herencia. No cerrada.
- El comportamiento de los monstruos. Un monstruo que persigue con A* convierte esto en un
  juego de reflejos, que es justo lo que un juego por turnos no puede dar. Probablemente
  sean estáticos, telegrafiados, o se muevan solo cuando el jugador se mueve.
