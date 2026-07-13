<?php

namespace App\Game;

/**
 * Gestión del talismán fuera de combate (docs/DECISIONES.md 018, cierra el
 * pendiente in-run): equipar (fieldear) y guardar gemas para armar el loadout,
 * desguazar una gema en esencia, fusionar dos en una superior, y subir de nivel
 * con esa esencia. Es lógica pura sobre el blob del talismán (arrays), sin HTTP
 * ni DB — se testea sin levantarla (CLAUDE.md, excepción de app/Game/).
 *
 * Números de arranque (tuning). Fieldear tiene DOS topes que conviven (025): la
 * SUMA de niveles de las gemas fieldeadas no supera el cap (011), y el CONTEO de
 * gemas fieldeadas no supera las ranuras. Con pocas gemas grandes ata el cap;
 * con muchas chicas atan las ranuras. La refieldeada CON fricción durante el
 * combate (011) queda para más adelante: acá solo se reordena entre peleas.
 */
final class Talisman
{
    /** Tope de gemas fieldeadas a la vez (conteo, no suma de niveles) — 025. */
    public const RANURAS = 6;

    /**
     * Carga máxima de una gema por nivel (026): una gema de nivel N tope a N×6 de
     * carga. La carga es el combustible interno de la gema (⚡, se gasta al lanzar
     * hechizos), distinta de la `esencia` pura del talismán (sube nivel / cura).
     * Hoy solo la fusión puede intentar pasarse de este tope; ahí se recorta.
     */
    public const CARGA_POR_NIVEL = 6;

    /**
     * Progresión maestra (docs/DECISIONES.md 024): el nivel del talismán deriva
     * el cap y los stats base de la hoja de personaje (014). Números de arranque
     * (tuning) elegidos para mantener el mago inicial (4×n3 = cap 12, defensa 8).
     */
    public const CAP_BASE = 12;      // cap a nivel 1

    public const CAP_POR_NIVEL = 10; // +cap por cada nivel

    public const DEF_BASE = 8;       // defensa a nivel 1

    public const DEF_POR_NIVEL = 4;  // +defensa base por cada nivel

    public const COSTO_NIVEL = 10;   // esencia para subir de nivel N a N+1 = N × esto

    /**
     * Esencia pura que cuesta fusionar dos gemas (docs/DECISIONES.md 027). Además
     * del costo de oportunidad que la fusión ya tenía (rinde menos esencia que
     * desguazar las dos por separado), este costo directo la mete en la economía
     * de esencia —compite con subir nivel y curar— y frena holdear gemas para
     * fusionarlas gratis en masa. Número de arranque (tuning).
     */
    public const COSTO_FUSION = 1;

    /**
     * Acople gema→stat (modelo A, 024): las gemas fieldeadas con carga aportan
     * a la hoja según su elemento. Números de arranque (tuning).
     */
    public const ATK_POR_NIVEL = 0.05;   // fuego: +5% de ataque por nivel de gema fieldeada

    public const DEF_POR_NIVEL_GEMA = 3; // agua: +3 de defensa por nivel de gema fieldeada

    /**
     * Aplica una acción y devuelve el talismán nuevo, o un error si la acción
     * no es legal (no toca nada en ese caso). `$gemaId2` solo lo usa 'fusionar'
     * (la segunda gema); el resto de las acciones lo ignoran.
     *
     * @return array{talisman: array, error: string|null}
     */
    public static function aplicar(array $talisman, string $accion, ?int $gemaId, ?int $gemaId2 = null): array
    {
        return match ($accion) {
            'fieldear' => self::fieldear($talisman, $gemaId),
            'guardar' => self::guardar($talisman, $gemaId),
            'desguazar' => self::desguazar($talisman, $gemaId),
            'fusionar' => self::fusionar($talisman, $gemaId, $gemaId2),
            'vaciar' => self::vaciar($talisman),
            'subirNivel' => self::subirNivel($talisman),
            'curar' => self::curar($talisman),
            default => self::error($talisman, 'acción desconocida'),
        };
    }

    /** Cap del talismán a un nivel dado (proyección escalonada, 024). */
    public static function capDeNivel(int $nivel): int
    {
        return self::CAP_BASE + ($nivel - 1) * self::CAP_POR_NIVEL;
    }

    /**
     * Defensa base a un nivel dado, antes del acople de gemas. El roll-up
     * gema→defensa (agua) se suma acá en el paso 2 del 024; hoy es solo la base.
     */
    public static function defensaDeNivel(int $nivel): int
    {
        return self::DEF_BASE + ($nivel - 1) * self::DEF_POR_NIVEL;
    }

    /** Esencia para subir de $nivel al siguiente (024). */
    public static function costoNivel(int $nivel): int
    {
        return $nivel * self::COSTO_NIVEL;
    }

    /**
     * Recalcula los stats derivados de la hoja desde el nivel del talismán y el
     * acople de las gemas fieldeadas con carga (modelo A, 024/025): eje
     * ofensivo fuego+aire → ataque (multiplicador `ataqueMult`), eje defensivo
     * agua+tierra → defensa (sumando al ratio, sobre la defensa base del nivel).
     * El mapeo es interino (025): cuando existan visión y memoria, aire y tierra
     * van a llevar esos stats y su aporte a atk/def probablemente se achique. Se
     * llama tras cada mutación exitosa (ver ok()):
     * cap, defensa y ataqueMult son proyección cacheada en el blob, no fuente de
     * verdad. El `?? 1` tolera blobs viejos sin nivel (dev, runs desechables).
     */
    public static function recomputar(array $talisman): array
    {
        $nivel = $talisman['nivel'] ?? 1;

        $ataqueGema = 0.0;
        $defensaGema = 0;
        foreach ($talisman['gemas'] as $g) {
            if (! $g['fieldeada'] || $g['carga'] <= 0) {
                continue; // una gema inerte no potencia la hoja (012)
            }
            if ($g['elemento'] === 'fuego' || $g['elemento'] === 'aire') {
                $ataqueGema += $g['nivel'] * self::ATK_POR_NIVEL;
            } elseif ($g['elemento'] === 'agua' || $g['elemento'] === 'tierra') {
                $defensaGema += $g['nivel'] * self::DEF_POR_NIVEL_GEMA;
            }
        }

        $talisman['cap'] = self::capDeNivel($nivel);
        $talisman['defensa'] = self::defensaDeNivel($nivel) + $defensaGema;
        $talisman['ataqueMult'] = round($ataqueGema, 4);

        return $talisman;
    }

    /** Equipar una gema del inventario, si hay ranura libre y entra en el cap (025). */
    private static function fieldear(array $talisman, ?int $id): array
    {
        $g = self::gema($talisman, $id);
        if ($g === null || $g['fieldeada']) {
            return self::error($talisman, 'gema inválida');
        }
        if (self::ranurasEnUso($talisman) >= self::RANURAS) {
            return self::error($talisman, 'no hay ranura libre');
        }
        if (self::capEnUso($talisman) + $g['nivel'] > $talisman['cap']) {
            return self::error($talisman, 'no entra en el cap');
        }

        return self::setFieldeada($talisman, $id, true);
    }

    /** Sacar una gema del talismán al inventario. */
    private static function guardar(array $talisman, ?int $id): array
    {
        $g = self::gema($talisman, $id);
        if ($g === null || ! $g['fieldeada']) {
            return self::error($talisman, 'gema inválida');
        }

        return self::setFieldeada($talisman, $id, false);
    }

    /** Fundir una gema del inventario en esencia (+nivel). Una gema fieldeada no se desguaza. */
    private static function desguazar(array $talisman, ?int $id): array
    {
        $g = self::gema($talisman, $id);
        if ($g === null || $g['fieldeada']) {
            return self::error($talisman, 'gema inválida');
        }

        $talisman['esencia'] += $g['nivel'];
        $talisman['gemas'] = array_values(array_filter(
            $talisman['gemas'], fn ($x) => $x['id'] !== $id,
        ));

        return self::ok($talisman);
    }

    /**
     * Fusiona dos gemas guardadas del mismo elemento y nivel en una de nivel+1
     * (025): la carga se suma, sin penalización, pero recortada al tope de la
     * gema nueva —N×6 (026)—: el sobrante se pierde. Cuesta COSTO_FUSION de
     * esencia pura (027); sin esa esencia, no se fusiona. Solo entre gemas del
     * inventario (no fieldeadas), como desguazar — es manejo de loadout entre
     * peleas. La gema resultante nace guardada con un id fresco (proximoId).
     */
    private static function fusionar(array $talisman, ?int $idA, ?int $idB): array
    {
        if ($idA === null || $idB === null || $idA === $idB) {
            return self::error($talisman, 'elegí dos gemas distintas');
        }
        $a = self::gema($talisman, $idA);
        $b = self::gema($talisman, $idB);
        if ($a === null || $b === null || $a['fieldeada'] || $b['fieldeada']) {
            return self::error($talisman, 'gema inválida');
        }
        if ($a['elemento'] !== $b['elemento'] || $a['nivel'] !== $b['nivel']) {
            return self::error($talisman, 'no coinciden tipo y nivel');
        }
        if ($talisman['esencia'] < self::COSTO_FUSION) {
            return self::error($talisman, 'esencia insuficiente para fusionar');
        }
        $talisman['esencia'] -= self::COSTO_FUSION;

        $nivel = $a['nivel'] + 1;
        $nueva = [
            'id' => $talisman['proximoId'],
            'elemento' => $a['elemento'],
            'nivel' => $nivel,
            'carga' => min($a['carga'] + $b['carga'], $nivel * self::CARGA_POR_NIVEL),
            'fieldeada' => false,
        ];
        $talisman['proximoId']++;
        $talisman['gemas'] = array_values(array_filter(
            $talisman['gemas'], fn ($x) => $x['id'] !== $idA && $x['id'] !== $idB,
        ));
        $talisman['gemas'][] = $nueva;

        return self::ok($talisman);
    }

    /**
     * Manda todas las gemas fieldeadas al inventario de un saque (027). Guardar
     * nunca se rechaza, así que vaciar siempre es legal y no cuesta nada; si no
     * había ninguna fieldeada, el estado queda igual (recomputar es idempotente).
     */
    private static function vaciar(array $talisman): array
    {
        foreach ($talisman['gemas'] as &$g) {
            $g['fieldeada'] = false;
        }
        unset($g);

        return self::ok($talisman);
    }

    /**
     * Sube un nivel del talismán con esencia pura (progresión maestra, 014/024):
     * el nivel deriva el cap y los stats base, así que recomputar() (vía ok())
     * los actualiza solo. Reemplaza el viejo subirCap punto-a-punto (011).
     */
    private static function subirNivel(array $talisman): array
    {
        $costo = self::costoNivel($talisman['nivel']);
        if ($talisman['esencia'] < $costo) {
            return self::error($talisman, 'esencia insuficiente');
        }
        $talisman['esencia'] -= $costo;
        $talisman['nivel'] += 1;

        return self::ok($talisman);
    }

    /**
     * Convierte esencia pura en vida, 1:1 (DECISIONES.md 021). Solo fuera de
     * combate (el controlador ya lo garantiza). No desperdicia: sana lo mínimo
     * entre la esencia que tenés y lo que te falta para el tope de vida.
     */
    private static function curar(array $talisman): array
    {
        if ($talisman['esencia'] <= 0) {
            return self::error($talisman, 'sin esencia');
        }
        if ($talisman['vida'] >= $talisman['vidaMax']) {
            return self::error($talisman, 'vida llena');
        }

        $sana = min($talisman['esencia'], $talisman['vidaMax'] - $talisman['vida']);
        $talisman['esencia'] -= $sana;
        $talisman['vida'] += $sana;

        return self::ok($talisman);
    }

    /** Suma de niveles de las gemas fieldeadas (tope: cap). */
    public static function capEnUso(array $talisman): int
    {
        $suma = 0;
        foreach ($talisman['gemas'] as $g) {
            if ($g['fieldeada']) {
                $suma += $g['nivel'];
            }
        }

        return $suma;
    }

    /** Cuántas gemas están fieldeadas (tope: RANURAS) — 025. */
    public static function ranurasEnUso(array $talisman): int
    {
        $n = 0;
        foreach ($talisman['gemas'] as $g) {
            if ($g['fieldeada']) {
                $n++;
            }
        }

        return $n;
    }

    private static function gema(array $talisman, ?int $id): ?array
    {
        foreach ($talisman['gemas'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }

        return null;
    }

    private static function setFieldeada(array $talisman, int $id, bool $valor): array
    {
        foreach ($talisman['gemas'] as &$g) {
            if ($g['id'] === $id) {
                $g['fieldeada'] = $valor;
                break;
            }
        }

        return self::ok($talisman);
    }

    private static function ok(array $talisman): array
    {
        return ['talisman' => self::recomputar($talisman), 'error' => null];
    }

    private static function error(array $talisman, string $motivo): array
    {
        return ['talisman' => $talisman, 'error' => $motivo];
    }
}
