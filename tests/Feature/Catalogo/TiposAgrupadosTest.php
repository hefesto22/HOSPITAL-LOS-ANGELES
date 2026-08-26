<?php

declare(strict_types=1);

use App\Domain\Enums\TipoItem;

/*
|--------------------------------------------------------------------------
| Los tipos se agrupan, no se fusionan
|--------------------------------------------------------------------------
|
| Diez tipos planos son diez decisiones; cuatro cajones son una. Pero
| fusionarlos de verdad borraría el código LOINC del laboratorio, el ATC
| del medicamento, el lote, el margen por tipo y el numeral del Art. 30.
|
| Estas pruebas son las que impiden que «simplificar el desplegable»
| termine borrando esas cinco cosas sin que nadie se dé cuenta.
*/

it('🔴 no se pierde ningun tipo al agrupar', function (): void {
    $agrupados = [];

    foreach (TipoItem::opcionesAgrupadas() as $tipos) {
        foreach (array_keys($tipos) as $valor) {
            $agrupados[] = $valor;
        }
    }

    sort($agrupados);

    $todos = array_map(static fn (TipoItem $t): string => $t->value, TipoItem::cases());
    sort($todos);

    expect($agrupados)->toBe($todos);
});

it('ningun tipo aparece en dos cajones', function (): void {
    $vistos = [];

    foreach (TipoItem::opcionesAgrupadas() as $tipos) {
        foreach (array_keys($tipos) as $valor) {
            expect($vistos)->not->toContain($valor);
            $vistos[] = $valor;
        }
    }
});

it('los cajones son los cuatro del hospital, mas otros', function (): void {
    expect(array_keys(TipoItem::opcionesAgrupadas()))->toBe([
        'Farmacia y bodega',
        'Honorarios',
        'Servicios',
        'Procedimientos y estudios',
        'Otros',
    ]);
});

it('cada cosa cae en el cajon que le corresponde', function (): void {
    expect(TipoItem::Medicamento->grupo())->toBe('Farmacia y bodega')
        ->and(TipoItem::Insumo->grupo())->toBe('Farmacia y bodega')
        ->and(TipoItem::Honorario->grupo())->toBe('Honorarios')
        ->and(TipoItem::Estancia->grupo())->toBe('Servicios')
        ->and(TipoItem::EstudioImagen->grupo())->toBe('Procedimientos y estudios')
        ->and(TipoItem::EstudioLaboratorio->grupo())->toBe('Procedimientos y estudios');
});

/*
|--------------------------------------------------------------------------
| Un honorario no se mide
|--------------------------------------------------------------------------
*/

it('🔴 un honorario no pregunta unidad de cobro', function (): void {
    expect(TipoItem::Honorario->usaUnidadDeCobro())->toBeFalse()
        ->and(TipoItem::Paquete->usaUnidadDeCobro())->toBeFalse();
});

it('una estancia si la pregunta: se cobra por dia o por hora', function (): void {
    expect(TipoItem::Estancia->usaUnidadDeCobro())->toBeTrue()
        ->and(TipoItem::Servicio->usaUnidadDeCobro())->toBeTrue()
        ->and(TipoItem::Procedimiento->usaUnidadDeCobro())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 🔴 Lo que se perdería al fusionar
|--------------------------------------------------------------------------
|
| Si algún día alguien junta laboratorio con imagen, o medicamento con
| insumo, esta prueba falla primero — y dice exactamente qué se lleva
| puesto.
*/

it('🔴 laboratorio e imagen no son lo mismo: solo uno lleva LOINC', function (): void {
    expect(TipoItem::EstudioLaboratorio->usaLoinc())->toBeTrue()
        ->and(TipoItem::EstudioImagen->usaLoinc())->toBeFalse();
});

it('🔴 medicamento e insumo no son lo mismo: solo uno lleva ATC y lote', function (): void {
    expect(TipoItem::Medicamento->usaAtc())->toBeTrue()
        ->and(TipoItem::Insumo->usaAtc())->toBeFalse()
        ->and(TipoItem::Medicamento->requiereLote())->toBeTrue();
});
