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
    /** Esencia por +1 de cap (valor de arranque, ver 011/015). */
    public const COSTO_CAP = 5;

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
            'subirCap' => self::subirCap($talisman),
            'curar' => self::curar($talisman),
            default => self::error($talisman, 'acción desconocida'),
        };
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

    /** Subir el cap con esencia. */
    private static function subirCap(array $talisman): array
    {
        if ($talisman['esencia'] < self::COSTO_CAP) {
            return self::error($talisman, 'esencia insuficiente');
        }
        $talisman['esencia'] -= self::COSTO_CAP;
        $talisman['cap'] += 1;

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
        return ['talisman' => $talisman, 'error' => null];
    }

    private static function error(array $talisman, string $motivo): array
    {
        return ['talisman' => $talisman, 'error' => $motivo];
    }
}
