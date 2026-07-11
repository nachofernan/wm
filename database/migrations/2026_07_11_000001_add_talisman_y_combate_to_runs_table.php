<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de partida para la Fase 4 (docs/DECISIONES.md 018):
 * - `talisman`: la hoja de personaje persistida (vida, cap, esencia, gemas,
 *   progreso). Es proyección/caché — chico y acotado (axioma 5).
 * - `combate`: el combate activo, o null si no hay. Guarda la vida del monstruo,
 *   el turno y la semilla secreta de combate. El cliente no tiene ninguna verdad
 *   de combate (axioma 4): solo se resuelve acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('talisman')->nullable();
            $table->json('combate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['talisman', 'combate']);
        });
    }
};
