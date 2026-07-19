<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los cofres ya abiertos de la corrida (DECISIÓN 035): el set de índices (en la
 * lista marcas().cofres, determinista desde el seed) de los cofres ya vaciados.
 * Proyección/caché — chica y acotada (axioma 5), mismo patrón que `llaves`. Un
 * cofre no se persiste como celda: su posición sale del seed, acá solo vive qué
 * índices ya rindieron su gema, para no dropear dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('cofres')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('cofres');
        });
    }
};
