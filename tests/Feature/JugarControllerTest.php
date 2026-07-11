<?php

use App\Game\MazeCombate;
use App\Models\Event;
use App\Models\Run;

test('crear arranca una partida y redirige a la url con token', function () {
    $response = $this->get('/');

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

test('paso legal actualiza la posición y avanza el contador', function () {
    // seed 42, 30x30: desde (23,5) el paso Norte a (23,4) tiene la pared abierta.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'semilla_secreta' => 0,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertOk()->assertJson(['ok' => true]);
    $run->refresh();
    expect($run->pos_x)->toBe(23);
    expect($run->pos_y)->toBe(4);
    expect($run->pasos)->toBe(1);
});

test('paso a través de una pared cerrada es ilegal y no mueve nada', function () {
    // seed 42: desde la entrada (0,0) el Sur (0,1) tiene pared cerrada.
    $run = Run::create(['token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 0, 'y' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'ilegal']);
    $run->refresh();
    expect($run->pos_x)->toBe(0);
    expect($run->pos_y)->toBe(0);
    expect($run->pasos)->toBe(0);
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('paso a una celda no adyacente es ilegal', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 5, 'y' => 5]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'ilegal']);
});

test('paso en una partida terminada es ilegal', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'terminado' => true,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'terminada']);
});

test('el dado secreto dispara un encuentro y lo registra como evento', function () {
    // seed 42: (23,4) tiene prob 11 (agua). Con semilla_secreta=5 el dado salta
    // en el paso 1 — escenario determinista, ver el script de tuning.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'semilla_secreta' => 5,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertOk()->assertJson([
        'ok' => true,
        'encuentro' => ['x' => 23, 'y' => 4, 'elem' => 'agua', 'prob' => 11],
    ]);
    $evento = Event::where('run_id', $run->id)->where('tipo', 'encuentro')->first();
    expect($evento)->not->toBeNull();
    expect($evento->payload)->toBe(['x' => 23, 'y' => 4, 'elem' => 'agua', 'prob' => 11]);
});

test('el dado secreto puede no disparar: paso legal sin encuentro ni evento', function () {
    // Mismo paso, pero semilla_secreta=0 no dispara en el paso 1.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'semilla_secreta' => 0,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertOk()->assertJson(['ok' => true, 'encuentro' => null]);
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('un paso con un combate abierto es rechazado (hay que pelear primero)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'talisman' => MazeCombate::talismanInicial(),
        'combate' => MazeCombate::iniciar(42, 23, 5, 'agua', 11, 0),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'en combate']);
    expect($run->fresh()->pos_y)->toBe(5); // no se movió
});

test('combate sin combate activo es rechazado', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'sin combate']);
});

test('atacar en combate baja la vida del monstruo y devuelve el estado', function () {
    $talisman = MazeCombate::talismanInicial();
    $combate = MazeCombate::iniciar(1, 5, 5, 'tierra', 0, 0);
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'talisman' => $talisman, 'combate' => $combate,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 1]);

    $response->assertOk()->assertJson(['ok' => true]);
    $estado = $response->json('estado');
    expect($estado['combate']['monstruo']['vida'])->toBeLessThan(70);
    // El estado que ve el cliente nunca expone la semilla de combate (axioma 4).
    expect($estado['combate'])->not->toHaveKey('semilla');
    expect($estado['combate'])->not->toHaveKey('paso');
});

test('matar al monstruo registra el evento y deja el drop en el inventario', function () {
    // Gema sobrada vs sílfide: un golpe letal cierra el combate con victoria.
    $talisman = MazeCombate::talismanInicial();
    $talisman['gemas'] = [['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'esencia' => 999, 'fieldeada' => true]];
    $combate = MazeCombate::iniciar(1, 5, 5, 'aire', 0, 0);
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'talisman' => $talisman, 'combate' => $combate,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 99]);

    $response->assertOk()->assertJson(['ok' => true, 'resultado' => 'victoria']);
    expect($run->fresh()->combate)->toBeNull();
    // Botín de una o más piedras (multi-drop): la gema previa + al menos una nueva.
    expect(count($run->fresh()->talisman['gemas']))->toBeGreaterThanOrEqual(2);
    expect($response->json('drop'))->toBeArray();
    expect(Event::where('run_id', $run->id)->where('tipo', 'combate_ganado')->exists())->toBeTrue();
});

test('fieldear una gema del inventario la equipa fuera de combate', function () {
    $talisman = MazeCombate::talismanInicial();
    $talisman['gemas'][2]['fieldeada'] = false; // dejo libre cap (saco la de tierra n3)
    $talisman['gemas'][] = ['id' => 9, 'elemento' => 'aire', 'nivel' => 2, 'esencia' => 12, 'fieldeada' => false];
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30, 'talisman' => $talisman,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/talisman", ['accion' => 'fieldear', 'gemaId' => 9]);

    $response->assertOk()->assertJson(['ok' => true]);
    $equipada = collect($run->fresh()->talisman['gemas'])->firstWhere('id', 9);
    expect($equipada['fieldeada'])->toBeTrue();
});

test('no se toca el talismán con un combate abierto', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
        'combate' => MazeCombate::iniciar(42, 5, 5, 'agua', 11, 0),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/talisman", ['accion' => 'guardar', 'gemaId' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'en combate']);
});

test('el paso que dispara un encuentro abre un combate en el estado', function () {
    // seed 42: (23,4) prob 11 (agua), y semilla_secreta=5 dispara en el paso 1.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 23, 'pos_y' => 5, 'semilla_secreta' => 5,
        'talisman' => MazeCombate::talismanInicial(),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/paso", ['x' => 23, 'y' => 4]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('estado.combate.monstruo.elemento'))->toBe('agua');
    expect($run->fresh()->combate)->not->toBeNull();
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
