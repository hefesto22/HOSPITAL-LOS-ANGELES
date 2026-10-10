<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\TipoConvenio;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Convenio;

/**
 * LA TARJETA DE CUENTAS ABIERTAS, ARMADA CON LA MISMA CONSULTA QUE LA
 * PANTALLA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ EXISTE ESTE ARCHIVO (10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `CuentasAbiertas::cuentas()` carga cada relación con columnas
 * nombradas. Lo que la tarjeta lee y no está en esa lista llega nulo,
 * sin error ni aviso:
 *
 *   · faltaba `convenios.base_descuento_legal` y /cuentas devolvía 500
 *     («Call to a member function aplica() on null»);
 *   · faltaba `personas.precision_fecha_nacimiento` y toda fecha exacta
 *     salía como «(edad estimada)».
 *
 * Ninguna prueba lo vio porque ninguna pasaba por esa consulta. Estas
 * dos pasan por ella: si alguien lee una columna nueva en `resumenDe()`
 * y no la agrega a la consulta, se ponen rojas acá y no en el mostrador.
 */
it('🔴 con un pagador que no aplica el art 30 la sigla sale en gris y dice por que', function (): void {
    $aseguradora = Convenio::factory()
        ->de(TipoConvenio::AseguradoraPrivada)
        ->conBase(BaseDelDescuentoLegal::NoAplica)
        ->create(['requiere_autorizacion' => false]);

    unaCuentaCon($aseguradora, edad: 85);

    $pagina = app(CuentasAbiertas::class);
    $resumen = $pagina->resumenDe($pagina->cuentas()->firstOrFail());

    expect($resumen['rango'])->toBe('4R')
        ->and($resumen['rango_color'])->toBe('gray')
        ->and($resumen['rango_titulo'])->toContain('no aplica el descuento de ley')
        ->and($resumen['rango_titulo'])->not->toContain('edad estimada');
});

it('de contado la sigla sale en su color y sin aviso', function (): void {
    unaCuentaCon(Convenio::factory()->contado()->create(), edad: 65);

    $pagina = app(CuentasAbiertas::class);
    $resumen = $pagina->resumenDe($pagina->cuentas()->firstOrFail());

    expect($resumen['rango'])->toBe('3R')
        ->and($resumen['rango_color'])->toBe(RangoEdad::Tercera->color())
        ->and($resumen['rango_titulo'])->not->toContain('no aplica')
        ->and($resumen['rango_titulo'])->not->toContain('edad estimada');
});
