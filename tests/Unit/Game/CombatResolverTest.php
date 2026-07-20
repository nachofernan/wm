<?php

use App\Game\CombatResolver;
use App\Game\Prng;

/**
 * Combate según docs/DECISIONES.md 012. La parte determinista (daño dado un
 * factor de variación, matchup, costo de bloqueo) se testea con valores fijos
 * calculados a mano sobre los números de arranque. golpe() se testea por
 * reproducibilidad (mismo seed → mismo golpe) y por cota de banda, no por un
 * valor exacto que dependería de la aritmética interna del Prng.
 */
function resolver(array $reglas = []): CombatResolver
{
    return new CombatResolver(new Prng(1), $reglas);
}

test('matchup lee la rueda: ventaja, revés y neutral', function () {
    expect(CombatResolver::matchup('fuego', 'aire'))->toBe('ventaja'); // fuego vence aire
    expect(CombatResolver::matchup('fuego', 'agua'))->toBe('reves');   // agua vence fuego
    expect(CombatResolver::matchup('fuego', 'tierra'))->toBe('neutral');
});

test('daño por ratio con ventaja elemental', function () {
    // poder 5*3=15, mitigacion 50/80=0.625, ventaja 1.5, var 1.0 → 14.0625 → 14
    expect(resolver()->dano(5, 'fuego', 30, 'aire', 1.0))->toBe(14);
});

test('daño por ratio con revés elemental', function () {
    // poder 15, mitigacion 0.625, revés 0.5, var 1.0 → 4.6875 → 5
    expect(resolver()->dano(5, 'fuego', 30, 'agua', 1.0))->toBe(5);
});

test('el bonus de ataque de la hoja escala el daño (acople gema→ataque, 024)', function () {
    // Base: poder 15, mitigacion 0.625, ventaja 1.5, var 1.0 → 14.0625 → 14.
    expect(resolver()->dano(5, 'fuego', 30, 'aire', 1.0, 0.0))->toBe(14);
    // Con +25% de ataque: 14.0625 × 1.25 = 17.578 → 18.
    expect(resolver()->dano(5, 'fuego', 30, 'aire', 1.0, 0.25))->toBe(18);
});

test('el daño nunca baja de 1: el alfeñique siempre araña', function () {
    // poder 3, mitigacion 50/250=0.2, revés 0.5, var 0.85 → 0.255 → round 0 → piso 1
    expect(resolver()->dano(1, 'fuego', 200, 'agua', 0.85))->toBe(1);
});

test('costo de bloqueo: barato con ventaja, carísimo con el elemento equivocado', function () {
    // Defensa del mago 0 → mitigacion 50/50 = 1.0: el matchup manda solo.
    // agua vence fuego (ventaja 0.5): peso 2 → 1
    expect(resolver()->costoBloqueo(2, 'agua', 'fuego', 0))->toBe(1);
    // fuego contra agua (revés 2.0): peso 2 → 4, funde media gema
    expect(resolver()->costoBloqueo(2, 'fuego', 'agua', 0))->toBe(4);
    // neutral (1.0): peso 2 → 2
    expect(resolver()->costoBloqueo(2, 'tierra', 'fuego', 0))->toBe(2);
});

test('la defensa del mago descuenta el costo de bloquear con la curva K/(K+def) (036)', function () {
    // Misma curva que mitiga el daño: defensa 50 → mitigacion 50/100 = 0.5.
    // neutral peso 8 → 8 × 1.0 × 0.5 = 4
    expect(resolver()->costoBloqueo(8, 'tierra', 'fuego', 50))->toBe(4);
    // revés peso 8 → 8 × 2.0 × 0.5 = 8 (el descuento no borra el castigo del matchup)
    expect(resolver()->costoBloqueo(8, 'fuego', 'agua', 50))->toBe(8);
});

test('el bloqueo nunca cuesta menos de 1 de esencia', function () {
    expect(resolver()->costoBloqueo(1, 'agua', 'fuego', 0))->toBe(1); // 1*0.5*1.0=0.5 → piso 1
});

test('cubrir esencia faltante con vida cuesta faltante × penalidad', function () {
    expect(resolver()->costoVida(5))->toBe(15); // gema extinta nivel 5: 5 × 3
    expect(resolver()->costoVida(2))->toBe(6);  // faltan 2 de esencia: 2 × 3
    expect(resolver()->costoVida(0))->toBe(0);  // no falta nada: sin costo
});

test('golpe cobra esencia igual al nivel de la gema', function () {
    expect(resolver()->golpe(7, 'fuego', 20, 'tierra')['costoEsencia'])->toBe(7);
});

test('golpe es reproducible: mismo seed, misma secuencia', function () {
    $a = new CombatResolver(new Prng(12345));
    $b = new CombatResolver(new Prng(12345));

    foreach (range(1, 5) as $_) {
        expect($a->golpe(5, 'fuego', 30, 'aire'))
            ->toBe($b->golpe(5, 'fuego', 30, 'aire'));
    }
});

test('el daño de golpe cae dentro de la banda de variación (con crítico incluido)', function () {
    $r = resolver();
    $piso = $r->dano(5, 'fuego', 30, 'aire', 0.85);              // banda mínima, sin crítico
    $techo = $r->dano(5, 'fuego', 30, 'aire', 1.15 * 1.75);      // banda máxima, con crítico

    foreach (range(1, 200) as $_) {
        $dano = $r->golpe(5, 'fuego', 30, 'aire')['dano'];
        expect($dano)->toBeGreaterThanOrEqual($piso)->toBeLessThanOrEqual($techo);
    }
});
