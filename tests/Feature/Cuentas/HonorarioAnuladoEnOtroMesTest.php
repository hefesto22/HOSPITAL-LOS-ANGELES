<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Filament\Pages\HonorariosPorMedico;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Medico;
use App\Services\AnuladorDeCargo;
use App\Services\RegistradorDeCargo;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Honorarios por médico con el neto del mes en negativo (12-oct-2026)
|--------------------------------------------------------------------------
|
| Anular en octubre un honorario cobrado en septiembre deja la reversa
| en octubre —`AnuladorDeCargo` le pone la fecha del día— y el original
| en septiembre. El neto de octubre de ese médico es negativo, y la
| pantalla reventaba con un 500: `Monto` no admite negativos.
*/

it('🔴 no revienta cuando el neto del mes da negativo y lo muestra con signo', function (): void {
    $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(9));

    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $medico = Medico::factory()->create();

    $cargo = app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: unServicioDe('630.0000'),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        medicoId: $medico->id,
    ))->first();

    expect($cargo)->toBeInstanceOf(Cargo::class);

    $this->travelBack();

    /** @var Cargo $cargo */
    app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGO AL MEDICO EQUIVOCADO');

    $pantalla = app(HonorariosPorMedico::class);
    $medicos = $pantalla->medicos();

    expect($pantalla->totalDelPeriodo())->toBe('− L. 630.00')
        ->and($medicos)->toHaveCount(1)
        ->and($medicos[0]['total'])->toBe('− L. 630.00')
        ->and($medicos[0]['negativo'])->toBeTrue();
});
