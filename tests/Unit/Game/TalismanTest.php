<?php

use App\Game\MazeCombate;
use App\Game\Talisman;

/** Talismán inicial con una gema suelta en el inventario para probar el swap. */
function talismanConInventario(): array
{
    $t = MazeCombate::talismanInicial(); // 4 fieldeadas (3×4 = 12 = cap)
    $t['gemas'][] = ['id' => 9, 'elemento' => 'aire', 'nivel' => 2, 'carga' => 12, 'fieldeada' => false];

    return $t;
}

test('capEnUso suma los niveles de las gemas fieldeadas', function () {
    expect(Talisman::capEnUso(MazeCombate::talismanInicial()))->toBe(12);
});

test('fieldear una gema que no entra en el cap es rechazado', function () {
    // El talismán inicial ya usa 12/12: no entra nada más.
    $r = Talisman::aplicar(talismanConInventario(), 'fieldear', 9);

    expect($r['error'])->toBe('no entra en el cap');
});

test('guardar libera cap y después la gema del inventario entra', function () {
    $t = talismanConInventario();

    // Guardo la de tierra (n3) → cap en uso 9/12.
    $t = Talisman::aplicar($t, 'guardar', 3)['talisman'];
    expect(Talisman::capEnUso($t))->toBe(9);

    // Ahora la de aire (n2) entra.
    $r = Talisman::aplicar($t, 'fieldear', 9);
    expect($r['error'])->toBeNull();
    $equipada = collect($r['talisman']['gemas'])->firstWhere('id', 9);
    expect($equipada['fieldeada'])->toBeTrue();
    expect(Talisman::capEnUso($r['talisman']))->toBe(11);
});

test('desguazar una gema del inventario suma esencia y la saca', function () {
    $r = Talisman::aplicar(talismanConInventario(), 'desguazar', 9);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['esencia'])->toBe(2); // +nivel (2)
    expect(collect($r['talisman']['gemas'])->firstWhere('id', 9))->toBeNull();
});

test('una gema fieldeada no se desguaza', function () {
    $r = Talisman::aplicar(MazeCombate::talismanInicial(), 'desguazar', 1);

    expect($r['error'])->toBe('gema inválida');
});

test('el talismán inicial deriva cap, defensa y ataque de nivel + gemas fieldeadas', function () {
    $t = MazeCombate::talismanInicial();

    expect($t['nivel'])->toBe(1);
    expect($t['cap'])->toBe(12);           // CAP_BASE
    expect($t['defensa'])->toBe(8 + 18);   // base 8 + agua n3 + tierra n3 fieldeadas (3×3 cada una)
    expect($t['ataqueMult'])->toBe(0.30);  // fuego n3 + aire n3 fieldeadas (3×0.05 cada una)
});

test('subir nivel cuesta esencia y sube cap y defensa base', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 12;

    $r = Talisman::aplicar($t, 'subirNivel', null);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['nivel'])->toBe(2);
    expect($r['talisman']['cap'])->toBe(22);           // 12 + 10
    expect($r['talisman']['defensa'])->toBe(12 + 18);  // base nivel 2 (12) + agua n3 + tierra n3
    expect($r['talisman']['esencia'])->toBe(2);        // 12 − (1 × 10)
});

test('subir nivel sube el tope de vida en 10 y cura al 100% (028)', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 10;
    $t['vida'] = 12; // maltrecho: la subida tiene que llenarlo

    $r = Talisman::aplicar($t, 'subirNivel', null);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['vidaMax'])->toBe(50); // 40 + 10
    expect($r['talisman']['vida'])->toBe(50);    // cura al 100%
});

test('el acople gema→stat: fieldear agua sube defensa, guardar fuego baja ataque', function () {
    // Arranco de un talismán limpio nivel 1 sin gemas, y armo el loadout a mano.
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 3,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'carga' => 10, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'fuego', 'nivel' => 5, 'carga' => 10, 'fieldeada' => true],
        ],
    ]);
    expect($t['defensa'])->toBe(8);        // base, ninguna agua fieldeada
    expect($t['ataqueMult'])->toBe(0.25);  // fuego n5 fieldeada

    // Fieldeo la agua n4 (entra: 5 + 4 = 9 ≤ 12) → +12 de defensa.
    $t = Talisman::aplicar($t, 'fieldear', 1)['talisman'];
    expect($t['defensa'])->toBe(8 + 12);

    // Guardo la fuego n5 → el ataque se cae a 0.
    $t = Talisman::aplicar($t, 'guardar', 2)['talisman'];
    expect($t['ataqueMult'])->toBe(0.0);
});

test('una gema inerte (carga 0) no potencia la hoja', function () {
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 2,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'carga' => 0, 'fieldeada' => true],
        ],
    ]);

    expect($t['defensa'])->toBe(8); // agua fieldeada pero seca: no suma
});

test('el eje elemental interino (025): aire potencia ataque, tierra potencia defensa', function () {
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 3,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'aire', 'nivel' => 4, 'carga' => 10, 'fieldeada' => true],
            ['id' => 2, 'elemento' => 'tierra', 'nivel' => 2, 'carga' => 10, 'fieldeada' => true],
        ],
    ]);

    expect($t['ataqueMult'])->toBe(0.20); // aire n4 → 4 × 0.05
    expect($t['defensa'])->toBe(8 + 6);   // base 8 + tierra n2 (2 × 3)
});

test('el tope de ranuras rechaza fieldear una 7ª gema aunque entre en el cap', function () {
    // 6 gemas n1 fieldeadas (suma 6 ≤ cap 12) + una 7ª guardada.
    $gemas = [];
    foreach (range(1, 7) as $id) {
        $gemas[] = ['id' => $id, 'elemento' => 'fuego', 'nivel' => 1, 'carga' => 6, 'fieldeada' => $id <= 6];
    }
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 8,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0, 'gemas' => $gemas,
    ]);

    expect(Talisman::ranurasEnUso($t))->toBe(6);
    expect(Talisman::capEnUso($t))->toBe(6); // el cap sobra: 6 ≤ 12

    $r = Talisman::aplicar($t, 'fieldear', 7);
    expect($r['error'])->toBe('no hay ranura libre');
});

test('fusionar dos gemas del mismo tipo y nivel da una de nivel+1 con la carga sumada', function () {
    // Ejemplo del pedido: n3 (10 ⚡) + n3 (2 ⚡) = n4 (12 ⚡), guardada.
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 2, 'proximoId' => 5,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 10, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 2, 'fieldeada' => false],
        ],
    ]);

    $r = Talisman::aplicar($t, 'fusionar', 1, 2);

    expect($r['error'])->toBeNull();
    $gemas = $r['talisman']['gemas'];
    expect($gemas)->toHaveCount(1);
    expect($gemas[0])->toMatchArray([
        'id' => 5, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 12, 'fieldeada' => false,
    ]);
    expect($r['talisman']['proximoId'])->toBe(6);
    expect($r['talisman']['esencia'])->toBe(1); // 2 − COSTO_FUSION (027)
});

test('la fusión recorta la carga al tope de la gema nueva (N×6): el sobrante se pierde (026)', function () {
    // Dos n3 con 15 + 15 = 30 → n4, tope 4×6 = 24: sobran 6 y se descartan.
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 1, 'proximoId' => 5,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'agua', 'nivel' => 3, 'carga' => 15, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'agua', 'nivel' => 3, 'carga' => 15, 'fieldeada' => false],
        ],
    ]);

    $r = Talisman::aplicar($t, 'fusionar', 1, 2);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0])->toMatchArray([
        'elemento' => 'agua', 'nivel' => 4, 'carga' => 24, // min(30, 24)
    ]);
});

test('fusionar rechaza tipos o niveles distintos, la misma gema, y gemas fieldeadas', function () {
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 6,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 6, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'agua', 'nivel' => 3, 'carga' => 6, 'fieldeada' => false],
            ['id' => 3, 'elemento' => 'fuego', 'nivel' => 4, 'carga' => 6, 'fieldeada' => false],
            ['id' => 4, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 6, 'fieldeada' => true],
        ],
    ]);

    expect(Talisman::aplicar($t, 'fusionar', 1, 2)['error'])->toBe('no coinciden tipo y nivel');
    expect(Talisman::aplicar($t, 'fusionar', 1, 3)['error'])->toBe('no coinciden tipo y nivel');
    expect(Talisman::aplicar($t, 'fusionar', 1, 1)['error'])->toBe('elegí dos gemas distintas');
    expect(Talisman::aplicar($t, 'fusionar', 1, 4)['error'])->toBe('gema inválida'); // la 4 está fieldeada
});

test('fusionar sin esencia suficiente es rechazado y no muta nada (027)', function () {
    // Par válido (mismo tipo y nivel, ambas guardadas) pero esencia 0.
    $t = Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => 0, 'proximoId' => 5,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0,
        'gemas' => [
            ['id' => 1, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 6, 'fieldeada' => false],
            ['id' => 2, 'elemento' => 'fuego', 'nivel' => 3, 'carga' => 6, 'fieldeada' => false],
        ],
    ]);

    $r = Talisman::aplicar($t, 'fusionar', 1, 2);

    expect($r['error'])->toBe('esencia insuficiente para fusionar');
    expect($r['talisman']['gemas'])->toHaveCount(2);       // no se fusionó
    expect($r['talisman']['proximoId'])->toBe(5);          // no se consumió id
});

test('vaciar manda todas las gemas fieldeadas al inventario (027)', function () {
    $t = MazeCombate::talismanInicial(); // 4 gemas fieldeadas (cap 12/12)

    $r = Talisman::aplicar($t, 'vaciar', null);

    expect($r['error'])->toBeNull();
    expect(collect($r['talisman']['gemas'])->every(fn ($g) => $g['fieldeada'] === false))->toBeTrue();
    expect(Talisman::ranurasEnUso($r['talisman']))->toBe(0);
    expect(Talisman::capEnUso($r['talisman']))->toBe(0);
});

test('subir nivel sin esencia suficiente es rechazado', function () {
    $r = Talisman::aplicar(MazeCombate::talismanInicial(), 'subirNivel', null);

    expect($r['error'])->toBe('esencia insuficiente');
});

test('curar convierte esencia en vida 1:1', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 30;      // faltan 10 para el tope (40)
    $t['esencia'] = 6;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['talisman']['vida'])->toBe(36);    // +6
    expect($r['talisman']['esencia'])->toBe(0);  // −6
});

test('curar no se pasa del tope de vida ni malgasta esencia', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 38;      // faltan 2 para el tope
    $t['esencia'] = 10;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['talisman']['vida'])->toBe(40);    // llega al tope
    expect($r['talisman']['esencia'])->toBe(8);  // solo gastó 2
});

test('curar sin esencia es rechazado', function () {
    $t = MazeCombate::talismanInicial();
    $t['vida'] = 20;

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['error'])->toBe('sin esencia');
});

test('curar con la vida llena es rechazado', function () {
    $t = MazeCombate::talismanInicial();
    $t['esencia'] = 5; // vida ya en el tope

    $r = Talisman::aplicar($t, 'curar', null);

    expect($r['error'])->toBe('vida llena');
});

/** Talismán limpio nivel 1 con una sola gema (fieldeada o no), para recargar. */
function talismanConUnaGema(array $gema, int $esencia): array
{
    return Talisman::recomputar([
        'nivel' => 1, 'vida' => 40, 'vidaMax' => 40, 'esencia' => $esencia, 'proximoId' => 99,
        'bichosCaidos' => 0, 'gemasJuntadas' => 0, 'gemas' => [$gema],
    ]);
}

test('recargar lleva la carga al tope y cuesta el nivel de la gema en esencia (028)', function () {
    // N7 en 0: recargar cuesta 7 y la deja en 42 (7 × 6).
    $t = talismanConUnaGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 7, 'carga' => 0, 'fieldeada' => true], 10);

    $r = Talisman::aplicar($t, 'recargar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(42); // 7 × CARGA_POR_NIVEL
    expect($r['talisman']['esencia'])->toBe(3);            // 10 − 7
});

test('recargar cuesta el nivel completo aunque la gema tenga carga parcial y no hay parciales (028)', function () {
    // N4 con 15/24: recargar igual cuesta 4 y la deja en 24 (no en 24−15).
    $t = talismanConUnaGema(['id' => 1, 'elemento' => 'agua', 'nivel' => 4, 'carga' => 15, 'fieldeada' => true], 5);

    $r = Talisman::aplicar($t, 'recargar', 1);

    expect($r['error'])->toBeNull();
    expect($r['talisman']['gemas'][0]['carga'])->toBe(24); // 4 × 6, tope entero
    expect($r['talisman']['esencia'])->toBe(1);            // 5 − 4
});

test('recargar sin esencia suficiente es rechazado y no muta nada (028)', function () {
    $t = talismanConUnaGema(['id' => 1, 'elemento' => 'fuego', 'nivel' => 7, 'carga' => 0, 'fieldeada' => true], 6);

    $r = Talisman::aplicar($t, 'recargar', 1);

    expect($r['error'])->toBe('esencia insuficiente para recargar');
    expect($r['talisman']['gemas'][0]['carga'])->toBe(0); // intacta
    expect($r['talisman']['esencia'])->toBe(6);           // intacta
});

test('recargar una gema con la carga llena es rechazado (028)', function () {
    $t = talismanConUnaGema(['id' => 1, 'elemento' => 'tierra', 'nivel' => 3, 'carga' => 18, 'fieldeada' => true], 10);

    $r = Talisman::aplicar($t, 'recargar', 1);

    expect($r['error'])->toBe('la gema ya tiene la carga llena');
    expect($r['talisman']['esencia'])->toBe(10); // no malgasta
});
