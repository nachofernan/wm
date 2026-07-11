<?php

it('la raíz arranca una partida y redirige', function () {
    $response = $this->get('/');

    $response->assertRedirect();
});
