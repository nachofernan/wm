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

**Modelo cerrado (ver `DECISIONES.md` 010, 011, 013, 014, 015):** el talismán es un **loadout**,
no un pozo indiferenciado. **El mago es solo vida; el talismán es toda la hoja de personaje**
(ataque, defensa, visión, memoria, bonus crítico…), y las gemas la potencian.

- **Talismán = la hoja de personaje (014).** El **nivel del talismán** es la progresión maestra:
  sube stats base y el **cap** (escalonado: nivel 1 → 10, nivel 2 → 20…). El cap es un tope sobre
  la **suma** de niveles de las gemas fieldeadas; bajo ese tope se reparte (una gema alta y
  frágil, o varias medias). Las gemas potencian los stats con **acople suelto** (fuego~ataque,
  aire~visión, tierra~memoria, agua~defensa; no 1:1 — una gema puede tocar varios). Fieldear una
  gema nunca es solo DPS: mueve la hoja entera. El ataque sigue siendo gema-a-gema (012).
- **Visión = revelado gradual de datos de celda (014)**, nunca a través de muros: de "solo tu
  celda" a "tres celdas a la redonda + color antes de pisar". Es previsión, y **cuesta cap** (no
  vida — ver 013).
- **Memoria y niebla en tres zonas (014/016).** El mapa se recuerda en tres estados: **oscura**
  (no visitada), **gris** (visitada pero sin detalle — "pasé, no sé si tenía colmena"), **blanca**
  (visión actual + rastro de las últimas N celdas). El largo del rastro lo potencia la memoria
  (tierra). Posición y visitadas viven en la caché `runs`, no en eventos (016).
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
- **Esencia única en dos estados (015).** La **esencia ligada** es la carga dentro de la gema
  (combate, 012). La **esencia pura** (el oro del juego) sale de **funguear** una gema: rendís un
  % de la esencia que le *queda* (así, cada tiro baja lo que vale funguearla — "usarla ahora o
  cobrarla después"). La esencia pura **compra niveles** del talismán (014). Una gema en 0 no se
  funguea; fuera del talismán es una roca muerta.
- **Vida (013, revierte la 010).** La vida es del mago, no del talismán, y **no hay drenaje
  pasivo**: tener poder o quedarte sin él no le saca vida por existir. La vida solo la amenaza el
  **combate** — comer un golpe o el último sacudón (gastarla *por decisión*, 012). Vida 0 =
  derrota, pero se llega peleando. **La esencia es el único reloj** de la atrición.
- **Gema de dos ejes y resolución de combate (ver `DECISIONES.md` 012).** Cada gema tiene
  **nivel** (poder, fijo, cuenta para el cap) y **esencia** (carga, se gasta al atacar y
  defender; esencia 0 = piedra inerte, no suma a poder ni visión). El poder actual es la suma de
  niveles de las gemas fieldeadas con esencia. **Atacar cuesta esencia igual al nivel** de la
  gema (nivel alto = golpes caros = durás menos). El daño es por **ratio**
  (`poder × K/(K+defensa) × elemental × azar`), nunca muro: el alfeñique siempre araña. La
  defensa es **por elección**: comer el golpe (a la vida) o bloquearlo entero gastando esencia,
  más barato con el elemento con ventaja. La rueda elemental gobierna ataque *y* defensa.

La **forma** de la curva de cap está decidida: sube fuerte con el nivel, el crecimiento real lo
empujan las presas grandes (bosses/cofres), y el valor del contenido **pasado** decae relativo
a tu nivel actual — un bicho de un maze alto puede valer más que un boss de tres mazes atrás,
así que lo que se apaga es el back-farm, no el valor absoluto de un bicho. Drops y costos
escalan juntos con la dificultad del maze.

Lo que queda **abierto a propósito** (es tuning, se ajusta jugando — no de antemano):

- ❓ La **rueda elemental concreta**: qué elemento vence a cuál. Ahora es doblemente
  load-bearing (gobierna ataque *y* defensa, ver 012), así que hay que diseñarla en serio.
- ❓ Los **valores** de combate (nivel, esencia, `K`, `F`, `C`, peso, crítico) — arranque en la
  012, se fijan con el prototipo de tuning.
- ❓ Los **números de la hoja de personaje (014)**: qué stat bumpea cada nivel del talismán, la
  curva de cap por nivel, los porcentajes del acople elemento→stat, la escala de visión, el largo
  del rastro de memoria y el % de rendimiento del funguéo (015).
- ❓ Costo en esencia de **moverse / esquivar** (la 011 los listaba; el combate 012 solo cerró
  atacar y defender).
- ❓ La **fricción de re-fieldear** (¿un turno?, ¿zona segura?).
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

## 5. Los monstruos 🔒 (combate telegrafiado; encuentros por celda) 

Restricción dura del stack: es un juego **por turnos** sobre un stack que no da reflejos. Un
monstruo que persigue por A* lo convierte en un juego de reacción, que es justo lo que no se
puede dar.

**El combate es telegrafiado (cerrado, ver 011):** el encuentro revela al guardián y su tipo
antes de comprometerse (p.ej. la llave muestra al monstruo protector), así el jugador responde
con el setup, no con reflejos. La información se da, no se gana muriendo.

**Encuentros por celda (cerrado, ver 016)** — reemplaza el `rand(1,20)` provisional:

- Cada celda tiene una **probabilidad de encuentro derivada del seed** (paritaria PHP/JS, va al
  `PROTOCOLO_GENERADOR.md`). Se **pinta**: color = elemento, alpha = probabilidad.
- **Colmenas:** una celda-núcleo de alta probabilidad que **irradia y decae** en las vecinas
  (15/13/10/7…/1) y **atraviesa muros**; limpiar el núcleo apaga la zona, y el núcleo puede ser
  casi inalcanzable.
- El **disparo** ("¿me saltó algo ahora?") es **secreto del servidor**, no sale del seed — si no,
  el cliente lo predice y no hay sorpresa. El sesgo es público (se pinta); la tirada no.
- El **paso viaja al servidor** para validar y tirar el dado (refinamiento del axioma 3, ver
  016), sin sincronizar el mapa. La llave sigue disparando su guardián **obligatorio** (011).

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
2. ~~La economía gruesa del talismán~~ (§3) — **cerrada** (011, 013, 014, 015); quedan los
   **números** (tuning, se ajustan jugando).
3. ~~Movimiento / spawn de los monstruos~~ (§5) — **cerrada**: encuentros por celda (016).
4. **La rueda elemental concreta** (§3) — doblemente load-bearing (ataque y defensa).
5. **Tabla de drops** (§6) y **condición de victoria concreta / fin de la secuencia** (§4).
6. **La ficción** (§8). Se cierra al final.

Nada de esto se codea hasta cerrarse. El generador (Fases 0–1 del roadmap) no depende de
ninguna de estas preguntas y por eso va primero.
