<?php

namespace App\Game;

/**
 * Gestión del talismán fuera de combate (docs/DECISIONES.md 018, cierra el
 * pendiente in-run): equipar (fieldear) y guardar gemas para armar el loadout,
 * desguazar una gema en esencia, y subir el cap con esa esencia. Es lógica pura
 * sobre el blob del talismán (arrays), sin HTTP ni DB — se testea sin levantarla
 * (CLAUDE.md, excepción de app/Game/).
 *
 * Números de arranque (tuning). El cap es un tope sobre la SUMA de niveles de
 * las gemas fieldeadas (011): meter una gema alta puede obligar a sacar otra.
 * La refieldeada CON fricción durante el combate (011) queda para más adelante:
 * acá solo se reordena entre peleas.
 */
final class Talisman
{
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
     * Aplica una acción y devuelve el talismán nuevo, o un error si la acción
     * no es legal (no toca nada en ese caso).
     *
     * @return array{talisman: array, error: string|null}
     */
    public static function aplicar(array $talisman, string $accion, ?int $gemaId): array
    {
        return match ($accion) {
            'fieldear' => self::fieldear($talisman, $gemaId),
            'guardar' => self::guardar($talisman, $gemaId),
            'desguazar' => self::desguazar($talisman, $gemaId),
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
     * Recalcula los stats derivados de la hoja (cap, defensa) desde el nivel del
     * talismán —y, desde el paso 2 del 024, desde el acople de las gemas
     * fieldeadas—. Se llama tras cada mutación exitosa (ver ok()): cap y defensa
     * son proyección cacheada en el blob, no fuente de verdad. El `?? 1` tolera
     * blobs viejos sin nivel (dev, runs desechables).
     */
    public static function recomputar(array $talisman): array
    {
        $nivel = $talisman['nivel'] ?? 1;
        $talisman['cap'] = self::capDeNivel($nivel);
        $talisman['defensa'] = self::defensaDeNivel($nivel);

        return $talisman;
    }

    /** Equipar una gema del inventario, si entra en el cap. */
    private static function fieldear(array $talisman, ?int $id): array
    {
        $g = self::gema($talisman, $id);
        if ($g === null || $g['fieldeada']) {
            return self::error($talisman, 'gema inválida');
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

    /** Suma de niveles de las gemas fieldeadas. */
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
