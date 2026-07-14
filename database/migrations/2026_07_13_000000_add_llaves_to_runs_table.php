<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las llaves conseguidas de la corrida (docs/DECISIONES.md 032): el set de
 * índices de guardián de llave ya vencidos (0..2). Proyección/caché — chico y
 * acotado (axioma 5). El guardián de la salida (índice 3) no deja una llave:
 * vencerlo termina la partida (`terminado`), es la victoria final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('llaves')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('llaves');
        });
    }
};
