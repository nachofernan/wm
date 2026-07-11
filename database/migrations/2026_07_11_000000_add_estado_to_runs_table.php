<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lleva la posición del mago a la caché `runs` (docs/DECISIONES.md 016):
 * el movimiento es local en el cliente, pero el servidor mantiene la posición
 * autoritativa para validar el ping por paso. `semilla_secreta` alimenta el
 * dado de encuentros del servidor — nunca viaja al cliente, así el disparo no
 * es predecible desde el seed público.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->unsignedInteger('pasos')->default(0);
            $table->unsignedBigInteger('semilla_secreta')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y', 'pasos', 'semilla_secreta']);
        });
    }
};
