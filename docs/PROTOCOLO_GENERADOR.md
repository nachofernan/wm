# Protocolo del generador — Wizard's Maze

Especificación del PRNG y del algoritmo de laberinto, **en prosa y pseudocódigo, agnóstica
de PHP y de JS**. Es el contrato que las dos implementaciones cumplen. Si el algoritmo
cambia, cambia **acá primero**, y ese cambio es destructivo: invalida todos los seeds
guardados y rompe la paridad. No se toca sin avisarlo como lo que es.

> **Estado de este documento: CERRADO**, salvo el set de seeds de test fijos, que se elige
> junto con el test de paridad en la Fase 1 (ver checklist al final). PRNG, `randBelow`,
> tamaño de grid y hash quedaron firmados el 2026-07-09 — ver `DECISIONES.md` 008.

---

## 0. Por qué existe este contrato

El cliente regenera el laberinto desde el seed y el servidor lo regenera por su cuenta para
validar. Si las dos regeneraciones no dan **exactamente** el mismo laberinto, la validación
del servidor es imposible. Por eso las dos implementaciones no pueden "parecerse": tienen
que producir el mismo output bit a bit. Este documento es lo que hace eso posible sin que
PHP y JS tengan que mirarse el código.

## 1. Requisitos duros (cerrados)

1. PRNG determinista propio, idéntico en PHP y JS. **Prohibido** `mt_rand`, `rand`,
   `random_int`, `Math.random`, `shuffle`, y cualquier fuente de entropía del sistema.
2. Toda la aritmética del PRNG opera sobre **enteros sin signo de 32 bits**. Es el ancho que
   PHP (64-bit) y JS (doubles + operadores `| 0`, `>>> 0`) pueden acordar sin ambigüedad.
3. **Orden de iteración explícito** en todo el algoritmo. Nada que dependa del orden de
   claves de un hash, de `Object.keys`, ni de ninguna implementación interna.
4. La única entrada es el `seed` (entero). La única salida relevante para el test es el
   **hash del laberinto** (§6).

## 2. El PRNG — mulberry32

Candidato por ser trivial de portar y probado. Estado: un entero de 32 bits.

Pseudocódigo (todas las operaciones en `uint32`, `⊕` es XOR, `≫`/`≪` son shifts lógicos,
`⊗` es multiplicación truncada a 32 bits):

```
estado ← seed (uint32)

next():                      # devuelve un uint32
    estado ← (estado + 0x6D2B79F5) mod 2^32
    t ← estado
    t ← t ⊗ (t ⊕ (t ≫ 15)) | 1
    t ← t ⊕ (t + (t ⊗ (t ⊕ (t ≫ 7)) | 61))
    return (t ⊕ (t ≫ 14)) mod 2^32
```

Notas de portabilidad (donde se rompe la paridad si uno se descuida):

- En JS: forzar `>>> 0` después de cada operación que pueda desbordar; `Math.imul` para las
  multiplicaciones de 32 bits (no `*`, que pierde precisión).
- En PHP: enmascarar con `& 0xFFFFFFFF` tras cada operación; `>>` es aritmético, usar máscara
  para emular shift lógico.
- El test de paridad del PRNG (secuencia de N valores desde un seed fijo) es lo que atrapa
  cualquier divergencia acá antes de que llegue al laberinto.

### 2.1 Derivar un entero en rango `[0, n)`  — mod directo

```
randBelow(n):                # n pequeño (≤ 4 para vecinos)
    return next() mod n
```

Sin rechazo. El sesgo que introduce un `mod` directo sobre `n ≤ 4` es despreciable para un
juego (no es criptografía), y evita el costo y la complejidad de un loop de rechazo.

## 3. El laberinto — modelo

- Grid de `W × H` celdas. Tamaño canónico: `W = H = 100` (heredado del proyecto viejo). El
  generador no hard-codea el tamaño: entra por parámetro (`W`, `H` son argumentos de
  `generar`), así que un tamaño distinto sigue siendo válido para playgrounds o pruebas.
- Cada celda tiene cuatro paredes: Norte, Este, Sur, Oeste. Una arista entre dos celdas
  adyacentes está "abierta" o "cerrada".
- El laberinto es **perfecto**: árbol generador del grid, un único camino entre dos celdas,
  sin ciclos. (❓ Si más adelante se quieren ciclos/atajos, es una extensión posterior y
  destructiva.)

### 3.1 Orden canónico de direcciones (cerrado en cuanto se firme)

Las direcciones se recorren **siempre** en este orden fijo: **N, E, S, O** (0,1,2,3). Este
orden es parte del contrato: cambiarlo cambia todos los laberintos.

```
N=0  → (dx= 0, dy=-1)
E=1  → (dx=+1, dy= 0)
S=2  → (dx= 0, dy=+1)
O=3  → (dx=-1, dy= 0)
```

## 4. El algoritmo — backtracking recursivo (iterativo con pila)

Se implementa **iterativo con pila explícita**, no recursivo, para no depender del límite de
stack de cada lenguaje y para que el orden sea inequívoco.

```
generar(seed, W, H):
    prng ← PRNG(seed)
    visitada ← grilla W×H de false
    pila ← []
    inicio ← celda (0, 0)                 # esquina superior izquierda
    marcar inicio como visitada
    pila.push(inicio)

    mientras pila no esté vacía:
        actual ← pila.top()
        vecinos ← direcciones N,E,S,O cuyo destino está dentro del grid
                   y NO visitado, en ese orden
        si vecinos está vacío:
            pila.pop()                    # backtrack
        si no:
            elegido ← vecinos[ randBelow(len(vecinos)) ]
            abrir la pared entre actual y elegido (en ambas celdas)
            marcar elegido como visitado
            pila.push(elegido)
```

Puntos donde la paridad se juega:

- La lista `vecinos` se construye **siempre** en orden N,E,S,O antes de indexar con el PRNG.
  Si un lenguaje la arma en otro orden, los laberintos divergen.
- `randBelow` consume del PRNG exactamente las veces que dice §2.1. Un consumo de más o de
  menos desincroniza las dos secuencias para siempre.

## 5. Ubicación de contenidos ❓

Dónde caen la llave, la salida, los cofres y los monstruos **no está diseñado todavía**
(depende de `DISENO.md`). Cuando se defina, se hace **también como función pura del seed**,
consumiendo del mismo PRNG *después* de generar el laberinto, con su propio orden explícito.
Hasta entonces, el generador produce solo la topología (paredes).

## 6. Hash del laberinto — para el test de paridad

El test de paridad compara un hash del laberinto generado, no la matriz. Propuesta de
serialización canónica antes de hashear:

1. Recorrer las celdas en orden **fila por fila** (`y` de 0 a H-1, `x` de 0 a W-1).
2. Por celda, emitir 4 bits: pared N, E, S, O (1 = cerrada, 0 = abierta), en ese orden.
3. Concatenar en un stream de bytes y hashear con **SHA-256** (existe idéntico en PHP y JS).
4. Comparar los hashes hex. Iguales ⇒ paridad.

Los **seeds de test son fijos y están commiteados** (nunca aleatorios). El vector de test
vive junto a los tests, no acá.

---

## Checklist para cerrar este protocolo (desbloquea Fase 1)

- [x] PRNG elegido y firmado: mulberry32.
- [x] `randBelow`: mod directo.
- [x] Tamaño del grid y celda de inicio: 100x100, inicio en (0,0).
- [x] Serialización y algoritmo de hash del §6: SHA-256 sobre el recorrido fila por fila.
- [ ] Set de seeds de test fijos — se eligen junto con el test de paridad en la Fase 1;
      viven junto al test, no acá (ver §6).

Ver `DECISIONES.md` 008. Con esto cerrado, arranca la Fase 1: `Prng.php` / `prng.js`.
