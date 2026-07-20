<?php

use App\Game\CombatResolver;
use App\Game\Prng;
use App\Http\Controllers\JugarController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// La ruta natural: el link limpio (raíz) arranca una partida nueva y redirige
// a su token. Cada visitante que abra el sitio juega su propio laberinto.
Route::get('/', [JugarController::class, 'crear'])->name('jugar.crear');

// Playground descartable para tantear vida/poder/talismán del PJ — ver docs/DECISIONES.md 010 y 011.
Route::get('/pj', function () {
    return view('pj-playground');
});

// Playground descartable: un combate real por turnos contra el resolver. Seteo
// mínimo y a pelear — para sentir el combate de la DECISIONES.md 012, no para tunear.
Route::get('/pelea', function () {
    return view('pelea');
});

// Playground descartable: la hoja del mago jugable. Une el combate del /pelea con
// la economía del talismán (010/011): peleas sueltas, drop de gema al ganar, y el
// loop guardar/fieldear/desguazar. Sin perillas de tuning — usa los DEFAULTS del
// resolver. Reúsa POST /pj/combate (autoridad de combate en el servidor, axioma 4).
Route::get('/mago', function () {
    return view('mago');
});

/**
 * Endpoint descartable del playground: corre el CombatResolver real (autoridad
 * de combate en el servidor, axioma 4) para tantear los números de la
 * DECISIONES.md 012 antes de llevarlos a la Fase 4. No persiste nada.
 */
Route::post('/pj/combate', function (Request $request) {
    $datos = $request->validate([
        'accion' => 'required|in:golpe,bloqueo',
        'reglas' => 'array',
        'semilla' => 'integer',
        'nivel' => 'integer|min:1',
        'elementoAtacante' => 'string',
        'defensa' => 'integer|min:0',
        'elementoDefensor' => 'string',
        'peso' => 'integer|min:1',
        'elementoGema' => 'string',
        'elementoAtaque' => 'string',
    ]);

    $prng = new Prng($datos['semilla'] ?? random_int(0, PHP_INT_MAX));
    $resolver = new CombatResolver($prng, $datos['reglas'] ?? []);

    if ($datos['accion'] === 'golpe') {
        return response()->json($resolver->golpe(
            $datos['nivel'], $datos['elementoAtacante'], $datos['defensa'], $datos['elementoDefensor'],
        ));
    }

    return response()->json([
        'costo' => $resolver->costoBloqueo($datos['peso'], $datos['elementoGema'], $datos['elementoAtaque'], $datos['defensa'] ?? 0),
    ]);
});

Route::get('/jugar/{token}', [JugarController::class, 'mostrar'])->name('jugar.mostrar');
Route::post('/jugar/{token}/encuentro', [JugarController::class, 'encuentro'])->name('jugar.encuentro');
Route::post('/jugar/{token}/guardian', [JugarController::class, 'guardian'])->name('jugar.guardian');
Route::post('/jugar/{token}/cofre', [JugarController::class, 'cofre'])->name('jugar.cofre');
Route::post('/jugar/{token}/combate', [JugarController::class, 'combate'])->name('jugar.combate');
Route::post('/jugar/{token}/revivir', [JugarController::class, 'revivir'])->name('jugar.revivir');
Route::post('/jugar/{token}/talisman', [JugarController::class, 'talisman'])->name('jugar.talisman');
Route::post('/jugar/{token}/salir', [JugarController::class, 'salir'])->name('jugar.salir');
