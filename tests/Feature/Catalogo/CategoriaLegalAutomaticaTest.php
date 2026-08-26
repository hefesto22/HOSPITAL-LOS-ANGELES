<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Services\ResolutorDeDescuentoLegal;

/*
|--------------------------------------------------------------------------
| El numeral del Art. 30 se deduce del tipo cuando nadie lo escribe
|--------------------------------------------------------------------------
|
| El formulario del catálogo dejó de preguntarlo: mostraba porcentajes de
| adulto mayor al lado de los descuentos del hospital y los dos parecían
| lo mismo. Pero la columna sigue siendo NOT NULL y de ella sale el
| descuento que se aplica SOLO, sin que nadie marque nada.
|
| Estas pruebas son las que impiden las dos formas de romperlo: que un
| alta nueva reviente contra la base, y que deducir la categoría pise una
| que alguien cargó a mano.
*/

function unItemNuevoDeTipo(TipoItem $tipo): Item
{
    /*
     * A propósito NO se pasa `categoria_legal_descuento`: es lo que la
     * pantalla nueva deja de mandar, y el punto de estas pruebas es que
     * eso no reviente contra una columna NOT NULL.
     */
    return Item::query()->create([
        'codigo'         => mb_strtoupper('AUTO-'.$tipo->value),
        'nombre'         => 'ITEM DE PRUEBA '.mb_strtoupper($tipo->value),
        'tipo'           => $tipo,
        'regimen_isv'    => RegimenIsv::Exento,
        'se_almacena'    => false,
        'vigencia_desde' => now()->subDay()->toDateString(),
    ]);
}

it('🔴 un item creado sin categoria legal no revienta: la toma del tipo', function (): void {
    $honorario = unItemNuevoDeTipo(TipoItem::Honorario);

    expect($honorario->categoria_legal_descuento)
        ->toBe(CategoriaLegalDeDescuento::ConsultaGeneral);
});

it('cada tipo cae en el numeral que le corresponde', function (TipoItem $tipo): void {
    expect(unItemNuevoDeTipo($tipo)->categoria_legal_descuento)
        ->toBe(CategoriaLegalDeDescuento::sugeridaPara($tipo));
})->with(function (): array {
    $casos = [];

    foreach (TipoItem::cases() as $tipo) {
        $casos[$tipo->value] = $tipo;
    }

    return $casos;
});

it('🔴 una categoria escrita a mano NO se pisa con la del tipo', function (): void {
    /*
     * Es el caso del honorario de un cardiólogo: el tipo dice
     * «Honorario» y la sugerencia sería consulta general al 25 %, pero es
     * consulta especializada y le toca el 30 %. Pisarlo acá le bajaría
     * cinco puntos al descuento de cada paciente mayor, en silencio.
     */
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Honorario,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ConsultaEspecializada,
    ]);

    expect($item->fresh()?->categoria_legal_descuento)
        ->toBe(CategoriaLegalDeDescuento::ConsultaEspecializada);
});

it('🔴 cambiarle el tipo a un item que ya existe no le recalcula la categoria', function (): void {
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Honorario,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ConsultaEspecializada,
    ]);

    $item->update(['tipo' => TipoItem::Servicio]);

    expect($item->fresh()?->categoria_legal_descuento)
        ->toBe(CategoriaLegalDeDescuento::ConsultaEspecializada);
});

it('el descuento de ley le sigue llegando a un item creado sin tocar nada', function (): void {
    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $honorario = unItemNuevoDeTipo(TipoItem::Honorario);

    $resuelto = app(ResolutorDeDescuentoLegal::class)
        ->para($honorario, RangoEdad::Tercera, now());

    expect($resuelto->comoPorcentaje())->toBe('25 %');
});

it('la politica de cargo por defecto es cobrable cuando el formulario no la manda', function (): void {
    /*
     * El campo se sacó de la pantalla porque hoy no cambia ninguna plata:
     * `generaCargoAlPaciente()` no lo consulta nadie todavía. La columna
     * queda con su default para el día que el hospital cobre paquetes
     * quirúrgicos y necesite decir qué va incluido y qué no.
     */
    /*
     * ⚠️ Se relee de la base a propósito. El default `cobrable` lo pone
     * Postgres, no Eloquent, así que el modelo recién creado lo tiene en
     * null hasta que se vuelve a cargar. Leerlo de memoria era lo que
     * hacía fallar esta prueba.
     */
    $item = unItemNuevoDeTipo(TipoItem::Servicio)->fresh();

    expect($item?->politica_cargo?->value)->toBe('cobrable');
});
