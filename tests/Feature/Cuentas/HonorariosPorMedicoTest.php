<?php

declare(strict_types=1);

use App\Filament\Pages\HonorariosPorMedico;
use App\Models\Medico;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Honorarios por médico: solo lo facturado en el período (13-oct-2026)
|--------------------------------------------------------------------------
|
| Mauricio: «si hizo 10 honorarios de 1000, se guardan esos 10; si se
| anula una factura en la que estaba un honorario, se le restan 1000.
| Solo honorarios del mes, nada de mezclar mes a mes».
|
| Las pruebas de la anulación están en `HonorarioAnuladoEnOtroMesTest`.
| Los ayudantes de los dos archivos viven en `tests/Pest.php`.
*/

it('🔴 un honorario cargado pero sin facturar todavía no cuenta', function (): void {
    $medico = Medico::factory()->create();
    $cuenta = cuentaConHonorarioDe($medico, '1000.0000');

    $antes = app(HonorariosPorMedico::class);

    expect($antes->medicos())->toBe([])
        ->and($antes->totalDelPeriodo())->toBe('L. 0.00');

    facturarLaCuentaDeHonorarios($cuenta);

    $despues = app(HonorariosPorMedico::class);

    expect($despues->totalDelPeriodo())->toBe('L. 1,000.00')
        ->and($despues->medicos())->toHaveCount(1)
        ->and($despues->medicos()[0]['total'])->toBe('L. 1,000.00');
});

it('suma los honorarios facturados del médico en el mes', function (): void {
    $medico = Medico::factory()->create();

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));
    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '500.0000'));

    $pantalla = app(HonorariosPorMedico::class);
    $resumen = $pantalla->resumen();
    $medicos = $pantalla->medicos();

    expect($resumen['total'])->toBe('L. 1,500.00')
        ->and($resumen['renglones'])->toBe(2)
        ->and($resumen['medicos'])->toBe(1)
        ->and($medicos[0]['total'])->toBe('L. 1,500.00')
        ->and($medicos[0]['renglones'])->toBe(2);
});

it('los atajos parten el mes el 15', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00'));

    $enLaPrimera = collect(app(HonorariosPorMedico::class)->periodos())->pluck('rango', 'clave')->all();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

    $enLaSegunda = collect(app(HonorariosPorMedico::class)->periodos())->pluck('rango', 'clave')->all();

    expect($enLaPrimera)->toBe([
        'esta_quincena'   => '01/10 – 15/10',
        'quincena_pasada' => '16/09 – 30/09',
        'este_mes'        => '01/10 – 31/10',
        'mes_pasado'      => '01/09 – 30/09',
    ])->and($enLaSegunda['esta_quincena'])->toBe('16/10 – 31/10')
        ->and($enLaSegunda['quincena_pasada'])->toBe('01/10 – 15/10');
});

it('sin fechas elegidas la pantalla abre en el mes en curso', function (): void {
    $activos = collect(app(HonorariosPorMedico::class)->periodos())->where('activo', true)->pluck('clave')->all();

    expect($activos)->toBe(['este_mes']);
});
