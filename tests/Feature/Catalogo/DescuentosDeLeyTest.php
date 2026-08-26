<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\Items\Support\PorcentajesPorEdad;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Services\FijadorDeDescuentoLegal;
use App\Services\ResolutorDeDescuentoLegal;
use Illuminate\Support\Carbon;

function elFijadorDeDescuentos(): FijadorDeDescuentoLegal
{
    return app(FijadorDeDescuentoLegal::class);
}

function unPorcentajeDeLey(
    CategoriaLegalDeDescuento $categoria,
    RangoEdad $rango,
    string $porciento,
    ?string $desde = null,
): DescuentoLegal {
    return elFijadorDeDescuentos()->fijar(
        categoria: $categoria,
        rango: $rango,
        porcentaje: Decimal::de($porciento)->entre('100'),
        fundamento: 'Art. 30, Decreto Legislativo 199-2006, cargado en la prueba.',
        desde: $desde === null ? now() : Carbon::parse($desde),
    );
}

/*
|--------------------------------------------------------------------------
| Cargar la cuarta edad es una fila, no un despliegue
|--------------------------------------------------------------------------
*/

it('🔴 la cuarta edad se carga sin tocar el esquema y le gana a la tercera', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::IntervencionQuirurgica, RangoEdad::Tercera, '30');
    unPorcentajeDeLey(CategoriaLegalDeDescuento::IntervencionQuirurgica, RangoEdad::Cuarta, '40');

    $resuelto = app(ResolutorDeDescuentoLegal::class)->paraCategoria(
        CategoriaLegalDeDescuento::IntervencionQuirurgica,
        RangoEdad::Cuarta,
        now(),
    );

    expect($resuelto->fraccion->redondeado(4))->toBe('0.4000');
})->note('🔴 El motor ya modelaba la cuarta edad desde el día uno: lo único que faltaba eran las filas y una pantalla para cargarlas. El día que el Congreso reforme otra vez, es un INSERT.');

it('🔴 un paciente de la cuarta edad sin su fila recibe la de la tercera, nunca cero', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '25');

    $resuelto = app(ResolutorDeDescuentoLegal::class)->paraCategoria(
        CategoriaLegalDeDescuento::ConsultaGeneral,
        RangoEdad::Cuarta,
        now(),
    );

    expect($resuelto->fraccion->redondeado(4))->toBe('0.2500');
})->note('🔴 Buscar solo el rango exacto y rendirse al no encontrarlo sería negarle el descuento a quien más derecho tiene, con la lógica pareciendo correcta. La ley no le puede dar menos a alguien por ser más viejo.');

/*
|--------------------------------------------------------------------------
| Corregir no es lo mismo que cambiar
|--------------------------------------------------------------------------
*/

it('corrige el porcentaje del mismo dia sin dejar una fila nueva', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '2.5');
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '25');

    $filas = DescuentoLegal::query()
        ->where('categoria_legal', CategoriaLegalDeDescuento::ConsultaGeneral->value)
        ->where('rango_edad', RangoEdad::Tercera->value)
        ->get();

    expect($filas)->toHaveCount(1)
        ->and($filas->firstOrFail()->fraccion()->redondeado(4))->toBe('0.2500')
        ->and($filas->firstOrFail()->vigencia_hasta)->toBeNull();
})->note('🔴 Sin esta rama, corregir un cero de menos recién cargado intentaría cerrar la fila de hoy con «ayer» — desde-hoy y hasta-ayer— y el CHECK de coherencia la rechazaría con un error crudo de PostgreSQL.');

it('una reforma de otro dia cierra la anterior y abre una nueva', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '25', '2026-01-01');
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '30', '2026-06-01');

    $filas = DescuentoLegal::query()
        ->where('categoria_legal', CategoriaLegalDeDescuento::ConsultaGeneral->value)
        ->where('rango_edad', RangoEdad::Tercera->value)
        ->orderBy('vigencia_desde')
        ->get();

    expect($filas)->toHaveCount(2)
        ->and($filas->first()?->fraccion()->redondeado(4))->toBe('0.2500')
        ->and($filas->first()?->vigencia_hasta?->toDateString())->toBe('2026-05-31')
        ->and($filas->last()?->vigencia_hasta)->toBeNull();
})->note('La pregunta que esta tabla contesta no es «cuánto se descuenta», es «cuánto se descontaba el día del servicio». Una factura de enero reimpresa en julio tiene que salir con el 25 %.');

it('el porcentaje de enero sigue siendo el de enero', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '25', '2026-01-01');
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '30', '2026-06-01');

    $resolutor = app(ResolutorDeDescuentoLegal::class);

    expect($resolutor->paraCategoria(
        CategoriaLegalDeDescuento::ConsultaGeneral,
        RangoEdad::Tercera,
        Carbon::parse('2026-03-15'),
    )->fraccion->redondeado(4))->toBe('0.2500');
})->note('Esa factura ya se cobró y ya se declaró. Reimprimirla con el porcentaje de hoy sería emitir un documento distinto del que el paciente firmó.');

/*
|--------------------------------------------------------------------------
| Lo que el fijador se niega a hacer
|--------------------------------------------------------------------------
*/

it('🔴 no deja meter una vigencia antes de una que ya existe', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ConsultaGeneral, RangoEdad::Tercera, '30', '2026-06-01');

    expect(fn (): DescuentoLegal => unPorcentajeDeLey(
        CategoriaLegalDeDescuento::ConsultaGeneral,
        RangoEdad::Tercera,
        '25',
        '2026-01-01',
    ))->toThrow(DescuentoNoFijableException::class);
})->note('🔴 La restricción de exclusión de la tabla lo atajaría igual, pero con un error de PostgreSQL que no menciona ni el hueco ni el traslape. Acá se dice qué pasó y qué hacer.');

it('no acepta un porcentaje mayor que el cien por ciento', function (): void {
    expect(fn (): DescuentoLegal => unPorcentajeDeLey(
        CategoriaLegalDeDescuento::ConsultaGeneral,
        RangoEdad::Tercera,
        '150',
    ))->toThrow(DescuentoNoFijableException::class);
})->note('Un descuento de más del 100 % es el hospital pagándole al paciente por atenderse. La base también lo rechaza; acá el mensaje se entiende.');

/*
|--------------------------------------------------------------------------
| El selector y el campo de la ficha del ítem
|--------------------------------------------------------------------------
*/

it('el selector ofrece las dos edades con su tramo', function (): void {
    expect(PorcentajesPorEdad::opcionesDeRango())->toBe([
        'tercera' => 'Tercera edad (60–79 años)',
        'cuarta'  => 'Cuarta edad (80 años en adelante)',
    ]);
})->note('Los tramos salen de `config(sihla.edad.rangos_por_defecto)`, no de la etiqueta: la ley ya cambió las edades una vez, y una pantalla con 60 y 80 escritos a mano termina diciendo algo distinto de lo que el sistema calcula.');

it('el resumen dice lo que rige hoy para las dos edades', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera, '25');

    expect(PorcentajesPorEdad::resumen(CategoriaLegalDeDescuento::ServicioHospitalario))
        ->toContain('Tercera edad (60–79 años): 25.00 %')
        ->toContain('Cuarta edad (80 años en adelante): sin cargar');
})->note('Es la respuesta a «¿cuánto se le descuenta a un adulto mayor en esto?», que es la pregunta que trae a alguien a esta pantalla.');

it('🔴 la cuarta edad sin fila propia se muestra VACIA, no heredada', function (): void {
    unPorcentajeDeLey(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera, '25');

    expect(PorcentajesPorEdad::vigente(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Cuarta))
        ->toBeNull();
})->note('🔴 El resolutor sube la escalera y devolvería el 25 % de la tercera. Si el campo se llenara con eso, aparecería cargado sin estarlo — y nadie cargaría nunca la cuarta edad porque la pantalla ya diría que está.');

it('escribir el porcentaje desde el item lo cambia para toda la categoria', function (): void {
    $uno = Item::factory()->create(['categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario]);
    Item::factory()->create(['categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario]);

    unPorcentajeDeLey(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera, '25');

    $escribio = PorcentajesPorEdad::guardar([
        PorcentajesPorEdad::CAMPO_RANGO      => RangoEdad::Tercera->value,
        PorcentajesPorEdad::CAMPO_PORCENTAJE => '30',
        PorcentajesPorEdad::CAMPO_FUNDAMENTO => 'Art. 30 numeral 5, Decreto Legislativo 199-2006.',
    ], $uno);

    expect($escribio)->toBeTrue()
        ->and(PorcentajesPorEdad::vigente(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera))
        ->toBe('30.00')
        ->and(PorcentajesPorEdad::cuantosItemsComparten(CategoriaLegalDeDescuento::ServicioHospitalario, $uno))
        ->toBe(1);
})->note('Es la ley: el descuento se fija por numeral del Art. 30, no producto por producto. Lo que no puede pasar es que se cambie sin que la pantalla lo haya dicho — por eso el aviso cuenta cuántos ítems comparten la categoría.');

it('🔴 sin elegir edad no se escribe nada', function (): void {
    $item = Item::factory()->create(['categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario]);

    unPorcentajeDeLey(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera, '25');

    $escribio = PorcentajesPorEdad::guardar([
        PorcentajesPorEdad::CAMPO_RANGO      => null,
        PorcentajesPorEdad::CAMPO_PORCENTAJE => '99',
    ], $item);

    expect($escribio)->toBeFalse()
        ->and(PorcentajesPorEdad::vigente(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera))
        ->toBe('25.00')
        ->and(DescuentoLegal::query()->count())->toBe(1);
})->note('🔴 El selector es el seguro de la pantalla: no tocarlo significa exactamente «no toqué ningún porcentaje». Sin él, guardar el ítem para corregir una tilde del nombre pasaría igual por un campo capaz de reescribir la ley.');

it('el mismo porcentaje no deja una fila nueva', function (): void {
    $item = Item::factory()->create(['categoria_legal_descuento' => CategoriaLegalDeDescuento::ServicioHospitalario]);

    unPorcentajeDeLey(CategoriaLegalDeDescuento::ServicioHospitalario, RangoEdad::Tercera, '25');

    $escribio = PorcentajesPorEdad::guardar([
        PorcentajesPorEdad::CAMPO_RANGO      => RangoEdad::Tercera->value,
        PorcentajesPorEdad::CAMPO_PORCENTAJE => '25',
    ], $item);

    expect($escribio)->toBeFalse()
        ->and(DescuentoLegal::query()->count())->toBe(1);
})->note('Abrir el ítem, mirar el porcentaje y guardar no es una reforma de la ley. Sin esta comparación el historial se llenaría de filas que nadie puso a propósito.');
