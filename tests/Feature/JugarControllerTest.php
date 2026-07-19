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

test('un encuentro en una celda con riesgo abre combate y persiste posición y pasos', function () {
    // seed 42: (23,4) tiene prob 11 (agua). El cliente ya decidió que saltó; el
    // servidor deriva el monstruo, lo abre y guarda la posición (022).
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/encuentro", ['x' => 23, 'y' => 4, 'pasos' => 1]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('estado.combate.monstruo.elemento'))->toBe('agua');
    $run->refresh();
    expect($run->pos_x)->toBe(23);
    expect($run->pos_y)->toBe(4);
    expect($run->pasos)->toBe(1);
    expect($run->combate)->not->toBeNull();
    $evento = Event::where('run_id', $run->id)->where('tipo', 'encuentro')->first();
    expect($evento->payload)->toBe(['x' => 23, 'y' => 4, 'elem' => 'agua', 'prob' => 11]);
});

test('un encuentro en una celda sin riesgo (prob 0) es ilegal', function () {
    // La entrada (0,0) tiene prob forzada a 0: no puede saltar nada ahí.
    $run = Run::create(['token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/encuentro", ['x' => 0, 'y' => 0, 'pasos' => 0]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'celda sin riesgo']);
    expect($run->fresh()->combate)->toBeNull();
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('un encuentro fuera del grid es ilegal', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/encuentro", ['x' => 30, 'y' => 0, 'pasos' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'fuera del grid']);
});

test('un encuentro en una partida terminada es ilegal', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30, 'terminado' => true,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/encuentro", ['x' => 23, 'y' => 4, 'pasos' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'terminada']);
});

test('un encuentro con un combate ya abierto es rechazado', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
        'combate' => MazeCombate::iniciar(42, 23, 5, 'agua', 11, 0),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/encuentro", ['x' => 23, 'y' => 4, 'pasos' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'en combate']);
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
    $talisman['gemas'] = [['id' => 99, 'elemento' => 'fuego', 'nivel' => 20, 'carga' => 999, 'fieldeada' => true]];
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
    $talisman['gemas'][] = ['id' => 9, 'elemento' => 'aire', 'nivel' => 2, 'carga' => 12, 'fieldeada' => false];
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

// --- Guardianes de llave y salida (DECISIÓN 032) ---
// seed 42, 30x30: llave 0 en (11,1), salida en (14,25) — ver MapaBuilderTest.

test('guardian sin pelear revela el telegraph pero no abre combate ni persiste (032)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/guardian", ['indice' => 0, 'x' => 11, 'y' => 1]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('guardian.boss'))->toBeTrue();
    expect($response->json('guardian.nivel'))->toBe(4); // llave 0 → N4
    // Staging: no persiste nada, el talismán sigue disponible.
    expect($run->fresh()->combate)->toBeNull();
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('guardian con pelear abre el combate boss y lo persiste (032)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/guardian", ['indice' => 0, 'x' => 11, 'y' => 1, 'pelear' => true]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('estado.combate.monstruo.boss'))->toBeTrue();
    expect($run->fresh()->combate)->not->toBeNull();
    expect(Event::where('run_id', $run->id)->where('tipo', 'guardian')->exists())->toBeTrue();
});

test('guardian en una celda que no es la de la marca es ilegal (032)', function () {
    $run = Run::create(['token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30]);

    $response = $this->postJson("/jugar/{$run->token}/guardian", ['indice' => 0, 'x' => 0, 'y' => 0]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'no es la celda del guardián']);
    expect($run->fresh()->combate)->toBeNull();
});

test('guardian de una llave ya conseguida es ilegal (032)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30, 'llaves' => [0],
    ]);

    $response = $this->postJson("/jugar/{$run->token}/guardian", ['indice' => 0, 'x' => 11, 'y' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'llave ya conseguida']);
});

test('matar al guardián de una llave graba la llave y registra el evento, sin terminar la corrida (032)', function () {
    // Gema descomunal: un golpe letal cierra el combate del guardián de llave (N4).
    $talisman = MazeCombate::talismanInicial();
    $talisman['gemas'] = [['id' => 99, 'elemento' => 'fuego', 'nivel' => 300, 'carga' => 999999, 'fieldeada' => true]];
    $combate = MazeCombate::guardian(42, 0, 11, 1); // llave 0 → N4
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => $talisman, 'combate' => $combate,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 99]);

    $response->assertOk()->assertJson(['ok' => true, 'resultado' => 'victoria']);
    $run->refresh();
    expect($run->llaves)->toBe([0]);      // se grabó la llave
    expect($run->terminado)->toBeFalse(); // una llave no termina la corrida
    expect($run->combate)->toBeNull();
    $evento = Event::where('run_id', $run->id)->where('tipo', 'llave')->first();
    expect($evento->payload['indice'])->toBe(0);
});

test('matar al guardián de la salida (N10) termina la partida como victoria (032)', function () {
    $talisman = MazeCombate::talismanInicial();
    $talisman['gemas'] = [['id' => 99, 'elemento' => 'fuego', 'nivel' => 300, 'carga' => 999999, 'fieldeada' => true]];
    $combate = MazeCombate::guardian(42, MazeCombate::INDICE_SALIDA, 14, 25); // salida → N10
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'talisman' => $talisman, 'combate' => $combate,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 99]);

    $response->assertOk()->assertJson(['ok' => true, 'resultado' => 'victoria']);
    $run->refresh();
    expect($run->terminado)->toBeTrue();               // la victoria final termina la corrida
    expect($run->llaves ?? [])->toBe([]);              // la salida no deja una llave
    expect(Event::where('run_id', $run->id)->where('tipo', 'ganado')->exists())->toBeTrue();
});

// --- Revivir pagando esencia (DECISIÓN 034) ---

/** Talismán inicial pero caído (vida 0), con la esencia que se le pida. */
function talismanCaido(int $esencia): array
{
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 0;
    $t['esencia'] = $esencia;

    return $t;
}

test('una derrota deja la corrida en el limbo (viva) y expone el costo de revivir (034)', function () {
    // Vida 3 y una gema seca nivel 1 (castear cuesta 3 de vida) contra un bicho que
    // sobrevive al golpe: la vida cae a 0, el monstruo no muere → derrota real.
    $talisman = MazeCombate::talismanInicial();
    $talisman['vida'] = 3;
    $talisman['gemas'] = [['id' => 1, 'elemento' => 'fuego', 'nivel' => 1, 'carga' => 0, 'fieldeada' => true]];
    $combate = MazeCombate::iniciar(1, 5, 5, 'tierra', 0, 0); // tierra N1 vida 58, aguanta
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 5, 'pos_y' => 5, 'talisman' => $talisman, 'combate' => $combate,
    ]);

    $response = $this->postJson("/jugar/{$run->token}/combate", ['accion' => 'atacar', 'gemaId' => 1]);

    $response->assertOk()->assertJson(['ok' => true, 'resultado' => 'derrota']);
    $run->refresh();
    expect($run->terminado)->toBeFalse();          // la corrida NO termina (034)
    expect($run->combate)->toBeNull();
    expect($run->talisman['vida'])->toBeLessThanOrEqual(0);
    expect(Event::where('run_id', $run->id)->where('tipo', 'derrota')->exists())->toBeTrue();
    // El costo de revivir viaja en el estado del limbo.
    $costo = $response->json('estado.revivir.costo');
    expect($costo)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10);
});

test('revivir descuenta la esencia, pone la vida en 1 y no toca el resto del talismán (034)', function () {
    // Caído en una celda cualquiera, con esencia de sobra. Cargo las gemas fieldeadas
    // a media asta para comprobar que revivir NO las recarga (eso es aparte, 028).
    $talisman = talismanCaido(20);
    foreach ($talisman['gemas'] as &$g) {
        $g['carga'] = 1;
    }
    unset($g);
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 13, 'pos_y' => 27, 'talisman' => $talisman, // (13,27) es el fondo del seed 1
    ]);

    $response = $this->postJson("/jugar/{$run->token}/revivir");

    $response->assertOk()->assertJson(['ok' => true]);
    $run->refresh();
    expect($run->terminado)->toBeFalse();
    expect($run->talisman['vida'])->toBe(1);                 // volvés con 1, no más
    expect($run->talisman['esencia'])->toBeLessThan(20);     // pagaste el costo
    expect($run->talisman['esencia'])->toBeGreaterThanOrEqual(10); // costo ≤ 10
    // Las gemas siguen a media carga: revivir no recarga el talismán.
    expect(collect($run->talisman['gemas'])->every(fn ($g) => $g['carga'] === 1))->toBeTrue();
    expect(Event::where('run_id', $run->id)->where('tipo', 'revivir')->exists())->toBeTrue();
});

test('revivir sin esencia suficiente es game over: termina la corrida y graba derrota_final (034)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 13, 'pos_y' => 27, 'talisman' => talismanCaido(0), // 0 esencia
    ]);

    $response = $this->postJson("/jugar/{$run->token}/revivir");

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'game over']);
    $run->refresh();
    expect($run->terminado)->toBeTrue();
    expect(Event::where('run_id', $run->id)->where('tipo', 'derrota_final')->exists())->toBeTrue();
});

test('revivir con la vida en pie es rechazado: no hay nada que revivir (034)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'talisman' => MazeCombate::talismanInicial(), // vida 40
    ]);

    $response = $this->postJson("/jugar/{$run->token}/revivir");

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'nada que revivir']);
    expect(Event::where('run_id', $run->id)->exists())->toBeFalse();
});

test('revivir con un combate abierto es rechazado (034)', function () {
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'talisman' => talismanCaido(20),
        'combate' => MazeCombate::iniciar(1, 5, 5, 'tierra', 0, 0),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/revivir");

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'en combate']);
});

test('estando caído no se puede tocar el talismán: curar no es un revivir barato (034)', function () {
    // El hueco que cerró la sección A: con combate cerrado y vida 0, curar (021)
    // habría comprado vida 1:1 salteando el costo escalado de revivir.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 5, 'pos_y' => 5, 'talisman' => talismanCaido(20),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/talisman", ['accion' => 'curar']);

    $response->assertStatus(422)->assertJson(['ok' => false, 'motivo' => 'estás caído']);
    expect($run->fresh()->talisman['vida'])->toBe(0); // sigue caído
});

test('estando caído no se puede salir del laberinto (034)', function () {
    // seed 1 → salida en (13,27). Caído sobre la salida (perdiste contra su guardián)
    // no se gana tildado: salir es ilegal hasta revivir.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 1, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 13, 'pos_y' => 27, 'talisman' => talismanCaido(20),
    ]);

    $response = $this->postJson("/jugar/{$run->token}/salir", ['x' => 13, 'y' => 27]);

    $response->assertStatus(422)->assertJson(['legal' => false]);
    expect($run->fresh()->terminado)->toBeFalse();
});

test('revivir tras perder contra un guardián lo devuelve a vida completa, sin llave (034)', function () {
    // Caído en la celda del guardián de la primera llave (seed 42 → (11,1)), sin
    // llaves. Revivo y vuelvo a abrir el guardián: MazeCombate::guardian lo
    // reconstruye del seed a full vida, y no se otorgó ninguna llave por perder.
    $run = Run::create([
        'token' => 'abc123', 'seed' => 42, 'ancho' => 30, 'alto' => 30,
        'pos_x' => 11, 'pos_y' => 1, 'talisman' => talismanCaido(20),
    ]);

    $this->postJson("/jugar/{$run->token}/revivir")->assertOk();
    expect($run->fresh()->talisman['vida'])->toBe(1);

    $response = $this->postJson("/jugar/{$run->token}/guardian", ['indice' => 0, 'x' => 11, 'y' => 1, 'pelear' => true]);

    $response->assertOk();
    $m = $response->json('estado.combate.monstruo');
    expect($m['boss'])->toBeTrue();
    expect($m['vida'])->toBe($m['vidaMax']);   // guardián fresco: vida completa
    expect($run->fresh()->llaves ?? [])->toBe([]); // perder no regaló la llave
});
