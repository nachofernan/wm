<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wizard's Maze</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <script>
        window.__MAZE__ = {
            seed: {{ $seed }},
            ancho: {{ $ancho }},
            alto: {{ $alto }},
            token: @json($token),
        };
    </script>

    <div x-data="game" x-on:keydown.window="mover($event)" class="flex items-start gap-4 p-4">
        <canvas x-ref="canvas" class="shrink-0"></canvas>

        <aside class="w-56 shrink-0 overflow-y-auto font-mono text-sm" :style="`max-height: ${alturaPx}px`">
            <p>seed: <span x-text="seed"></span></p>
            <a href="{{ route('jugar.crear') }}" class="mt-1 inline-block rounded border px-2 py-1">nueva partida</a>
            <p x-show="terminado" class="mt-2 font-bold">laberinto finalizado</p>
            <ol class="mt-2 space-y-0.5">
                <template x-for="(m, i) in movimientos" :key="i">
                    <li x-text="(i + 1) + '. ' + m.dir + ' x' + m.x + 'y' + m.y"></li>
                </template>
            </ol>
        </aside>
    </div>
</body>
</html>
