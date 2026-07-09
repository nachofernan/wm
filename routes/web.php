<?php

use App\Http\Controllers\JugarController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/jugar', [JugarController::class, 'crear'])->name('jugar.crear');
Route::get('/jugar/{token}', [JugarController::class, 'mostrar'])->name('jugar.mostrar');
Route::post('/jugar/{token}/salir', [JugarController::class, 'salir'])->name('jugar.salir');
