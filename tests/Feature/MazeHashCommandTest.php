<?php

test('maze:hash imprime el hash de paridad para un seed y tamaño dados', function () {
    $this->artisan('maze:hash', ['seed' => 1, '--ancho' => 10, '--alto' => 10])
        ->expectsOutputToContain('d2c1a5b8ab4caf9d85bacb1864a8ec6a9063db17284e7e1c0a311223fd5b8b9a')
        ->assertExitCode(0);
});

test('maze:hash usa 100x100 por defecto', function () {
    $this->artisan('maze:hash', ['seed' => 7])
        ->expectsOutputToContain('grid:  100x100')
        ->expectsOutputToContain('1675b97c02b874770028bbb2babe660d09a92e7af6a9f6b19c5266952e4210d6')
        ->assertExitCode(0);
});
