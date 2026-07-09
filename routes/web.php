<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/jugar', function () {
    return view('jugar', [
        'seed' => random_int(1, PHP_INT_MAX),
        'ancho' => 30,
        'alto' => 30,
    ]);
});
