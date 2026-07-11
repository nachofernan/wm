<?php

namespace App\Http\Controllers;

use App\Game\EncuentroBuilder;
use App\Game\MapaBuilder;
use App\Game\MazeCombate;
use App\Game\MazeGenerator;
use App\Game\Prng;
use App\Game\Talisman;
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

    /** Orden canónico N,E,S,O — docs/PROTOCOLO_GENERADOR.md §3.1 */
    private const DIRECCIONES = [
        'N' => ['dx' => 0, 'dy' => -1],
        'E' => ['dx' => 1, 'dy' => 0],
        'S' => ['dx' => 0, 'dy' => 1],
        'O' => ['dx' => -1, 'dy' => 0],
    ];

    /**
     * Arranca una partida: elige un seed válido y la persiste bajo un token
     * opaco. `semilla_secreta` alimenta el dado de encuentros del servidor
     * (docs/DECISIONES.md 016) — se sortea acá y nunca viaja al cliente.
     */
    public function crear()
    {
        $resultado = MapaBuilder::buscarSeed(self::ANCHO, self::ALTO);

        $run = Run::create([
            'token' => $this->generarToken(),
            'seed' => $resultado['seed'],
            'ancho' => self::ANCHO,
            'alto' => self::ALTO,
            'semilla_secreta' => random_int(0, 0xFFFFFFFF),
            'talisman' => MazeCombate::talismanInicial(),
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
            'estado' => $this->estado($run),
        ]);
    }

    /**
     * Ping por paso (docs/DECISIONES.md 016). El movimiento es local y optimista
     * en el cliente; cada paso sube al servidor, que es la autoridad:
     *
     *  1. Valida que el paso sea legal contra el laberinto regenerado desde el
     *     seed — adyacente a la posición autoritativa y sin pared en el medio.
     *     Esto es lo que impide "saltar paredes" desde un cliente toqueteado
     *     (axioma 3/4). El mapa no se sincroniza: solo se valida.
     *  2. Actualiza la posición en la caché `runs` (el paso en sí no se
     *     persiste como evento).
     *  3. Tira el DADO SECRETO de encuentro: el sesgo (prob) es público y sale
     *     del EncuentroBuilder paritario, pero la tirada usa `semilla_secreta`
     *     (que el cliente no ve) mezclada con el número de paso, así el disparo
     *     no se predice desde el seed. Si salta, se registra un evento
     *     `encuentro` append-only y se devuelve al cliente.
     *
     * Pendiente (no en este paso): puertas/llaves en el servidor. Hoy la
     * validación es adyacencia + pared, más laxa pero nunca incorrecta: no
     * rechaza cruzar una puerta que el cliente considera cerrada.
     */
    public function paso(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
        ]);

        if ($run->terminado) {
            return response()->json(['ok' => false, 'motivo' => 'terminada'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // No se camina con un combate abierto: hay que resolverlo primero. El
        // cliente ya lo frena, pero el servidor no confía en eso (axioma 4).
        if ($run->combate !== null) {
            return response()->json(['ok' => false, 'motivo' => 'en combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->pasoLegal($run, $datos['x'], $datos['y'])) {
            return response()->json(['ok' => false, 'motivo' => 'ilegal'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $run->update([
            'pos_x' => $datos['x'],
            'pos_y' => $datos['y'],
            'pasos' => $run->pasos + 1,
        ]);

        $encuentro = $this->tirarEncuentro($run, $datos['x'], $datos['y']);
        if ($encuentro !== null) {
            $run->events()->create(['tipo' => 'encuentro', 'payload' => $encuentro]);
            // El encuentro abre un combate: el monstruo y su semilla se derivan
            // en el servidor (docs/DECISIONES.md 018). El paso (índice del
            // encuentro) varía el bicho si volvés a cruzar la misma celda.
            $combate = MazeCombate::iniciar(
                $run->seed, $datos['x'], $datos['y'], $encuentro['elem'], $encuentro['prob'], $run->pasos,
            );
            $run->update(['combate' => $combate]);
        }

        return response()->json(['ok' => true, 'encuentro' => $encuentro, 'estado' => $this->estado($run)]);
    }

    /**
     * Resuelve una acción del combate activo (docs/DECISIONES.md 018). Toda la
     * verdad de combate vive en el servidor (axioma 4): el cliente manda la
     * acción y recibe el estado nuevo. La resolución la hace MazeCombate con
     * una semilla de combate que el cliente no ve.
     */
    public function combate(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'accion' => 'required|in:atacar,comer,bloquear',
            'gemaId' => 'nullable|integer',
        ]);

        if ($run->terminado || $run->combate === null) {
            return response()->json(['ok' => false, 'motivo' => 'sin combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $res = MazeCombate::resolver($run->combate, $run->talisman, $datos['accion'], $datos['gemaId'] ?? null);

        if ($res['error'] !== null) {
            return response()->json(['ok' => false, 'motivo' => $res['error']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $run->talisman = $res['talisman'];
        $run->combate = $res['combate'];

        if ($res['resultado'] === 'victoria') {
            $run->events()->create(['tipo' => 'combate_ganado', 'payload' => ['drop' => $res['drop']]]);
        } elseif ($res['resultado'] === 'derrota') {
            // Sin revividas todavía (docs/DISENO.md §4): la derrota termina la
            // partida. Placeholder hasta que se construya el reset/revivida.
            $run->events()->create(['tipo' => 'derrota', 'payload' => []]);
            $run->terminado = true;
        }

        $run->save();

        return response()->json([
            'ok' => true,
            'resultado' => $res['resultado'],
            'drop' => $res['drop'],
            'log' => $res['log'],
            'estado' => $this->estado($run),
        ]);
    }

    /**
     * Gestión del talismán entre peleas (docs/DECISIONES.md 018): equipar,
     * guardar, desguazar, subir cap. No se toca el loadout con un combate
     * abierto — hay que resolverlo primero.
     */
    public function talisman(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'accion' => 'required|in:fieldear,guardar,desguazar,subirCap',
            'gemaId' => 'nullable|integer',
        ]);

        if ($run->terminado || $run->combate !== null) {
            return response()->json(['ok' => false, 'motivo' => 'en combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $res = Talisman::aplicar($run->talisman, $datos['accion'], $datos['gemaId'] ?? null);

        if ($res['error'] !== null) {
            return response()->json(['ok' => false, 'motivo' => $res['error']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $run->update(['talisman' => $res['talisman']]);

        return response()->json(['ok' => true, 'estado' => $this->estado($run)]);
    }

    /**
     * Estado de partida que ve el cliente: la hoja de personaje y el combate
     * activo, si hay. Nunca expone la semilla de combate ni el contador de
     * pasos (axioma 4): solo lo renderizable.
     */
    private function estado(Run $run): array
    {
        $combate = null;
        if ($run->combate !== null) {
            $c = $run->combate;
            $combate = [
                'x' => $c['x'], 'y' => $c['y'], 'monstruo' => $c['monstruo'],
                'turno' => $c['turno'], 'entrante' => $c['entrante'],
            ];
        }

        return ['talisman' => $run->talisman, 'combate' => $combate];
    }

    /**
     * ¿El destino (x,y) es un paso legal desde la posición autoritativa? Tiene
     * que ser una de las cuatro celdas adyacentes, dentro del grid, y sin pared
     * cerrada en el medio según el laberinto de este seed.
     */
    private function pasoLegal(Run $run, int $x, int $y): bool
    {
        if ($x >= $run->ancho || $y >= $run->alto) {
            return false;
        }

        $dx = $x - $run->pos_x;
        $dy = $y - $run->pos_y;

        $direccion = null;
        foreach (self::DIRECCIONES as $nombre => $d) {
            if ($d['dx'] === $dx && $d['dy'] === $dy) {
                $direccion = $nombre;
                break;
            }
        }

        if ($direccion === null) {
            return false; // no es adyacente (o es la misma celda)
        }

        $matriz = MazeGenerator::generar($run->seed, $run->ancho, $run->alto);

        return $matriz[$run->pos_y][$run->pos_x][$direccion] === 0; // 0 = pared abierta
    }

    /**
     * Dado secreto de encuentro para la celda (x,y). El sesgo (prob, elemento)
     * es público y paritario; la tirada usa la semilla secreta de la partida
     * mezclada con el número de paso, así el cliente no la predice. Devuelve el
     * encuentro si saltó, o null.
     *
     * @return array{x:int,y:int,elem:string|null,prob:int}|null
     */
    private function tirarEncuentro(Run $run, int $x, int $y): ?array
    {
        $celda = EncuentroBuilder::campo($run->seed, $run->ancho, $run->alto)['celdas'][$y][$x];

        $dado = new Prng(($run->semilla_secreta + $run->pasos) & 0xFFFFFFFF);
        if ($dado->randBelow(100) >= $celda['prob']) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'elem' => $celda['elem'], 'prob' => $celda['prob']];
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
