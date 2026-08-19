<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Services\ResolutorDePrecio;
use Carbon\Carbon;

function resolutorDePrecio(): ResolutorDePrecio
{
    return app(ResolutorDePrecio::class);
}

const DIA_DE_LA_ATENCION = '2026-08-19';

/*
|--------------------------------------------------------------------------
| La escalera
|--------------------------------------------------------------------------
*/

it('usa el precio de lista cuando el pagador no tiene el suyo', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    Tarifario::factory()->delItem($item)->a('29.3300')->create();

    $precio = resolutorDePrecio()->para($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION));

    expect($precio->precio)->toBeMonto('29.33')
        ->and($precio->origen)->toBe(OrigenDelPrecio::PrecioDeLista)
        ->and($precio->esNegociado())->toBeFalse();
})->note('La fila sin convenio es la que siempre responde. Sin ella, un ítem no se puede cobrar y punto.');

it('el precio negociado con el pagador le gana a la lista', function (): void {
    $item = Item::factory()->create();
    $aseguradora = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);

    Tarifario::factory()->delItem($item)->a('29.3300')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($aseguradora)->a('25.0000')->create();

    $precio = resolutorDePrecio()->para($item, $aseguradora, Carbon::parse(DIA_DE_LA_ATENCION));

    expect($precio->precio)->toBeMonto('25.00')
        ->and($precio->origen)->toBe(OrigenDelPrecio::PrecioNegociado);
});

it('el precio de esta sede le gana al que vale para todas', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();
    $sede = Sede::factory()->create();

    Tarifario::factory()->delItem($item)->a('29.3300')->create();
    Tarifario::factory()->delItem($item)->enLaSede($sede)->a('31.0000')->create();

    $precio = resolutorDePrecio()->para($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION), $sede);

    expect($precio->precio)->toBeMonto('31.00')
        ->and($precio->fila->sede_id)->toBe($sede->id);
});

it('lo firmado con el pagador manda sobre la politica de la sede', function (): void {
    $item = Item::factory()->create();
    $aseguradora = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);
    $sede = Sede::factory()->create();

    Tarifario::factory()->delItem($item)->enLaSede($sede)->a('31.0000')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($aseguradora)->a('25.0000')->create();

    $precio = resolutorDePrecio()->para($item, $aseguradora, Carbon::parse(DIA_DE_LA_ATENCION), $sede);

    expect($precio->precio)->toBeMonto('25.00');
})->note('Si el precio de la sede le ganara al del convenio, el hospital estaría cobrándole a la aseguradora algo distinto de lo que firmó. El orden del resolutor es convenio primero, sede después.');

it('la sede especifica del convenio le gana a la del convenio para todas', function (): void {
    $item = Item::factory()->create();
    $aseguradora = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);
    $sede = Sede::factory()->create();

    Tarifario::factory()->delItem($item)->paraElConvenio($aseguradora)->a('25.0000')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($aseguradora)->enLaSede($sede)->a('27.0000')->create();

    $precio = resolutorDePrecio()->para($item, $aseguradora, Carbon::parse(DIA_DE_LA_ATENCION), $sede);

    expect($precio->precio)->toBeMonto('27.00');
});

/*
|--------------------------------------------------------------------------
| La fecha del servicio
|--------------------------------------------------------------------------
*/

it('reimprimir una factura de marzo da el precio de marzo', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    Tarifario::factory()->delItem($item)->vigenteEntre('2026-01-01', '2026-06-30')->a('20.0000')->create();
    Tarifario::factory()->delItem($item)->vigenteEntre('2026-07-01')->a('29.3300')->create();

    $enMarzo = resolutorDePrecio()->para($item, $convenio, Carbon::parse('2026-03-15'));
    $hoy = resolutorDePrecio()->para($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION));

    expect($enMarzo->precio)->toBeMonto('20.00')
        ->and($hoy->precio)->toBeMonto('29.33');
})->note('Si la reimpresión diera el precio de hoy, cada copia sería un documento distinto del que el paciente firmó.');

it('no hay precio antes de que el tarifario arranque', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    Tarifario::factory()->delItem($item)->vigenteEntre('2026-07-01')->create();

    resolutorDePrecio()->para($item, $convenio, Carbon::parse('2026-03-15'));
})->throws(PrecioNoDefinidoException::class);

/*
|--------------------------------------------------------------------------
| Lo que se niega a hacer
|--------------------------------------------------------------------------
*/

it('se niega a inventar un precio cuando no hay ninguno', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    resolutorDePrecio()->para($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION));
})->throws(PrecioNoDefinidoException::class)
    ->note('La tentación es devolver cero, o el último precio conocido, o el costo. Las tres cobran mal: regalan el producto, facturan con tarifa vencida o venden sin margen.');

it('avisa si el item se puede cobrar sin reventar', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    expect(resolutorDePrecio()->hayPrecio($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION)))->toBeFalse();

    Tarifario::factory()->delItem($item)->create();

    expect(resolutorDePrecio()->hayPrecio($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION)))->toBeTrue();
})->note('La pantalla necesita avisar ANTES de que alguien arme una cuenta con un ítem sin precio, no reventar al facturar.');

/*
|--------------------------------------------------------------------------
| Lo que se puede contestar después
|--------------------------------------------------------------------------
*/

it('devuelve la fila que explica el precio, no solo el monto', function (): void {
    $item = Item::factory()->create();
    $convenio = Convenio::factory()->contado()->create();

    $fila = Tarifario::factory()
        ->delItem($item)
        ->a('29.3300')
        ->create(['motivo' => 'Derivado del costo con margen objetivo del 120 %.']);

    $precio = resolutorDePrecio()->para($item, $convenio, Carbon::parse(DIA_DE_LA_ATENCION));

    expect($precio->fila->id)->toBe($fila->id)
        ->and($precio->explicacion())->toContain('Precio de lista')
        ->and($precio->explicacion())->toContain('120 %')
        ->and($precio->explicacion())->toContain('01/01/2026');
})->note('La factura guarda el id de esta fila, así que el reclamo de dentro de dos años se contesta leyendo un registro en vez de reconstruyendo la historia a mano.');

it('el precio de lista tambien responde para un item que no se compra', function (): void {
    $honorario = Item::factory()->de(
        TipoItem::Honorario,
        CategoriaLegalDeDescuento::ConsultaEspecializada,
    )->create();

    Tarifario::factory()->delItem($honorario)->a('800.0000')->create();

    $precio = resolutorDePrecio()->deLista($honorario, Carbon::parse(DIA_DE_LA_ATENCION));

    expect($precio->precio)->toBeMonto('800.00');
})->note('Ruta B del §4.1: el honorario de un cirujano no tiene costo de compra del cual derivar, así que su precio se fija a mano. El resolutor no distingue: lee la misma tabla.');
