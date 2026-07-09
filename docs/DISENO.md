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

## 2. Qué habilidad mide el juego ❓

Ésta es la pregunta madre; todo lo demás cuelga de ella. Las candidatas:

- **Attrition (gestión de recursos).** El jugador administra un pozo que se agota. Cada
  decisión es "¿gasto ahora o aguanto?". La tensión es la escasez.
- **Deducción (información incompleta).** El jugador infiere el laberinto/peligros desde
  pistas parciales. La tensión es la incertidumbre.
- **Planificación (ruta).** El jugador optimiza un recorrido conocido bajo restricciones.
  La tensión es el compromiso entre objetivos.

No están decididas ni son excluyentes, pero una tiene que ser la primaria. **No se resuelve
por acá: se charla.** Hasta que se cierre, ninguna feature de la Fase 4 se construye.

## 3. La economía del talismán 🧪

**Hipótesis central en juego:** *el talismán es simultáneamente el poder y la vida.*

- El talismán tiene cuatro gemas elementales, cada una con un nivel.
- La combinación de niveles define de qué es capaz el mago: cuánto ve, cuánto aguanta, qué
  hechizos puede lanzar.
- **Cada hechizo consume nivel de gema.** Gastar poder es gastarse a uno mismo.
- **El radio de visión sale del talismán:** ver más lejos cuesta. Ver más es durar menos.
- Corolario: un mago maximizado es un mago que está por morir.

Lo que esto implica y **está abierto**:

- ❓ Cómo se mapea el nivel de cada gema a visión / HP / hechizos disponibles.
- ❓ Si las cuatro gemas hacen cosas distintas (elementos con roles) o son fungibles.
- ❓ Cómo se recupera nivel (cofres, gemas sueltas en el mapa) y a qué ritmo.
- ❓ Los costos concretos: cuánto cuesta un paso de visión, cuánto un hechizo.

Todo esto es la economía fina y depende de la sección 2. No se cierra suelto.

## 4. Qué significa ganar 🧪

**Salir del laberinto no es la victoria. La victoria es salir *con algo*.** Llegar a la
salida con las manos vacías es sobrevivir, no ganar. El juego es la tensión entre bajar más
(más botín, más riesgo, más gasto de talismán) y salir a tiempo.

- ❓ Qué es ese "algo": la llave, botín acumulado, gemas, un objetivo por partida.
- ❓ Si hay grados de victoria o es binario.

## 5. Los monstruos ❓

Restricción dura del stack: es un juego **por turnos** sobre un stack que no da reflejos. Un
monstruo que persigue por A* lo convierte en un juego de reacción, que es justo lo que no se
puede dar. Las direcciones viables:

- **Estáticos** — guardan una celda o un pasaje; el peligro es posicional.
- **Telegrafiados** — anuncian su acción con anticipación; el jugador responde con
  información, no con velocidad.
- **Reactivos al turno** — solo se mueven cuando el jugador se mueve (lockstep).

Sin decidir. Probablemente una mezcla. Se charla.

## 6. Los cofres ❓

- ❓ Qué contienen (nivel de gema, llave, botín, trampa).
- ❓ Si abrir cuesta algo (turno, riesgo, gema).
- ❓ Cuántos hay y cómo se distribuyen en el laberinto.

Depende de la economía (sección 3): un cofre importa solo si cambia una decisión.

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

1. **Qué habilidad mide el juego** (§2). Bloquea todo lo demás.
2. **La economía del talismán** (§3). Bloquea combate, cofres, visión, victoria.
3. **Comportamiento de monstruos** (§5).
4. **Contenido de cofres** (§6) y **condición de victoria concreta** (§4).
5. **La ficción** (§8). Se cierra al final.

Nada de esto se codea hasta cerrarse. El generador (Fases 0–1 del roadmap) no depende de
ninguna de estas preguntas y por eso va primero.
