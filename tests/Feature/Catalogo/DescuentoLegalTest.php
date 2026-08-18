<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\TipoItem;

/*
|--------------------------------------------------------------------------
| GOLDEN TEST DE LA LEY
|--------------------------------------------------------------------------
|
| Estos números son el Artículo 30 del Decreto Legislativo 199-2006, y de
| ellos depende el precio de lista de TODO el catálogo:
|
|     precio_lista = costo × (1 + margen) / (1 − descuento_maximo)
|
| Cambiar uno acá cambia el precio de cada producto del hospital. Por eso
| este archivo se toca ÚNICAMENTE con la reforma en la mano, y en el mismo
| commit que actualiza `docs/dominio-inventario-y-precios.md` §4.4.
|
| Verificado el 18-ago-2026 contra el texto de la ley. El Decreto 45-2025
| NO reformó este artículo: reformó el 31, que es la Sección II de
| servicios básicos. La cuarta edad con 35 % vive ahí, no en salud.
|
*/

it('los porcentajes son los del Articulo 30', function (): void {
    $esperado = [
        'servicio_hospitalario'           => 0.25,
        'medicamento_material_quirurgico' => 0.25,
        'consulta_general'                => 0.25,
        'consulta_especializada'          => 0.30,
        'intervencion_quirurgica'         => 0.30,
        'odontologia_oftalmologia'        => 0.30,
        'radiologia_laboratorio'          => 0.30,
        'medicina_computarizada'          => 0.30,
        'sin_descuento_legal'             => 0.0,
    ];

    $real = collect(CategoriaLegalDeDescuento::cases())
        ->mapWithKeys(fn (CategoriaLegalDeDescuento $c): array => [
            $c->value => $c->porcentajeDeReferencia(),
        ])
        ->all();

    expect($real)->toBe($esperado);
})->note('De estos números sale el precio de lista de todo el catálogo. No se tocan sin la reforma en la mano.');

it('el descuento maximo en salud es 30 por ciento', function (): void {
    $maximo = collect(CategoriaLegalDeDescuento::cases())
        ->map(fn (CategoriaLegalDeDescuento $c): float => $c->porcentajeDeReferencia())
        ->max();

    expect($maximo)->toBe(0.30);
})->note('El 40 % que circuló en prensa es del Art. 31 —cable, energía, agua— y no aplica a servicios médicos. Asumirlo subiría 20 % el precio de lista de cada medicamento.');

it('cada categoria cita el numeral que la sustenta', function (): void {
    foreach (CategoriaLegalDeDescuento::cases() as $categoria) {
        if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            expect($categoria->numeral())->toBeNull();

            continue;
        }

        expect($categoria->numeral())->toStartWith('Art. 30');
    }
})->note('Cuando llega una denuncia a la línea 115 hay que poder decir por qué se aplicó ese porcentaje.');

it('solo medicamentos exige receta para el descuento', function (): void {
    $exigen = collect(CategoriaLegalDeDescuento::cases())
        ->filter(fn (CategoriaLegalDeDescuento $c): bool => $c->exigeReceta())
        ->map(fn (CategoriaLegalDeDescuento $c): string => $c->value)
        ->values()
        ->all();

    expect($exigen)->toBe(['medicamento_material_quirurgico']);
})->note('Art. 34: receta original firmada y sellada. En venta de mostrador hay que capturarla o el descuento no procede.');

it('propone una categoria para cada tipo de item', function (): void {
    foreach (TipoItem::cases() as $tipo) {
        expect(CategoriaLegalDeDescuento::sugeridaPara($tipo))
            ->toBeInstanceOf(CategoriaLegalDeDescuento::class);
    }
})->note('El match es exhaustivo: agregar un TipoItem sin decidir su categoría legal rompe acá y no en producción.');

/*
|--------------------------------------------------------------------------
| El precio de lista que sale de estos números
|--------------------------------------------------------------------------
*/

it('el descuento del medicamento fija su precio de lista', function (): void {
    $descuento = (string) CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico
        ->porcentajeDeReferencia();

    /*
     * §4.5: lista = costo × (1 + margen) / (1 − descuento_maximo).
     * El divisor es lo único que cambia si el porcentaje legal cambia,
     * así que la relación entre dos precios de lista es exactamente la
     * relación inversa entre sus divisores — sin redondeos de por medio.
     */
    $divisorCorrecto = bcsub('1', $descuento, 4);   // 0.7500
    $divisorErroneo = bcsub('1', '0.40', 4);        // 0.6000

    expect($divisorCorrecto)->toBe('0.7500')
        ->and(bcdiv($divisorErroneo, $divisorCorrecto, 4))->toBe('0.8000');
})->note('Con el 40 % que decía la versión anterior del documento, el precio de lista de cada medicamento habría sido 25 % más alto — o visto al revés: el correcto es 20 % más barato para todo el que no tiene descuento. El adulto mayor paga lo mismo en los dos casos, porque el piso de margen se toca igual.');

/*
|--------------------------------------------------------------------------
| Rangos de edad
|--------------------------------------------------------------------------
*/

it('en salud el umbral es 60 anios', function (): void {
    expect(RangoEdad::paraEdad(59))->toBe(RangoEdad::Normal)
        ->and(RangoEdad::paraEdad(60))->toBe(RangoEdad::Tercera)
        ->and(RangoEdad::paraEdad(60)->tieneDescuentoLegal())->toBeTrue();
});

it('la cuarta edad sigue existiendo aunque hoy no aplique a salud', function (): void {
    expect(RangoEdad::paraEdad(80))->toBe(RangoEdad::Cuarta);
})->note('Se modela desde ya: el día que el Congreso la extienda a servicios médicos tiene que ser una fila de configuración, no un despliegue.');

it('el rango se resuelve contra la fecha del servicio, no contra hoy', function (): void {
    $nacimiento = now()->parse('1966-06-15');

    expect(RangoEdad::paraPaciente($nacimiento, now()->parse('2026-06-14')))->toBe(RangoEdad::Normal)
        ->and(RangoEdad::paraPaciente($nacimiento, now()->parse('2026-06-15')))->toBe(RangoEdad::Tercera);
})->note('Un paciente que cumple 60 durante la hospitalización cambia de rango a mitad de la cuenta, y cada cargo lleva el rango del día que se generó.');
