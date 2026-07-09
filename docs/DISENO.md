# Diseño — Wizard's Maze

Documento **vivo**. Es el que más va a cambiar y el que manda sobre el código. Acá vive la
mecánica: qué habilidad mide el juego, cómo funciona el talismán, qué hacen los monstruos,
qué hay en los cofres, qué significa ganar.

Cada sección se marca con su estado:

- 🔒 **cerrado** — decidido; si cambia, va a `DECISIONES.md` como reversión.
- 🧪 **hipótesis** — la dirección en la que estamos apostando, todavía sin cerrar.
- ❓ **abierto** — sin decidir; no se codea nada que dependa de esto.

> Regla: la mecánica manda sobre la ficción. Primero se sabe qué habilidad mide el juego,
> después de qué está hecho el mundo.

---

## 1. El pitch en una línea 🧪

Un mago entra a un laberinto que no conoce, ve pocas celdas a la redonda, y tiene que
decidir **cuánto laberinto puede pagar** antes de salir con algo.

## 2. Qué habilidad mide el juego 🔒

Ésta es la pregunta madre; todo lo demás cuelga de ella. **Cerrada (ver `DECISIONES.md` 011):
la primaria es la planificación, con attrition de sostén.** El jugador arma el talismán contra
encuentros telegrafiados y después gasta bien lo armado. Las candidatas que se pesaron:

- **Attrition (gestión de recursos).** El jugador administra un pozo que se agota. Cada
  decisión es "¿gasto ahora o aguanto?". La tensión es la escasez.
- **Deducción (información incompleta).** El jugador infiere el laberinto/peligros desde
  pistas parciales. La tensión es la incertidumbre.
- **Planificación (ruta).** El jugador optimiza un recorrido conocido bajo restricciones.
  La tensión es el compromiso entre objetivos.

**Planificación** ganó como primaria: la tensión es armar el talismán correcto contra lo que
el maze telegrafía, bajo la escasez de gemas. **Attrition** queda como capa de sostén (gastar
bien un loadout que no se recarga). **Deducción** se descartó como primaria: el combate es
telegrafiado, así que la información se da, no se infiere ni se gana muriendo.

## 3. La economía del talismán 🔒 (números abiertos)

**Modelo cerrado (ver `DECISIONES.md` 010 y 011):** el talismán es un **loadout**, no un pozo
indiferenciado. El PJ tiene dos stats con actual/máximo — **vida** y **poder** — acoplados por
umbral.

- **Talismán = cap.** El nivel del talismán es un tope sobre la **suma** de niveles de las
  gemas fieldeadas. Bajo ese tope se reparte: una gema alta y frágil, o varias medias y
  equilibradas. El poder disponible es el agregado de las gemas fieldeadas; de ahí salen
  visión y hechizos.
- **Gemas con rol.** Elementales, con ventaja de tipo frente a enemigos telegrafiados. No son
  fungibles: meter todo en un elemento te deja desnudo en los otros (por eso la
  especialización es frágil — ataque nivel dios, pero un soplido te mata).
- **Se gastan, no se recargan.** Cada acción (atacar, defender, moverse, esquivar) consume
  carga de gema. Una gema gastada no se rellena dentro de una vida; una muerta no revive. El
  poder se repone consiguiendo **gemas nuevas** (drops de monstruos, cofres, bosses), no
  descansando.
- **Roster y fielding.** Se poseen gemas (inventario) y se fieldean pocas bajo el cap.
  Re-fieldear tiene fricción (p.ej. cuesta un turno) — sin fricción, el counter perfecto es
  gratis y el combate se vuelve un lookup.
- **Desguace.** Una gema se puede fieldear (poder ahora) o desguazar en **esencia** para subir
  el cap del talismán. La misma gema no hace las dos cosas: chatarra → cap, premios → fieldear.
- **Vida y umbral (de la 010).** La vida es un stat propio del PJ. Mientras hay poder, la vida
  no se toca; al quedar sin poder, forzar (o quedarse ahí) drena vida. Vida en 0 = derrota.

La **forma** de la curva de cap está decidida: sube fuerte con el nivel, el crecimiento real lo
empujan las presas grandes (bosses/cofres), y el valor del contenido **pasado** decae relativo
a tu nivel actual — un bicho de un maze alto puede valer más que un boss de tres mazes atrás,
así que lo que se apaga es el back-farm, no el valor absoluto de un bicho. Drops y costos
escalan juntos con la dificultad del maze.

Lo que queda **abierto a propósito** (es tuning, se ajusta jugando — no de antemano):

- ❓ Los roles concretos de los cuatro elementos y cómo mapean a visión / hechizos / defensa.
- ❓ Todos los números: costo de cada acción, esencia por gema, los valores de la curva de cap.
- ❓ La fricción exacta de re-fieldear (¿un turno?, ¿solo en zona segura?).
- ❓ El ritmo de drops y su tabla (ver §6).

## 4. Qué significa ganar 🧪

**El juego es una secuencia de mazes de dificultad creciente** (monstruos, caminos, tamaño).
El talismán **persiste entre mazes**: lo que ganás en una corrida se **banca al extraer vivo**,
no al farmear. Salir con las manos vacías es sobrevivir; ganar es salir *con algo* — el
talismán más grande, gemas para el maze siguiente.

**La muerte** resetea el maze a su estado de entrada y restaura las gemas al loadout con el que
entraste: se pierde el progreso no bankeado de esa corrida. Revividas como tope diferido
(cantidad ❓); al agotarlas, game over.

- ❓ Qué es exactamente "con algo" y si hay grados de victoria.
- ❓ Dónde termina la secuencia y qué es la victoria final (engancha con la historia, §8).
- ❓ Cantidad de revividas y qué resetea el game over.

## 5. Los monstruos 🧪 (combate telegrafiado 🔒; movimiento ❓)

Restricción dura del stack: es un juego **por turnos** sobre un stack que no da reflejos. Un
monstruo que persigue por A* lo convierte en un juego de reacción, que es justo lo que no se
puede dar.

**El combate es telegrafiado (cerrado, ver 011):** el encuentro revela al guardián y su tipo
antes de comprometerse (p.ej. la llave muestra al monstruo protector), así el jugador responde
con el setup, no con reflejos. La información se da, no se gana muriendo.

El **movimiento** de los monstruos sigue ❓. Las direcciones viables:

- **Estáticos** — guardan una celda o un pasaje; el peligro es posicional.
- **Reactivos al turno** — solo se mueven cuando el jugador se mueve (lockstep).

Sin decidir. Probablemente una mezcla. Se charla.

## 6. Los cofres y los drops 🧪

Los **bosses y cofres garantizan gemas importantes** (grandes, fieldeables); los monstruos
comunes sueltan morralla para desguace, con chance baja (~1 en 100) de una gema grande —
variance como condimento, no como plan.

- ❓ La tabla de drops concreta y su escala por maze.
- ❓ Si abrir un cofre cuesta algo (turno, riesgo).
- ❓ Cuántos cofres/monstruos hay y cómo se distribuyen en el laberinto.

Regla que se mantiene: un drop importa solo si cambia una decisión.

## 7. La llave y la salida 🧪

Herencia del proyecto viejo: hay una llave y una salida. Tentativamente, la llave habilita
la salida (o la victoria "con algo"). ❓ Sin cerrar cómo se entrelaza con la condición de
victoria de la sección 4.

## 8. La ficción 🧪

Tentativamente **arcana**, por herencia del proyecto viejo: mago, talismán, gemas
elementales. **No cerrada.** La ficción se decide *después* de saber qué habilidad mide el
juego (sección 2). Si la mecánica pide otra piel, la ficción cede.

---

## Preguntas abiertas, en orden de bloqueo

1. ~~Qué habilidad mide el juego~~ (§2) — **cerrada**: planificación primaria (011).
2. ~~La economía gruesa del talismán~~ (§3) — **cerrada** (011); quedan los **números**
   (tuning, se ajustan jugando).
3. **Movimiento de los monstruos** (§5).
4. **Tabla de drops** (§6) y **condición de victoria concreta / fin de la secuencia** (§4).
5. **La ficción** (§8). Se cierra al final.

Nada de esto se codea hasta cerrarse. El generador (Fases 0–1 del roadmap) no depende de
ninguna de estas preguntas y por eso va primero.
