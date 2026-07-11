<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proyección (cache) del estado de una partida. La fuente de verdad son los
 * eventos (Event); esta fila existe para no rehacer el replay en cada
 * request. Ver CLAUDE.md, axioma 6.
 */
class Run extends Model
{
    protected $fillable = [
        'token',
        'seed',
        'ancho',
        'alto',
        'terminado',
        'pos_x',
        'pos_y',
        'pasos',
        'semilla_secreta',
        'talisman',
        'combate',
    ];

    protected $casts = [
        'terminado' => 'boolean',
        'talisman' => 'array',
        'combate' => 'array',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
