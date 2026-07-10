<?php

/**
 * Endpoint descartable /pj/combate: la fina capa HTTP sobre CombatResolver que
 * alimenta el playground de tuning. La resolución en sí ya está cubierta por
 * CombatResolverTest; acá solo se verifica el wiring y la validación de bordes.
 */

test('golpe devuelve el desglose del resolver', function () {
    $resp = $this->postJson('/pj/combate', [
        'accion' => 'golpe',
        'nivel' => 5,
        'elementoAtacante' => 'fuego',
        'defensa' => 30,
        'elementoDefensor' => 'aire', // fuego vence aire → ventaja
        'semilla' => 1,
    ]);

    $resp->assertOk()
        ->assertJson(['matchup' => 'ventaja', 'costoEsencia' => 5])
        ->assertJsonStructure(['poder', 'mitigacion', 'mult', 'critico', 'variacion', 'dano']);
});

test('los overrides de reglas cambian la resolución', function () {
    $base = ['accion' => 'golpe', 'nivel' => 5, 'elementoAtacante' => 'fuego',
        'defensa' => 30, 'elementoDefensor' => 'aire', 'semilla' => 7];

    $poderF3 = $this->postJson('/pj/combate', $base)->json('poder');
    $poderF5 = $this->postJson('/pj/combate', [...$base, 'reglas' => ['F' => 5]])->json('poder');

    expect($poderF3)->toBe(15)->and($poderF5)->toBe(25);
});

test('bloqueo devuelve el costo en esencia', function () {
    // agua vence fuego → ventaja (factor 0.5): peso 2 → 1
    $this->postJson('/pj/combate', [
        'accion' => 'bloqueo', 'peso' => 2, 'elementoGema' => 'agua', 'elementoAtaque' => 'fuego',
    ])->assertOk()->assertExactJson(['costo' => 1]);
});

test('rechaza una acción desconocida', function () {
    $this->postJson('/pj/combate', ['accion' => 'volar'])->assertStatus(422);
});
