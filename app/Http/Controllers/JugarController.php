<?php

namespace App\Http\Controllers;

use App\Game\MapaBuilder;
use App\Game\MazeGenerator;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class JugarController extends Controller
{
    private const ANCHO = 30;

    private const ALTO = 30;

    /** Arranca una partida: elige un seed válido y la persiste bajo un token opaco. */
    public function crear()
    {
        $resultado = MapaBuilder::buscarSeed(self::ANCHO, self::ALTO);

        $run = Run::create([
            'token' => $this->generarToken(),
            'seed' => $resultado['seed'],
            'ancho' => self::ANCHO,
            'alto' => self::ALTO,
        ]);

        return redirect()->route('jugar.mostrar', $run->token);
    }

    /** Sirve la vista del laberinto de una partida ya persistida. */
    public function mostrar(string $token): View
    {
        $run = Run::where('token', $token)->firstOrFail();

        return view('jugar', [
            'seed' => $run->seed,
            'ancho' => $run->ancho,
            'alto' => $run->alto,
            'token' => $run->token,
        ]);
    }

    /**
     * Evento "salir": legal solo si la posición que manda el cliente
     * coincide con la salida del laberinto de este seed y la partida no
     * terminó antes. El servidor nunca confía en esa posición a ciegas —
     * la valida recalculando el mapa desde el seed guardado.
     * Ver CLAUDE.md, axiomas 3 y 4.
     */
    public function salir(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
        ]);

        if ($run->terminado) {
            return response()->json(['legal' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $matriz = MazeGenerator::generar($run->seed, $run->ancho, $run->alto);
        $salida = MapaBuilder::marcas($matriz)['salida'];

        if ($salida['x'] !== $datos['x'] || $salida['y'] !== $datos['y']) {
            return response()->json(['legal' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $run->events()->create(['tipo' => 'salir', 'payload' => $datos]);
        $run->update(['terminado' => true]);

        return response()->json(['legal' => true]);
    }

    private function generarToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Run::where('token', $token)->exists());

        return $token;
    }
}
