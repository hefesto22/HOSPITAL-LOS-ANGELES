<?php

declare(strict_types=1);

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\ValueObjects\Decimal;
use App\Models\Descuento;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\PlantillaPresupuesto;
use App\Models\TurnoDeCaja;
use App\Services\FijadorDeDescuento;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 «QUE AL ESCRIBIR SIEMPRE SE VEA EN MAYÚSCULAS TODO»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Pedido de Mauricio (3-sep-2026). Suena a CSS y no lo era: había cinco
 * columnas que quedaban canónicas o no según por qué puerta entrara el
 * dato, y en dos de ellas eso no era cosmética.
 *
 * Estas pruebas van contra el MODELO y no contra el formulario, que es
 * donde tiene que estar la garantía: el formulario no es la única puerta
 * —un seeder, un import de catálogo o un comando escriben directo— y una
 * regla que solo vive en el formulario no es una regla.
 */
it('la presentacion se guarda en mayusculas venga de donde venga', function (): void {
    $presentacion = ItemPresentacion::factory()->create(['nombre' => 'caja x 100 tabletas']);

    expect($presentacion->fresh()?->nombre)->toBe('CAJA X 100 TABLETAS');
})->note('El modal ya la mostraba en mayúsculas mientras se escribía, pero lo que viajaba era lo tecleado.');

it('🔴 el numero de lote se guarda en mayusculas', function (): void {
    $lote = Lote::factory()->create(['numero' => 'lot-1']);

    expect($lote->fresh()?->numero)->toBe('LOT-1');
})->note('🔴 «lot-1» y «LOT-1» son dos lotes del mismo producto, con dos existencias y dos vencimientos. FEFO sugiere el que vence primero DE LOS QUE VE, así que con el saldo partido en dos sugiere el que no era.');

it('el nombre de la plantilla se guarda en mayusculas', function (): void {
    $plantilla = PlantillaPresupuesto::factory()->create(['nombre' => 'Apendicectomia laparoscopica']);

    expect($plantilla->fresh()?->nombre)->toBe('APENDICECTOMIA LAPAROSCOPICA');
})->note('El formulario la canonizaba y la acción «Guardar como plantilla» no: dos convenciones para una columna.');

it('el nombre del turno se guarda en mayusculas', function (): void {
    $turno = TurnoDeCaja::factory()->create(['nombre' => 'Turno a']);

    expect($turno->fresh()?->nombre)->toBe('TURNO A');
})->note('Sale impreso en el corte de caja, al lado de datos que ya van en mayúsculas.');

it('conserva la enie y las tildes al pasar a mayusculas', function (): void {
    $presentacion = ItemPresentacion::factory()->create(['nombre' => 'cajón de ampollas peña']);

    expect($presentacion->fresh()?->nombre)->toBe('CAJÓN DE AMPOLLAS PEÑA');
})->note('`strtoupper()` a secas dejaría «CAJóN» y «PEñA», que es peor que no haber hecho nada. Y quitar las tildes rompería la factura contra el documento.');

/*
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL DESCUENTO SE BUSCA POR NOMBRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Este es el que dejó de ser cosmética. `FijadorDeDescuento` busca el
 * vigente con un `where` exacto sobre el nombre: sin canonizar antes de
 * buscar, el segundo no veía al primero, no lo cerraba, y quedaban dos
 * descuentos vigentes con el mismo significado — los dos saliendo
 * impresos en facturas.
 */
it('🔴 fijar el mismo descuento escrito distinto no crea uno nuevo', function (): void {
    $fijador = app(FijadorDeDescuento::class);

    $primero = $fijador->fijar(
        nombre: 'Tercera edad',
        aplicaA: AplicacionDeDescuento::Manual,
        porcentaje: Decimal::de('0.25'),
        desde: now()->subMonth(),
    );

    $segundo = $fijador->fijar(
        nombre: 'TERCERA EDAD',
        aplicaA: AplicacionDeDescuento::Manual,
        porcentaje: Decimal::de('0.30'),
        desde: now(),
    );

    expect($primero->nombre)->toBe('TERCERA EDAD')
        ->and($segundo->nombre)->toBe('TERCERA EDAD')
        ->and(Descuento::query()->where('nombre', 'TERCERA EDAD')->count())->toBe(2);

    expect($primero->fresh()?->vigencia_hasta)->not->toBeNull();
})->note('🔴 Son dos filas —el histórico no se pisa— pero la primera queda CERRADA. Sin canonizar antes de buscar quedaban las dos abiertas, y cuál se le aplicaba al paciente lo decidía el ORDER BY.');

it('el descuento se busca por el nombre canonico y no por el tecleado', function (): void {
    $fijador = app(FijadorDeDescuento::class);

    $fijador->fijar(
        nombre: '  tercera    edad  ',
        aplicaA: AplicacionDeDescuento::Manual,
        porcentaje: Decimal::de('0.25'),
        desde: now(),
    );

    expect(Descuento::query()->where('nombre', 'TERCERA EDAD')->exists())->toBeTrue();
})->note('Los espacios de más también: «tercera  edad» con dos espacios era otro descuento.');

it('un item con nombre en minusculas queda en mayusculas', function (): void {
    $item = Item::factory()->create(['nombre' => 'acetaminofen 500 mg tableta']);

    expect($item->fresh()?->nombre)->toBe('ACETAMINOFEN 500 MG TABLETA');
})->note('Ya estaba, y se prueba acá para que quede junto al resto: es la misma regla.');
