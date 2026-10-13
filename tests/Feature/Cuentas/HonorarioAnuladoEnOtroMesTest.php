<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Filament\Pages\HonorariosPorMedico;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Medico;
use App\Models\User;
use App\Services\AnuladorDeCargo;
use App\Services\EmisorDeFactura;
use App\Services\RegistradorDeCargo;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Honorarios por médico: la anulación no mezcla meses (13-oct-2026)
|--------------------------------------------------------------------------
|
| Antes la pantalla sumaba cargos por su fecha, y un honorario de
| septiembre quitado en octubre salía como −L 630 en octubre. Mauricio:
| «solo honorarios del mes, nada de mezclar mes a mes; si se anula la
| factura, se le restan 1000».
|
| Ahora la fuente son las facturas emitidas: anular la factura saca sus
| honorarios del mes al que pertenecía, y nunca aparece un negativo.
|
| Los ayudantes (`cuentaConHonorarioDe`, `facturarLaCuentaDeHonorarios`)
| viven en `tests/Pest.php`: con `--parallel` cada proceso carga solo sus
| archivos, y una función definida en otro test no existiría acá.
*/

it('🔴 anular la factura le resta el honorario a su mes, sin negativos', function (): void {
    $medico = Medico::factory()->create();

    facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));
    $laQueSeAnula = facturarLaCuentaDeHonorarios(cuentaConHonorarioDe($medico, '1000.0000'));

    expect(app(HonorariosPorMedico::class)->totalDelPeriodo())->toBe('L. 2,000.00');

    app(EmisorDeFactura::class)->anular($laQueSeAnula, 'SE FACTURO AL PACIENTE EQUIVOCADO', User::factory()->create()->id);

    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->totalDelPeriodo())->toBe('L. 1,000.00')
        ->and($pantalla->resumen()['renglones'])->toBe(1)
        ->and($pantalla->medicos()[0]['total'])->toBe('L. 1,000.00');
});

it('🔴 cuenta en el mes en que se facturó, no en el que se cargó', function (): void {
    $medico = Medico::factory()->create();

    $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(9));
    $cuenta = cuentaConHonorarioDe($medico, '630.0000');
    $mesPasado = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
    $this->travelBack();

    facturarLaCuentaDeHonorarios($cuenta);

    $esteMes = app(HonorariosPorMedico::class);

    $elOtro = app(HonorariosPorMedico::class);
    $elOtro->desde = $mesPasado[0];
    $elOtro->hasta = $mesPasado[1];

    expect($esteMes->totalDelPeriodo())->toBe('L. 630.00')
        ->and($elOtro->totalDelPeriodo())->toBe('L. 0.00')
        ->and($elOtro->medicos())->toBe([]);
});

it('quitar un honorario de la cuenta antes de facturar no deja ningún negativo', function (): void {
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();
    $medico = Medico::factory()->create();

    $cuenta = unaCuentaCon($contado);

    $cargo = app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: unServicioDe('630.0000'),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        medicoId: $medico->id,
    ))->first();

    expect($cargo)->toBeInstanceOf(Cargo::class);

    /** @var Cargo $cargo */
    app(AnuladorDeCargo::class)->anular($cargo, 'SE CARGO AL MEDICO EQUIVOCADO');

    $pantalla = app(HonorariosPorMedico::class);

    expect($pantalla->totalDelPeriodo())->toBe('L. 0.00')
        ->and($pantalla->medicos())->toBe([]);
});
