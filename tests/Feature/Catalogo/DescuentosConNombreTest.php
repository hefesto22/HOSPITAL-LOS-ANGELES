<?php

declare(strict_types=1);

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Descuento;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Services\FijadorDeDescuento;
use App\Services\ResolutorDeDescuentoLegal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function elFijadorDeDescuentosConNombre(): FijadorDeDescuento
{
    return app(FijadorDeDescuento::class);
}

/**
 * ⚠️ El nombre se le pasa como lo TECLEARÍA alguien —«Tercera edad»— y
 * el sistema lo guarda canónico —«TERCERA EDAD»—. Las expectativas de
 * abajo van en mayúsculas por eso, y no por gusto: `FijadorDeDescuento`
 * busca el vigente POR NOMBRE, así que dos formas de escribirlo eran dos
 * descuentos con el mismo significado, los dos saliendo en facturas.
 */
function unDescuentoLlamado(
    string $nombre,
    string $porciento,
    AplicacionDeDescuento $aplicaA = AplicacionDeDescuento::Tercera,
    ?string $desde = null,
): Descuento {
    return elFijadorDeDescuentosConNombre()->fijar(
        nombre: $nombre,
        aplicaA: $aplicaA,
        porcentaje: Decimal::de($porciento)->entre('100'),
        desde: $desde === null ? now() : Carbon::parse($desde),
    );
}

function elResolutorDeDescuentos(): ResolutorDeDescuentoLegal
{
    return app(ResolutorDeDescuentoLegal::class);
}

function unItemSinDescuentoDeLey(): Item
{
    return Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);
}

/*
|--------------------------------------------------------------------------
| Crear uno, marcarlo, cobrarlo
|--------------------------------------------------------------------------
*/

it('se crea con nombre y porcentaje, y se marca en un item', function (): void {
    $descuento = unDescuentoLlamado('Tercera edad', '25');
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach($descuento);

    $resuelto = elResolutorDeDescuentos()->para($item, RangoEdad::Tercera, now());

    expect($resuelto->comoPorcentaje())->toBe('25 %')
        ->and($resuelto->nombre)->toBe('TERCERA EDAD')
        ->and($resuelto->aplica())->toBeTrue();
});

it('un item sin nada marcado se comporta como antes de que existiera el modulo', function (): void {
    $item = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario,
    ]);

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $resuelto = elResolutorDeDescuentos()->para($item, RangoEdad::Tercera, now());

    expect($resuelto->comoPorcentaje())->toBe('25 %')
        ->and($resuelto->nombre)->toBeNull();
});

it('el descuento de la cuarta edad le gana al de la tercera en el mismo item', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25'));
    $item->descuentos()->attach(
        unDescuentoLlamado('Cuarta edad', '40', AplicacionDeDescuento::Cuarta)
    );

    expect(elResolutorDeDescuentos()->para($item, RangoEdad::Cuarta, now())->comoPorcentaje())
        ->toBe('40 %');
});

it('🔴 un paciente de la cuarta edad sin descuento propio recibe el de la tercera, nunca cero', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25'));

    expect(elResolutorDeDescuentos()->para($item, RangoEdad::Cuarta, now())->comoPorcentaje())
        ->toBe('25 %');
});

it('a un paciente que no es adulto mayor no se le aplica nada', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25'));

    expect(elResolutorDeDescuentos()->para($item, RangoEdad::Normal, now())->aplica())
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 🔴 La ley es piso, nunca techo
|--------------------------------------------------------------------------
|
| Es lo que hace que este módulo se pueda usar sin miedo: marcar un
| descuento propio no puede dejar a un adulto mayor por debajo de lo que
| el Artículo 30 le garantiza, ni siquiera por un error de carga.
*/

it('🔴 un descuento del hospital menor que el de la ley NO le gana a la ley', function (): void {
    $item = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario,
    ]);

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $item->descuentos()->attach(unDescuentoLlamado('Promoción de aniversario', '10'));

    $resuelto = elResolutorDeDescuentos()->para($item, RangoEdad::Tercera, now());

    expect($resuelto->comoPorcentaje())->toBe('25 %')
        ->and($resuelto->nombre)->toBeNull();
});

it('un descuento del hospital mayor que el de la ley si le gana', function (): void {
    $item = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario,
    ]);

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad ampliada', '30'));

    expect(elResolutorDeDescuentos()->para($item, RangoEdad::Tercera, now())->comoPorcentaje())
        ->toBe('30 %');
});

/*
|--------------------------------------------------------------------------
| 🔴 El nombre es la identidad
|--------------------------------------------------------------------------
|
| El bug silencioso de este módulo sería resolver por el `id` que guarda
| el pivote: al cambiar el porcentaje, todos los ítems marcados se
| quedarían con el viejo, con la casilla marcada en pantalla y sin un
| solo error. Estas dos pruebas son las que lo impiden.
*/

it('🔴 cambiar el porcentaje le llega al item sin volver a marcarlo', function (): void {
    $item = unItemSinDescuentoDeLey();

    $enero = unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01');
    $item->descuentos()->attach($enero);

    unDescuentoLlamado('Tercera edad', '30', desde: '2026-07-01');

    /* Nadie volvió a tocar el ítem: el pivote sigue apuntando a enero. */
    expect($item->descuentos()->pluck('descuentos.id')->all())->toBe([$enero->id]);

    expect(elResolutorDeDescuentos()
        ->para($item, RangoEdad::Tercera, Carbon::parse('2026-09-15'))
        ->comoPorcentaje())->toBe('30 %');
});

it('🔴 la factura de marzo se reimprime con el porcentaje de marzo', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01'));
    unDescuentoLlamado('Tercera edad', '30', desde: '2026-07-01');

    expect(elResolutorDeDescuentos()
        ->para($item, RangoEdad::Tercera, Carbon::parse('2026-03-15'))
        ->comoPorcentaje())->toBe('25 %');
});

it('corregir el mismo dia reemplaza la fila en vez de agregar otra', function (): void {
    unDescuentoLlamado('Tercera edad', '20', desde: '2026-01-01');
    $corregido = unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01');

    expect(Descuento::query()->where('nombre', 'TERCERA EDAD')->count())->toBe(1)
        ->and($corregido->comoPorcentaje())->toBe('25 %');
});

it('una fecha posterior cierra la anterior el dia antes', function (): void {
    unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01');
    unDescuentoLlamado('Tercera edad', '30', desde: '2026-07-01');

    $viejo = Descuento::query()
        ->where('nombre', 'TERCERA EDAD')
        ->orderBy('vigencia_desde')
        ->firstOrFail();

    expect($viejo->vigencia_hasta?->toDateString())->toBe('2026-06-30');
});

/*
|--------------------------------------------------------------------------
| Lo que el fijador no deja hacer
|--------------------------------------------------------------------------
*/

it('🔴 no deja que un nombre cambie de destinatario', function (): void {
    unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01');

    expect(fn () => unDescuentoLlamado(
        'Tercera edad',
        '25',
        AplicacionDeDescuento::Manual,
        '2026-07-01',
    ))->toThrow(DescuentoNoFijableException::class);
});

it('no deja meter una vigencia antes de una que ya existe', function (): void {
    unDescuentoLlamado('Tercera edad', '25', desde: '2026-07-01');

    expect(fn () => unDescuentoLlamado('Tercera edad', '30', desde: '2026-01-01'))
        ->toThrow(DescuentoNoFijableException::class);
});

it('no acepta un porcentaje mayor que el cien por ciento', function (): void {
    expect(fn () => unDescuentoLlamado('Regalo', '250'))
        ->toThrow(DescuentoNoFijableException::class);
});

it('uno manual se guarda igual que uno de edad', function (): void {
    $manual = unDescuentoLlamado('Empleado del hospital', '20', AplicacionDeDescuento::Manual);

    expect($manual->comoPorcentaje())->toBe('20 %')
        ->and($manual->aplica_a)->toBe(AplicacionDeDescuento::Manual);
});

it('no acepta un nombre de dos letras', function (): void {
    expect(fn () => unDescuentoLlamado('AB', '25'))
        ->toThrow(DescuentoNoFijableException::class);
});

/*
|--------------------------------------------------------------------------
| Los manuales no se aplican solos
|--------------------------------------------------------------------------
*/

it('🔴 un descuento manual NO se le aplica solo a un adulto mayor', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(
        unDescuentoLlamado('Empleado del hospital', '50', AplicacionDeDescuento::Manual)
    );

    expect(elResolutorDeDescuentos()->para($item, RangoEdad::Tercera, now())->aplica())
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| El peor caso, que es de donde sale el precio de lista
|--------------------------------------------------------------------------
*/

it('🔴 el maximo del item incluye lo que se le marco, no solo la ley', function (): void {
    $item = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario,
    ]);

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $item->descuentos()->attach(
        unDescuentoLlamado('Cuarta edad', '40', AplicacionDeDescuento::Cuarta)
    );

    expect(elResolutorDeDescuentos()->maximoParaItem($item, now())->comoPorcentaje())
        ->toBe('40 %');
});

it('🔴 un descuento manual no infla el precio de todo el catalogo', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(
        unDescuentoLlamado('Empleado del hospital', '50', AplicacionDeDescuento::Manual)
    );

    expect(elResolutorDeDescuentos()->maximoParaItem($item, now())->aplica())
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Vigencia
|--------------------------------------------------------------------------
*/

it('uno que todavia no arranca no se aplica', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25', desde: '2026-07-01'));

    expect(elResolutorDeDescuentos()
        ->para($item, RangoEdad::Tercera, Carbon::parse('2026-03-15'))
        ->aplica())->toBeFalse();
});

it('dos con el mismo nombre no pueden estar vigentes el mismo dia', function (): void {
    unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01');

    Descuento::query()->create([
        'nombre'         => 'Tercera edad',
        'porcentaje'     => '0.3000',
        'aplica_a'       => AplicacionDeDescuento::Tercera->value,
        'nota'           => 'Fila metida a mano, sin pasar por el fijador.',
        'vigencia_desde' => '2026-03-01',
    ]);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Pantalla
|--------------------------------------------------------------------------
*/

it('la etiqueta del selector dice el nombre y el porcentaje', function (): void {
    $descuento = unDescuentoLlamado('Tercera edad', '25');

    expect($descuento->etiquetaCompleta())->toBe('TERCERA EDAD — 25 %');
});

it('cuenta los items por nombre y no por fila', function (): void {
    $item = unItemSinDescuentoDeLey();

    $item->descuentos()->attach(unDescuentoLlamado('Tercera edad', '25', desde: '2026-01-01'));

    $nuevo = unDescuentoLlamado('Tercera edad', '30', desde: '2026-07-01');

    /*
     * La fila nueva no tiene ni un pivote propio, y aun así le llega al
     * ítem. Contar por `id` diría cero y haría creer que el cambio no
     * tuvo efecto.
     */
    expect($nuevo->items()->count())->toBe(0)
        ->and($nuevo->cuantosItemsLoTienen())->toBe(1);
});
