<?php

namespace App\Game;

/**
 * Resuelve un golpe de combate según docs/DECISIONES.md 012: daño por ratio
 * (nunca muro), ventaja elemental sobre ataque y defensa, y variación por azar
 * como mística (nunca decide vida/muerte). Es lógica pura y determinista: no
 * sabe que existen HTTP ni una base de datos, y se testea sin levantarla
 * (CLAUDE.md, excepción de app/Game/).
 *
 * La autoridad de combate vive acá, no en el cliente (axioma 4). El azar sale
 * del Prng del proyecto —no de mt_rand/Math.random— para que la resolución sea
 * reproducible en el replay de eventos (axioma 6): mismo seed → mismo golpe.
 *
 * Todos los números son de arranque (tuning, se ajustan jugando). El prototipo
 * de /pj los expone para toquetearlos antes de llevar esto a la Fase 4.
 */
final class CombatResolver
{
    /** Números de arranque — DECISIONES.md 012. Todo tuning, override por constructor. */
    public const DEFAULTS = [
        'F' => 3,          // poder = nivel * F
        'K' => 50,         // mordida de la defensa: mitigacion = K / (K + defensa)
        'vidaPorEsencia' => 3, // pagar esencia faltante con vida: vida = faltante * esto (penalidad)
        'critProb' => 10,  // % de crítico
        'critMult' => 1.75,
        'bandaMin' => 0.85,
        'bandaMax' => 1.15,
        // multiplicadores de ataque según el matchup del atacante vs el defensor
        'ventaja' => 1.5,
        'neutral' => 1.0,
        'reves' => 0.5,
        // factores de bloqueo: sobre el peso del ataque, según el matchup de la gema que bloquea
        'defVentaja' => 0.5,
        'defNeutral' => 1.0,
        'defReves' => 2.0,
    ];

    /**
     * Rueda elemental — cada elemento vence al que figura acá. PLACEHOLDER: la
     * rueda concreta sigue ❓ (docs/DISENO.md §3). La estructura sí está fija:
     * ciclo de cuatro, cada uno gana a uno y pierde contra uno (los otros dos
     * cruces son neutrales).
     */
    private const VENCE_A = [
        'fuego' => 'aire',
        'aire' => 'tierra',
        'tierra' => 'agua',
        'agua' => 'fuego',
    ];

    /** @var array<string, int|float> Reglas efectivas (DEFAULTS + overrides). */
    private array $reglas;

    /**
     * @param  Prng  $prng  fuente de la variación; inyectada para que el golpe sea reproducible.
     * @param  array<string, int|float>  $reglas  overrides sobre DEFAULTS (los sliders del prototipo).
     */
    public function __construct(private Prng $prng, array $reglas = [])
    {
        $this->reglas = array_merge(self::DEFAULTS, $reglas);
    }

    /**
     * Relación del elemento $a frente a $b: 'ventaja' si a vence a b, 'reves'
     * si b vence a a, 'neutral' en los otros dos cruces.
     */
    public static function matchup(string $a, string $b): string
    {
        if ((self::VENCE_A[$a] ?? null) === $b) {
            return 'ventaja';
        }
        if ((self::VENCE_A[$b] ?? null) === $a) {
            return 'reves';
        }

        return 'neutral';
    }

    /**
     * Un golpe completo: tira la variación desde el Prng y devuelve el desglose
     * para que el prototipo lo muestre. Consume dos draws del Prng (banda y
     * crítico, en ese orden). El costo en esencia es el nivel de la gema
     * (DECISIONES.md 012): nivel alto = golpe caro.
     *
     * `$bonusAtaque` es el acople gema→ataque de la hoja (fuego, 024): un
     * multiplicador `(1 + bonus)` sobre el daño. Solo lo pasa el mago; los
     * monstruos atacan sin bono (default 0), así que su resolución no cambia.
     *
     * @return array{
     *     poder:int, mitigacion:float, matchup:string, mult:float,
     *     banda:float, critico:bool, variacion:float, dano:int, costoEsencia:int
     * }
     */
    public function golpe(int $nivel, string $elementoAtacante, int $defensa, string $elementoDefensor, float $bonusAtaque = 0.0): array
    {
        $banda = $this->reglas['bandaMin']
            + ($this->prng->next() / 0xFFFFFFFF) * ($this->reglas['bandaMax'] - $this->reglas['bandaMin']);
        $critico = $this->prng->randBelow(100) < $this->reglas['critProb'];
        $variacion = $critico ? $banda * $this->reglas['critMult'] : $banda;

        $mate = self::matchup($elementoAtacante, $elementoDefensor);

        return [
            'poder' => $nivel * (int) $this->reglas['F'],
            'mitigacion' => $this->mitigacion($defensa),
            'matchup' => $mate,
            'mult' => $this->multAtaque($mate),
            'banda' => $banda,
            'critico' => $critico,
            'variacion' => $variacion,
            'dano' => $this->dano($nivel, $elementoAtacante, $defensa, $elementoDefensor, $variacion, $bonusAtaque),
            'costoEsencia' => $nivel,
        ];
    }

    /**
     * Daño determinista dado un factor de variación explícito. Es el núcleo
     * testeable de golpe(): daño = max(1, round(poder × mitigación × mult ×
     * variación × (1 + bonusAtaque))). Ratio, nunca cero: el alfeñique siempre
     * araña. `$bonusAtaque` es el acople gema→ataque de la hoja (fuego, 024);
     * default 0 = sin bono (monstruos, y todo el que no lo pase).
     */
    public function dano(int $nivel, string $elementoAtacante, int $defensa, string $elementoDefensor, float $variacion, float $bonusAtaque = 0.0): int
    {
        $poder = $nivel * (int) $this->reglas['F'];
        $mult = $this->multAtaque(self::matchup($elementoAtacante, $elementoDefensor));
        $bruto = $poder * $this->mitigacion($defensa) * $mult * $variacion * (1 + $bonusAtaque);

        return max(1, (int) round($bruto));
    }

    /**
     * Costo en esencia de bloquear un golpe entero (bloqueo completo o nada,
     * DECISIONES.md 012): peso del ataque escalado por dos factores. El fuerte,
     * que el jugador elige activamente cada golpe, es el matchup de la gema que
     * bloquea (×0.5/×1/×2 — bloquear con el elemento equivocado funde la gema el
     * doble de rápido). Encima va un descuento parejo de fondo por la defensa del
     * mago (DECISIONES.md 036): reusa `mitigacion()`, la MISMA curva K/(K+defensa)
     * con que el monstruo mitiga el daño, para no meter una tercera curva de
     * tuning y darle a la defensa del talismán la misma forma (%, con retornos
     * decrecientes) que el `ataqueMult` le da al daño. Antes de la 036 la defensa
     * del mago era una stat muerta: se calculaba y se mostraba, pero no entraba en
     * ningún cálculo de combate (huérfana desde la 029).
     *
     * costo = max(1, round(peso × factorMatchup × mitigacion(defensaMago)))
     */
    public function costoBloqueo(int $peso, string $elementoGema, string $elementoAtaque, int $defensaMago): int
    {
        $factor = match (self::matchup($elementoGema, $elementoAtaque)) {
            'ventaja' => $this->reglas['defVentaja'],
            'reves' => $this->reglas['defReves'],
            default => $this->reglas['defNeutral'],
        };

        return max(1, (int) round($peso * $factor * $this->mitigacion($defensaMago)));
    }

    /**
     * Costo en vida de cubrir un faltante de esencia al atacar (DECISIONES.md 021):
     * cada punto de esencia que la gema no tiene se paga a `vidaPorEsencia` de vida.
     * Cubre tanto la gema extinta (faltante = nivel entero) como el pago parcial
     * (le queda algo de esencia y el resto sale de la vida). Generaliza el viejo
     * "último sacudón" (012, nivel × C).
     */
    public function costoVida(int $faltante): int
    {
        return max(0, $faltante) * (int) $this->reglas['vidaPorEsencia'];
    }

    private function mitigacion(int $defensa): float
    {
        return $this->reglas['K'] / ($this->reglas['K'] + $defensa);
    }

    private function multAtaque(string $matchup): float
    {
        return match ($matchup) {
            'ventaja' => $this->reglas['ventaja'],
            'reves' => $this->reglas['reves'],
            default => $this->reglas['neutral'],
        };
    }
}
