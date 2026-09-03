<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\Tarifario;
use Database\Seeders\Support\ListaPendiente;

/*
 * La regla del precio de lista, en pruebas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SE ROMPIÓ Y POR QUÉ ESTE ARCHIVO EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * El seed de producción reventó a mitad con un 23P01:
 *
 *     conflicting key value violates exclusion constraint
 *     "tarifarios_sin_traslape"
 *     Key (item_id, …, vigencia)=(34, …, [2026-08-01,)) conflicts with
 *     existing key (item_id, …, vigencia)=(34, …, [2026-09-01,))
 *
 * El ítem 34 es una radiografía que sale en DOS documentos: la lista de
 * rayos X del hospital, que le pone precio real desde el 2026-09-01, y la
 * propuesta de un seguro, que arranca el 2026-08-01 y le ponía el
 * centinela de L 10. Dos precios de lista abiertos del mismo ítem — que
 * es exactamente lo que el EXCLUDE existe para impedir, y hace bien.
 *
 * La causa no fue la base: fue que cada seeder abría su propia fila con
 * su propia vigencia, dando por sentado que el otro usaba la misma fecha.
 *
 * ⚠️ Lo que se prueba acá NO es «que no tire excepción». Es la regla de
 * negocio de la que la excepción era el síntoma:
 *
 *     Un ítem tiene como máximo UN precio de lista abierto, y el precio
 *     de verdad SIEMPRE le gana al centinela — corran los seeders en el
 *     orden que corran.
 *
 * Por eso los ayudantes usan `sole()`: si algún día vuelven a existir dos
 * filas abiertas, estos tests fallan por la razón correcta y no por un
 * mensaje de PostgreSQL.
 */

/** El precio de lista del ítem. Falla si hay dos: ese es el punto. */
function elPrecioDeListaDe(Item $item): Tarifario
{
    return Tarifario::query()
        ->where('item_id', $item->id)
        ->whereNull('convenio_id')
        ->whereNull('sede_id')
        ->sole();
}

it('no pisa con el centinela un precio de lista que ya existe', function (): void {
    $item = Item::factory()->create();

    ListaPendiente::precioReal($item, '400.0000', 'Lista de radiografías del hospital.', '2026-09-01');

    $puesto = ListaPendiente::poner($item, '2026-08-01');

    expect($puesto)->toBeFalse()
        ->and(elPrecioDeListaDe($item)->precio)->toBe('400.0000');
})->note('Es el 23P01 que reventó el seed. L 10 significa «no sabemos cuánto vale»; si ya se sabe, no aplica.');

it('el precio de verdad reemplaza al centinela en su misma fila', function (): void {
    $item = Item::factory()->create();

    ListaPendiente::poner($item, '2026-08-01');
    ListaPendiente::precioReal($item, '400.0000', 'Lista de radiografías del hospital.', '2026-09-01');

    $fila = elPrecioDeListaDe($item);

    expect($fila->precio)->toBe('400.0000')
        ->and($fila->vigencia_desde->toDateString())->toBe('2026-08-01');
})->note('La vigencia no se mueve: se escribe sobre la fila abierta en vez de abrir otra.');

it('da lo mismo el orden en que corran los seeders', function (): void {
    $primeroLaLista = Item::factory()->create();
    $primeroElSeguro = Item::factory()->create();

    ListaPendiente::precioReal($primeroLaLista, '400.0000', 'Lista del hospital.', '2026-09-01');
    ListaPendiente::poner($primeroLaLista, '2026-08-01');

    ListaPendiente::poner($primeroElSeguro, '2026-08-01');
    ListaPendiente::precioReal($primeroElSeguro, '400.0000', 'Lista del hospital.', '2026-09-01');

    expect(elPrecioDeListaDe($primeroLaLista)->precio)->toBe('400.0000')
        ->and(elPrecioDeListaDe($primeroElSeguro)->precio)->toBe('400.0000');
})->note('Un resultado que depende del orden del `db:seed` es un resultado que nadie puede reproducir.');

it('poner el centinela dos veces no abre dos vigencias', function (): void {
    $item = Item::factory()->create();

    ListaPendiente::poner($item, '2026-08-01');
    ListaPendiente::poner($item, '2026-09-01');

    expect(elPrecioDeListaDe($item)->precio)->toBe(ListaPendiente::PRECIO);
})->note('Volver a correr un seeder tiene que ser inofensivo, o deja de poder correrse.');

it('cuenta solo los que siguen esperando su precio de verdad', function (): void {
    ListaPendiente::poner(Item::factory()->create(), '2026-08-01');
    ListaPendiente::precioReal(Item::factory()->create(), '400.0000', 'Lista del hospital.', '2026-08-01');

    expect(ListaPendiente::cuantosFaltan())->toBe(1);
})->note('Es el número que decide si ya se le puede facturar a un paciente de contado.');
