<?php

use App\Game\Prng;

/**
 * Vector de paridad: seed fijo → primeros 5 valores de next(). Estos mismos
 * seeds y valores están commiteados en resources/js/prng.test.js. Si un test
 * cambia, el otro tiene que cambiar igual, o la paridad PHP/JS está rota.
 */
dataset('seeds_prng', [
    'seed 0' => [0, [1144304738, 1416247, 958946056, 627933444, 2007157716]],
    'seed 42' => [42, [2581720956, 1925393290, 3661312704, 2876485805, 750819978]],
    'seed uint32 max' => [4294967295, [3850105811, 813802916, 3073704848, 4054706436, 3630262831]],
    'seed 123456789' => [123456789, [1107202814, 4169434471, 3372958138, 885470128, 1301683845]],
]);

test('produce la secuencia esperada para un seed fijo', function (int $seed, array $esperado) {
    $prng = new Prng($seed);

    $obtenido = array_map(fn () => $prng->next(), range(1, count($esperado)));

    expect($obtenido)->toBe($esperado);
})->with('seeds_prng');

test('la misma seed siempre produce la misma secuencia', function () {
    $a = new Prng(2026);
    $b = new Prng(2026);

    $secuenciaA = array_map(fn () => $a->next(), range(1, 10));
    $secuenciaB = array_map(fn () => $b->next(), range(1, 10));

    expect($secuenciaA)->toBe($secuenciaB);
});

test('randBelow devuelve valores dentro de rango', function () {
    $prng = new Prng(2026);

    foreach (range(1, 100) as $_) {
        expect($prng->randBelow(4))->toBeGreaterThanOrEqual(0)->toBeLessThan(4);
    }
});
