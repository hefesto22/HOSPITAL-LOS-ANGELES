<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use App\Services\ResolutorDeHonorario;

/**
 * El mismo honorario del catálogo, un precio distinto por médico.
 *
 * «CONSULTA EXTERNA MEDICINA INTERNA» es un solo ítem, pero el doctor
 * Carlos cobra L 500 y el doctor Juan L 100. Estas pruebas fijan que el
 * resolutor devuelva el número de CADA doctor, y —lo más importante— que
 * devuelva NULO cuando el médico no tiene lista propia, que es la señal
 * de «cobrá lo que dice el tarifario».
 */
function honorarioDelCatalogo(): Item
{
    return Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)->create();
}

function resolutorDeHonorario(): ResolutorDeHonorario
{
    return new ResolutorDeHonorario;
}

it('devuelve el precio de cada medico para el mismo honorario', function (): void {
    $consulta = honorarioDelCatalogo();

    $carlos = Medico::factory()->create(['nombre' => 'CARLOS PINEDA']);
    $juan = Medico::factory()->create(['nombre' => 'JUAN MEJIA']);

    HonorarioMedico::factory()->create([
        'medico_id' => $carlos->id,
        'item_id'   => $consulta->id,
        'precio'    => '500.0000',
    ]);

    HonorarioMedico::factory()->create([
        'medico_id' => $juan->id,
        'item_id'   => $consulta->id,
        'precio'    => '100.0000',
    ]);

    expect(resolutorDeHonorario()->para($carlos, $consulta)?->valor())->toBe('500.00')
        ->and(resolutorDeHonorario()->para($juan, $consulta)?->valor())->toBe('100.00');
});

it('🔴 devuelve nulo y no cero cuando el medico no tiene lista propia', function (): void {
    $consulta = honorarioDelCatalogo();
    $medico = Medico::factory()->create();

    expect(resolutorDeHonorario()->para($medico, $consulta))->toBeNull();
})->note('Cero regalaría el honorario. Nulo significa «cobrá lo que dice el tarifario», que es lo que hace la pantalla.');

it('ignora el precio cuya vigencia ya se cerro', function (): void {
    $consulta = honorarioDelCatalogo();
    $medico = Medico::factory()->create();

    HonorarioMedico::factory()->cerrado()->create([
        'medico_id' => $medico->id,
        'item_id'   => $consulta->id,
        'precio'    => '500.0000',
    ]);

    expect(resolutorDeHonorario()->para($medico, $consulta))->toBeNull();
});

it('con dos precios abiertos manda el que empezo despues', function (): void {
    $consulta = honorarioDelCatalogo();
    $medico = Medico::factory()->create();

    HonorarioMedico::factory()->create([
        'medico_id'      => $medico->id,
        'item_id'        => $consulta->id,
        'precio'         => '500.0000',
        'vigencia_desde' => now()->subMonths(6)->toDateString(),
    ]);

    HonorarioMedico::factory()->create([
        'medico_id'      => $medico->id,
        'item_id'        => $consulta->id,
        'precio'         => '650.0000',
        'vigencia_desde' => now()->subMonth()->toDateString(),
    ]);

    expect(resolutorDeHonorario()->para($medico, $consulta)?->valor())->toBe('650.00');
})->note('El índice único impide dos filas con la misma fecha de inicio, pero no una de enero y otra de junio las dos abiertas: manda la última que alguien decidió.');

it('🔴 no le pone precio de medico a lo que no es un honorario', function (): void {
    $medicamento = Item::factory()->medicamento()->create();
    $medico = Medico::factory()->create();

    /*
     * La fila puede existir —la base no conoce el tipo del ítem— y aun
     * así el resolutor no la usa: un medicamento con «precio de médico»
     * saltearía el margen sobre el costo promedio, que es de donde sale
     * el precio de farmacia.
     */
    HonorarioMedico::factory()->create([
        'medico_id' => $medico->id,
        'item_id'   => $medicamento->id,
        'precio'    => '1.0000',
    ]);

    expect(resolutorDeHonorario()->para($medico, $medicamento))->toBeNull();
});

it('el medico se lee con su especialidad en una sola linea', function (): void {
    $medico = Medico::factory()->create(['nombre' => 'CARLOS PINEDA']);
    $medico->especialidad->update(['nombre' => 'CIRUGIA GENERAL']);

    expect($medico->fresh()?->etiqueta())->toBe('CARLOS PINEDA · CIRUGIA GENERAL');
});

it('el medico con vigencia cerrada no aparece entre los vigentes', function (): void {
    Medico::factory()->create(['nombre' => 'ACTIVO']);
    Medico::factory()->cerrado()->create(['nombre' => 'RETIRADO']);

    $vigentes = Medico::query()->vigentes()->pluck('nombre')->all();

    expect($vigentes)->toContain('ACTIVO')
        ->and($vigentes)->not->toContain('RETIRADO');
});
