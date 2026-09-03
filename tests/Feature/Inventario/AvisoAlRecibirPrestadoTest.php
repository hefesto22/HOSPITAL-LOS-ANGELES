<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\Producto;
use App\Models\Unidad;
use App\Services\AvisoDeLoQueSeDebe;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL AVISO AL RECIBIR MERCADERÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: «cuando a nosotros nos entre de ese medicamento a
 * bodega o farmacia, que aparezca que hay que devolverle x cantidad a x
 * empresa o persona».
 *
 * Es la pieza que hace que el módulo de préstamos sirva de algo. Un
 * préstamo se pide un martes a las once de la noche porque no había, y
 * solo se puede devolver el día que llega la compra: entre esos dos
 * momentos no hay nada que hacer. Una lista de deudas que hay que acordarse
 * de abrir no se abre; el aviso que aparece SOLO, con la caja en la mano,
 * sí.
 */
it('avisa lo que hay que devolver del producto que esta entrando', function (): void {
    $unidad = Unidad::factory()->create(['codigo' => 'TAB']);
    $producto = Producto::factory()->create([
        'nombre'                 => 'ACETAMINOFEN 500 MG TABLETA',
        'unidad_dispensacion_id' => $unidad->getKey(),
    ]);

    Prestamo::factory()->create([
        'item_id'       => $producto->getKey(),
        'cantidad'      => '20.0000',
        'presta_nombre' => 'FARMACIA SAN JOSE',
    ]);

    $frase = app(AvisoDeLoQueSeDebe::class)->delItem($producto->getKey());

    expect($frase)->toContain('20')
        ->and($frase)->toContain('TAB')
        ->and($frase)->toContain('ACETAMINOFEN 500 MG TABLETA')
        ->and($frase)->toContain('FARMACIA SAN JOSE');
})->note('Cuánto, de qué y a quién. Las tres cosas o el aviso no se puede accionar: «tenés una deuda» manda a buscar el dato a otra pantalla, y ahí se pierde.');

it('no avisa nada de un producto que no se le debe a nadie', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'IBUPROFENO 400 MG TABLETA']);

    expect(app(AvisoDeLoQueSeDebe::class)->delItem($producto->getKey()))->toBeNull();
})->note('Null y no una cadena vacía: quien llama pregunta «¿hay algo que avisar?» sin comparar contra "". Un recuadro vacío pintado igual que uno lleno se aprende a saltear.');

it('🔴 no avisa lo que trajo la familia del paciente', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'CEFTRIAXONA 1 G VIAL']);

    Prestamo::factory()->delPaciente()->create(['item_id' => $producto->getKey()]);

    expect(app(AvisoDeLoQueSeDebe::class)->delItem($producto->getKey()))->toBeNull();
})->note('🔴 Se registra para que el kardex cuadre y el medicamento quede trazado, pero no hay a quién devolvérselo. Un aviso con ruido se aprende a ignorar en tres recepciones, y el día que la deuda sea real ya nadie lo lee.');

it('no avisa un prestamo que ya se saldo', function (): void {
    $producto = Producto::factory()->create(['nombre' => 'OMEPRAZOL 40 MG VIAL']);

    Prestamo::factory()->saldado()->create(['item_id' => $producto->getKey()]);

    expect(app(AvisoDeLoQueSeDebe::class)->delItem($producto->getKey()))->toBeNull();
})->note('La deuda cerrada no vuelve a aparecer al recibir más del mismo producto.');

it('avisa lo que falta y no lo que se presto', function (): void {
    $unidad = Unidad::factory()->create(['codigo' => 'TAB']);
    $producto = Producto::factory()->create([
        'nombre'                 => 'ENALAPRIL 10 MG TABLETA',
        'unidad_dispensacion_id' => $unidad->getKey(),
    ]);

    Prestamo::factory()->create([
        'item_id'          => $producto->getKey(),
        'cantidad'         => '30.0000',
        'cantidad_saldada' => '18.0000',
        'estado'           => EstadoPrestamo::Parcial,
    ]);

    expect(app(AvisoDeLoQueSeDebe::class)->delItem($producto->getKey()))
        ->toContain('12')
        ->not->toContain('30');
})->note('La pregunta del que está recibiendo es «cuánto falta», no «cuánto fue». Decirle 30 cuando faltan 12 es que devuelva de más.');

it('junta en un solo aviso todo lo que se debe de la recepcion', function (): void {
    $uno = Producto::factory()->create(['nombre' => 'DIPIRONA 1 G AMPOLLA']);
    $otro = Producto::factory()->create(['nombre' => 'TRAMADOL 100 MG AMPOLLA']);
    $limpio = Producto::factory()->create(['nombre' => 'GASA ESTERIL']);

    Prestamo::factory()->create(['item_id' => $uno->getKey(), 'presta_nombre' => 'CLINICA DEL VALLE']);
    Prestamo::factory()->create(['item_id' => $otro->getKey(), 'presta_nombre' => 'DR. MEJIA']);

    $frase = app(AvisoDeLoQueSeDebe::class)->frase([
        $uno->getKey(),
        $otro->getKey(),
        $limpio->getKey(),
    ]);

    expect($frase)->toContain('CLINICA DEL VALLE')
        ->and($frase)->toContain('DR. MEJIA')
        ->and($frase)->not->toContain('GASA ESTERIL');
})->note('Una recepción trae varias cajas. Un aviso por producto es una torre de avisos que se cierra sin leer.');

it('no consulta nada cuando la recepcion todavia no tiene productos', function (): void {
    expect(app(AvisoDeLoQueSeDebe::class)->frase([]))->toBeNull();
})->note('El formulario abre vacío y el aviso se pregunta en cada tecla.');
