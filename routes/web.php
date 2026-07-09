<?php

use App\Game\MapaBuilder;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/jugar', function () {
    $ancho = 30;
    $alto = 30;
    $resultado = MapaBuilder::buscarSeed($ancho, $alto);

    return view('jugar', [
        'seed' => $resultado['seed'],
        'ancho' => $ancho,
        'alto' => $alto,
    ]);
});
