<?php

namespace App\Game;

/**
 * PRNG determinista (mulberry32), espejo bit a bit de resources/js/prng.js.
 * Ver docs/PROTOCOLO_GENERADOR.md §2. Toda la aritmética emula uint32; PHP
 * usa enteros de 64 bits, así que cada operación que puede desbordar 32 bits
 * se trunca explícitamente con `& 0xFFFFFFFF`.
 */
final class Prng
{
    private int $estado;

    public function __construct(int $seed)
    {
        $this->estado = $seed & 0xFFFFFFFF;
    }

    public function next(): int
    {
        $this->estado = ($this->estado + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->estado;
        $t = self::mul32($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + self::mul32($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

        return ($t ^ ($t >> 14)) & 0xFFFFFFFF;
    }

    public function randBelow(int $n): int
    {
        return $this->next() % $n;
    }

    /**
     * Multiplicación truncada a 32 bits sin signo — equivalente a Math.imul
     * en JS. Se parte en mitades de 16 bits porque a*b puede pasarse de los
     * 63 bits utilizables de un int de PHP antes de truncar.
     */
    private static function mul32(int $a, int $b): int
    {
        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;

        return ((($aHi * $bLo + $aLo * $bHi) << 16) + $aLo * $bLo) & 0xFFFFFFFF;
    }
}
