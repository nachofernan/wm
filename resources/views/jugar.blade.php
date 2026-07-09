<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Wizard's Maze</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <script>
        window.__MAZE__ = {
            seed: {{ $seed }},
            ancho: {{ $ancho }},
            alto: {{ $alto }},
        };
    </script>

    <div x-data="game" x-on:keydown.window="mover($event)">
        <canvas x-ref="canvas"></canvas>
    </div>
</body>
</html>
