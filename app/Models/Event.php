<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de eventos que le importan al servidor (abrir cofre,
 * combate, hechizo, salir — ver CLAUDE.md, axioma 3). Ninguna fila se
 * actualiza ni se borra.
 */
class Event extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'run_id',
        'tipo',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
