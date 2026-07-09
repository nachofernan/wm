<?php

use App\Models\Event;
use App\Models\Run;

test('crear arranca una partida y redirige a la url con token', function () {
    $response = $this->get('/jugar');

    $response->assertRedirect();
    $run = Run::sole();
    expect($response->headers->get('Location'))->toContain($run->token);
});

test('mostrar sirve el laberinto de una partida existente', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30]);

    $response = $this->get("/jugar/{$run->token}");

    $response->assertStatus(200);
});

test('mostrar devuelve 404 para un token que no existe', function () {
    $response = $this->get('/jugar/no-existe');

    $response->assertStatus(404);
});

test('salir es legal cuando la posición coincide con la salida del seed', function () {
    // seed 1, 30x30 → salida en (13, 27), ver tests/Unit/Game/MapaBuilderTest.php
    $run = Run::create(['token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/salir", ['x' => 13, 'y' => 27]);

    $response->assertOk()->assertJson(['legal' => true]);
    expect($run->fresh()->terminado)->toBeTrue();
    expect(Event::where('run_id', $run->id)->where('tipo', 'salir')->exists())->toBeTrue();
});

test('salir es ilegal cuando la posición no es la salida', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/salir", ['x' => 0, 'y' => 0]);

    $response->assertStatus(422)->assertJson(['legal' => false]);
    expect($run->fresh()->terminado)->toBeFalse();
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('salir es ilegal si la partida ya había terminado', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30, 'terminado' => true]);

    $response = $this->postJson("/jugar/{$run->token}/salir", ['x' => 13, 'y' => 27]);

    $response->assertStatus(422)->assertJson(['legal' => false]);
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});
