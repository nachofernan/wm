<?php

namespace App\Http\Controllers;

use App\Game\EncuentroBuilder;
use App\Game\MapaBuilder;
use App\Game\MazeCombate;
use App\Game\MazeGenerator;
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
     * Abre un combate en la celda que el cliente reporta (docs/DECISIONES.md 022).
     * El movimiento y la tirada del encuentro son del cliente ahora; el servidor
     * no pinguea por paso. Cuando el cliente decide que saltó un bicho, acá:
     *
     *  1. Chequeos de borde: la partida está viva, no hay otro combate abierto, y
     *     la celda está dentro del grid y tiene riesgo (prob > 0). NO se revalida
     *     el camino paso a paso — se confía la posición (axioma 3 relajado, 022):
     *     sin stakes, un cliente toqueteado solo se perjudica a sí mismo.
     *  2. Persiste posición y pasos en la caché `runs`.
     *  3. Deriva el monstruo del seed —esto SÍ sigue siendo autoridad del servidor
     *     (axioma 4)— abre el combate y registra el evento `encuentro` append-only.
     */
    public function encuentro(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
            'pasos' => 'required|integer|min:0',
        ]);

        if ($run->terminado) {
            return response()->json(['ok' => false, 'motivo' => 'terminada'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($run->combate !== null) {
            return response()->json(['ok' => false, 'motivo' => 'en combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($datos['x'] >= $run->ancho || $datos['y'] >= $run->alto) {
            return response()->json(['ok' => false, 'motivo' => 'fuera del grid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $celda = EncuentroBuilder::campo($run->seed, $run->ancho, $run->alto)['celdas'][$datos['y']][$datos['x']];
        if ($celda['prob'] === 0) {
            return response()->json(['ok' => false, 'motivo' => 'celda sin riesgo'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $run->update(['pos_x' => $datos['x'], 'pos_y' => $datos['y'], 'pasos' => $datos['pasos']]);

        $encuentro = ['x' => $datos['x'], 'y' => $datos['y'], 'elem' => $celda['elem'], 'prob' => $celda['prob']];
        $run->events()->create(['tipo' => 'encuentro', 'payload' => $encuentro]);
        // El monstruo y su semilla se derivan en el servidor (axioma 4). El índice
        // (pasos) varía el bicho si volvés a cruzar la misma celda. `t` es la
        // distancia normalizada a la entrada: escala dificultad y loot (027).
        $matriz = MazeGenerator::generar($run->seed, $run->ancho, $run->alto);
        $t = MapaBuilder::dificultadCelda($matriz, $datos['x'], $datos['y']);
        $combate = MazeCombate::iniciar($run->seed, $datos['x'], $datos['y'], $celda['elem'], $celda['prob'], $datos['pasos'], $t);
        $run->update(['combate' => $combate]);

        return response()->json(['ok' => true, 'estado' => $this->estado($run)]);
    }

    /**
     * Guardián de una llave o de la salida (docs/DECISIONES.md 032). El combate es
     * telegrafiado y sin escape: el encuentro se REVELA antes de comprometerse, así
     * el jugador setea el talismán en un staging seguro (con el combate cerrado, el
     * endpoint de talismán sigue disponible) y recién ahí pelea.
     *
     *  - `pelear` ausente o false: revela el guardián (stats telegrafiadas) sin
     *    abrir combate ni persistir nada. Es el staging.
     *  - `pelear` true: abre el combate boss y lo persiste.
     *
     * La posición se valida contra el seed (axioma 4): la celda reportada tiene que
     * ser la de esta marca (la llave del índice, o la salida). El bicho lo deriva
     * MazeCombate::guardian del seed — nivel fijo por índice, el cliente no decide.
     */
    public function guardian(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'indice' => 'required|integer|min:0|max:'.MazeCombate::INDICE_SALIDA,
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
            'pelear' => 'boolean',
        ]);

        if ($run->terminado) {
            return response()->json(['ok' => false, 'motivo' => 'terminada'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($run->combate !== null) {
            return response()->json(['ok' => false, 'motivo' => 'en combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $indice = $datos['indice'];
        if ($indice !== MazeCombate::INDICE_SALIDA && in_array($indice, $run->llaves ?? [], true)) {
            return response()->json(['ok' => false, 'motivo' => 'llave ya conseguida'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $matriz = MazeGenerator::generar($run->seed, $run->ancho, $run->alto);
        $marcas = MapaBuilder::marcas($matriz);
        $celda = $indice === MazeCombate::INDICE_SALIDA ? $marcas['salida'] : $marcas['llaves'][$indice];
        if ($celda === null || $celda['x'] !== $datos['x'] || $celda['y'] !== $datos['y']) {
            return response()->json(['ok' => false, 'motivo' => 'no es la celda del guardián'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $combate = MazeCombate::guardian($run->seed, $indice, $datos['x'], $datos['y']);

        if (! ($datos['pelear'] ?? false)) {
            // Solo revelar: telegrafía el guardián para el staging, no abre combate.
            return response()->json(['ok' => true, 'guardian' => $combate['monstruo']]);
        }

        $run->update(['pos_x' => $datos['x'], 'pos_y' => $datos['y'], 'combate' => $combate]);
        $run->events()->create(['tipo' => 'guardian', 'payload' => ['indice' => $indice]]);

        return response()->json(['ok' => true, 'estado' => $this->estado($run)]);
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
            'accion' => 'required|in:atacar,bloquear,escapar',
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
            $llave = $res['llave'] ?? null;
            if ($llave === MazeCombate::INDICE_SALIDA) {
                // Guardián de la salida caído (032): victoria final, la corrida termina.
                $run->events()->create(['tipo' => 'ganado', 'payload' => ['drop' => $res['drop']]]);
                $run->terminado = true;
            } elseif ($llave !== null) {
                // Guardián de llave caído (032): se graba la llave del índice.
                $run->llaves = array_values(array_unique([...($run->llaves ?? []), $llave]));
                $run->events()->create(['tipo' => 'llave', 'payload' => ['indice' => $llave, 'drop' => $res['drop']]]);
            } else {
                $run->events()->create(['tipo' => 'combate_ganado', 'payload' => ['drop' => $res['drop']]]);
            }
        } elseif ($res['resultado'] === 'derrota') {
            // Sin revividas todavía (docs/DISENO.md §4): la derrota termina la
            // partida. Placeholder hasta que se construya el reset/revivida.
            $run->events()->create(['tipo' => 'derrota', 'payload' => []]);
            $run->terminado = true;
        } elseif ($res['resultado'] === 'huida') {
            // Escape (030): el combate se cierra pero la partida sigue; la colmena
            // queda viva. Se registra append-only como cualquier otro cierre.
            $run->events()->create(['tipo' => 'huida', 'payload' => []]);
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
     * Gestión del talismán entre peleas (docs/DECISIONES.md 018/025): equipar,
     * guardar, desguazar, fusionar (usa `gemaId2`), subir nivel, curar. No se
     * toca el loadout con un combate abierto — hay que resolverlo primero.
     */
    public function talisman(Request $request, string $token): JsonResponse
    {
        $run = Run::where('token', $token)->firstOrFail();

        $datos = $request->validate([
            'accion' => 'required|in:fieldear,guardar,desguazar,fusionar,vaciar,recargar,subirNivel,curar',
            'gemaId' => 'nullable|integer',
            'gemaId2' => 'nullable|integer',
        ]);

        if ($run->terminado || $run->combate !== null) {
            return response()->json(['ok' => false, 'motivo' => 'en combate'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $res = Talisman::aplicar($run->talisman, $datos['accion'], $datos['gemaId'] ?? null, $datos['gemaId2'] ?? null);

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

        return ['talisman' => $run->talisman, 'combate' => $combate, 'llaves' => $run->llaves ?? []];
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
