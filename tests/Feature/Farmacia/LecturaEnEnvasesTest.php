<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use App\Services\LecturaEnEnvases;

/*
 * «15 tabletas» no le dice nada a quien está frente al estante.
 * «1 BLISTER X 10 + 5 TAB» sí.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ES UNA LECTURA Y NO UN SALDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Planteo de Mauricio (3-sep-2026): «no tiene lógica que 1 caja de 100 se
 * abre, se sacan 3, quedan 97 — el personal es mentira que sabrá eso, ya
 * que son varios trabajando».
 *
 * Tiene razón, y por eso la existencia NO se parte por presentación. Un
 * saldo por envase exige que alguien teclee de qué caja física sacó cada
 * tableta; con tres personas despachando eso no pasa nunca y a la semana
 * el número es mentira.
 *
 * La lectura, en cambio, se calcula cada vez y no se puede desincronizar.
 */

function laLectura(): LecturaEnEnvases
{
    return app(LecturaEnEnvases::class);
}

/** Acetaminofén en TAB, con blíster de 10 y caja de 100. */
function unMedicamentoConEnvases(): Item
{
    $tab = Unidad::factory()->create(['codigo' => 'TAB']);

    $item = Item::factory()->medicamento()->create([
        'unidad_dispensacion_id' => $tab->id,
        'se_almacena'            => true,
    ]);

    ItemPresentacion::factory()->conContenido('10.0000', 'BLISTER X 10')->create(['item_id' => $item->id]);
    ItemPresentacion::factory()->conContenido('100.0000', 'CAJA X 100')->create(['item_id' => $item->id]);

    return $item->fresh() ?? $item;
}

it('lee 15 como un blister y cinco sueltas', function (): void {
    expect(laLectura()->de(unMedicamentoConEnvases(), Decimal::de('15')))
        ->toBe('1 BLISTER X 10 + 5 TAB');
})->note('Es el ejemplo exacto que planteó Mauricio.');

it('usa primero el envase más grande que quepa', function (): void {
    expect(laLectura()->de(unMedicamentoConEnvases(), Decimal::de('235')))
        ->toBe('2 CAJA X 100 + 3 BLISTER X 10 + 5 TAB');
})->note('De mayor a menor, que es lo que hace la mano: se abre el envase más grande que sirva y se sigue.');

it('no agrega el resto cuando la cantidad cierra exacta', function (): void {
    expect(laLectura()->de(unMedicamentoConEnvases(), Decimal::de('100')))
        ->toBe('1 CAJA X 100');
});

it('calla cuando no alcanza ni para el envase más chico', function (): void {
    expect(laLectura()->de(unMedicamentoConEnvases(), Decimal::de('7')))
        ->toBeNull();
})->note('«7 TAB» ya se lee solo. Repetirlo debajo del campo es ruido donde menos tiempo hay para leer.');

it('calla cuando el producto no tiene envases', function (): void {
    $item = Item::factory()->medicamento()->create(['se_almacena' => true]);

    expect(laLectura()->de($item, Decimal::de('15')))->toBeNull();
});

it('no dice nada de una cantidad en cero', function (): void {
    expect(laLectura()->de(unMedicamentoConEnvases(), Decimal::de('0')))->toBeNull();
});

it('ignora una presentación que no agrupa nada', function (): void {
    $item = unMedicamentoConEnvases();

    ItemPresentacion::factory()->conContenido('1.0000', 'TABLETA')->create(['item_id' => $item->id]);

    expect(laLectura()->de($item->fresh() ?? $item, Decimal::de('15')))
        ->toBe('1 BLISTER X 10 + 5 TAB');
})->note('Una presentación de una unidad no es un envase: es la unidad, y ya está escrita al final.');
