<?php

declare(strict_types=1);

use App\Filament\Resources\Recepciones\Schemas\RecepcionForm;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE LLEGA SIN ENVASE NO ES UNA PRESENTACIÓN MÁS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Observación de Mauricio (3-sep-2026): «aparece la presentación de 100
 * ml, pero el otro no es una presentación y aparece ahí».
 *
 * El desplegable de la recepción arma un renglón por presentación más uno
 * para lo que llega suelto. El renglón suelto decía «· suelto, por fco»,
 * que ocupa el MISMO lugar donde va el nombre del envase:
 *
 *   ACETAMINOFEN JARABE · FRASCO 100 ML     ← una presentación
 *   ACETAMINOFEN JARABE · suelto, por fco   ← se leía como otra
 *
 * La opción se queda —es la única forma de cargar un saldo que no es un
 * envase entero— pero el texto dice qué es.
 */
function unJarabeConSuFrasco(): Item
{
    $frasco = Unidad::factory()->create(['codigo' => 'FRASCO', 'nombre' => 'FRASCO', 'simbolo' => 'fco']);

    $item = Item::factory()->medicamento()->create([
        'nombre'                 => 'ACETAMINOFEN JARABE',
        'se_almacena'            => true,
        'unidad_dispensacion_id' => $frasco->getKey(),
    ]);

    ItemPresentacion::factory()
        ->conContenido('1.0000', 'FRASCO 100 ML')
        ->create(['item_id' => $item->getKey(), 'unidad_id' => $frasco->getKey()]);

    return $item->fresh() ?? $item;
}

it('el renglon sin envase se distingue del de una presentacion', function (): void {
    $texto = RecepcionForm::asiLlegaSuelto(unJarabeConSuFrasco());

    expect($texto)->toContain('SIN ENVASE')
        ->and($texto)->not->toContain('suelto');
})->note('«SIN ENVASE» no se puede confundir con el nombre de una caja; «suelto, por fco» sí — ocupaba el mismo lugar del renglón donde va el nombre del envase.');

it('dice en que unidad se cuenta lo que llego suelto', function (): void {
    expect(RecepcionForm::asiLlegaSuelto(unJarabeConSuFrasco()))->toContain('FRASCO');
})->note('Sin la unidad, «SIN ENVASE» no dice si se teclean frascos o mililitros — y ahí entra cien veces lo que era uno.');

it('🔴 usa el codigo de la unidad y no el simbolo', function (): void {
    expect(RecepcionForm::asiLlegaSuelto(unJarabeConSuFrasco()))->not->toContain('fco');
})->note('🔴 «FRASCO» es lo que se ve en la etiqueta del estante y en el kardex. En un renglón que ya está en mayúsculas, un símbolo en minúscula se lee como otra cosa.');

it('la presentacion sigue mostrandose con su envase', function (): void {
    expect(unJarabeConSuFrasco()->presentaciones->sole()->envase())->toBe('FRASCO 100 ML');
})->note('Lo que cambia es el renglón de lo suelto; el de la presentación queda igual.');
