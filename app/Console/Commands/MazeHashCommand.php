<?php

namespace App\Console\Commands;

use App\Game\MazeGenerator;
use Illuminate\Console\Command;

/**
 * Herramienta de validación offline: genera el laberinto de un seed y
 * vuelca su hash de paridad (docs/PROTOCOLO_GENERADOR.md §6), para
 * compararlo a mano contra el lado JS sin tener que levantar el juego.
 */
class MazeHashCommand extends Command
{
    protected $signature = 'maze:hash {seed : Entero usado como seed} {--ancho=100} {--alto=100}';

    protected $description = 'Genera un laberinto desde un seed y muestra su hash de paridad';

    public function handle(): int
    {
        $seed = (int) $this->argument('seed');
        $ancho = (int) $this->option('ancho');
        $alto = (int) $this->option('alto');

        $matriz = MazeGenerator::generar($seed, $ancho, $alto);

        $this->line("seed:  {$seed}");
        $this->line("grid:  {$ancho}x{$alto}");
        $this->line('hash:  '.MazeGenerator::hash($matriz));

        return self::SUCCESS;
    }
}
