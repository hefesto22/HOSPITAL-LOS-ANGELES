<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\HonorarioPropuesto;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Tarifario;
use App\Services\PreciosDeLaFicha;
use App\Services\ProponedorDeHonorario;

/*
 * El precio del honorario al cargarlo en la cuenta (Mauricio, 6-nov-2026).
 *
 * «Lo que cobran al Militar y a PALIG ya está fijado, así que en esos no
 * se puede modificar; en particular sí se puede cambiar y poner el monto
 * a gusto.»
 *
 * ⚠️ Los ayudantes llevan nombre propio: son funciones globales y con
 * `--parallel` dos iguales en archivos distintos chocan (§9.M5).
 */

function honorarioParaLaCuenta(): Item
{
    return Item::factory()->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)->create();
}

function contadoParaElHonorario(): Convenio
{
    return Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();
}

/**
 * @param numeric-string $precio
 */
function listaDelHonorario(Item $item, string $precio, bool $deRelleno = false): void
{
    Tarifario::factory()->delItem($item)->a($precio)->create(
        $deRelleno ? ['motivo' => PreciosDeLaFicha::MARCA_PENDIENTE] : [],
    );
}

function propuestaDelHonorario(Item $item, Cuenta $cuenta, ?Medico $medico = null): HonorarioPropuesto
{
    return app(ProponedorDeHonorario::class)->para($item, $cuenta, $medico, now());
}

/**
 * @param array<string, mixed> $data
 */
function loQueViajaEnElCargo(Item $item, Cuenta $cuenta, array $data): ?Monto
{
    $pagina = app(CuentasAbiertas::class);
    $pagina->cuentaDelFormulario = $cuenta->id;

    /** @var Monto|null $resultado */
    $resultado = (new ReflectionMethod(CuentasAbiertas::class, 'honorarioAcordado'))
        ->invoke($pagina, $item, $cuenta, $data);

    return $resultado;
}

/*
|--------------------------------------------------------------------------
| Seguro: lo pactado no se cambia
|--------------------------------------------------------------------------
*/

it('🔴 en un seguro el honorario sale fijo con lo pactado, aunque el medico tenga precio general', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create(['nombre' => 'HOSPITAL MILITAR']);

    listaDelHonorario($item, '600.0000');
    Tarifario::factory()->delItem($item)->paraElConvenio($militar)->a('850.0000')->create();

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->create(['medico_id' => $medico->id, 'item_id' => $item->id, 'precio' => '500.0000']);

    $propuesto = propuestaDelHonorario($item, unaCuentaCon($militar), $medico);

    expect($propuesto->fijo)->toBeTrue()
        ->and($propuesto->valor())->toBe('850.00')
        ->and($propuesto->delMedico)->toBeFalse();
})->note('Decisión de Mauricio: el precio general del médico es para el particular; en el seguro manda lo pactado.');

it('en un seguro manda el precio del medico hecho para ESE seguro', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create();

    Tarifario::factory()->delItem($item)->paraElConvenio($militar)->a('850.0000')->create();

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->paraElPagador($militar)->create([
        'medico_id' => $medico->id,
        'item_id'   => $item->id,
        'precio'    => '900.0000',
    ]);

    $propuesto = propuestaDelHonorario($item, unaCuentaCon($militar), $medico);

    expect($propuesto->fijo)->toBeTrue()
        ->and($propuesto->valor())->toBe('900.00')
        ->and($propuesto->delMedico)->toBeTrue();
});

it('un seguro que no pacto el honorario deja escribirlo a mano y lo dice', function (): void {
    $item = honorarioParaLaCuenta();
    $palig = Convenio::factory()->create(['nombre' => 'PAN-AMERICAN LIFE']);

    listaDelHonorario($item, '10.0000', deRelleno: true);

    $propuesto = propuestaDelHonorario($item, unaCuentaCon($palig));

    expect($propuesto->fijo)->toBeFalse()
        ->and($propuesto->precio)->toBeNull()
        ->and($propuesto->explicacion)->toContain('PAN-AMERICAN LIFE no tiene este honorario pactado');
})->note('Decisión de Mauricio: no se traba el cobro.');

it('🔴 lo tecleado en un seguro con pactado no llega al cargo', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create();

    Tarifario::factory()->delItem($item)->paraElConvenio($militar)->a('850.0000')->create();

    expect(loQueViajaEnElCargo($item, unaCuentaCon($militar), ['precio_acordado' => '1']))->toBeNull();
})->note('Nulo: el motor cobra la fila del seguro. El campo va bloqueado, pero un bloqueo de pantalla se salta desde el navegador (§9.M30).');

it('el precio del medico para ese seguro viaja en el cargo aunque se teclee otro', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create();

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->paraElPagador($militar)->create([
        'medico_id' => $medico->id,
        'item_id'   => $item->id,
        'precio'    => '900.0000',
    ]);

    $monto = loQueViajaEnElCargo($item, unaCuentaCon($militar), ['precio_acordado' => '1', 'medico_id' => $medico->id]);

    expect($monto?->valor())->toBe('900.00');
});

/*
|--------------------------------------------------------------------------
| Particular: el precio se pone al cobrar
|--------------------------------------------------------------------------
*/

it('al particular se le propone lo que cobra el medico, y se puede cambiar', function (): void {
    $item = honorarioParaLaCuenta();
    listaDelHonorario($item, '10.0000', deRelleno: true);

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->create(['medico_id' => $medico->id, 'item_id' => $item->id, 'precio' => '500.0000']);

    $cuenta = unaCuentaCon(contadoParaElHonorario());
    $propuesto = propuestaDelHonorario($item, $cuenta, $medico);

    expect($propuesto->fijo)->toBeFalse()
        ->and($propuesto->valor())->toBe('500.00')
        ->and(loQueViajaEnElCargo($item, $cuenta, ['precio_acordado' => '650', 'medico_id' => $medico->id])?->valor())
        ->toBe('650.00');
});

it('🔴 al particular el L 10 de relleno no se propone', function (): void {
    $item = honorarioParaLaCuenta();
    listaDelHonorario($item, '10.0000', deRelleno: true);

    $propuesto = propuestaDelHonorario($item, unaCuentaCon(contadoParaElHonorario()));

    expect($propuesto->fijo)->toBeFalse()
        ->and($propuesto->precio)->toBeNull();
})->note('Los honorarios ya no aparecen en la base de la lista: nadie corregiría ese L 10, y propuesto en el campo se cobraría si nadie lo cambia.');

it('🔴 sin numero y sin nada que proponer, el honorario del particular no se carga', function (): void {
    $item = honorarioParaLaCuenta();
    listaDelHonorario($item, '10.0000', deRelleno: true);

    loQueViajaEnElCargo($item, unaCuentaCon(contadoParaElHonorario()), ['precio_acordado' => null]);
})->throws(CargoException::class, 'Escribí cuánto se cobra');

it('al particular con lista real se le propone la lista', function (): void {
    $item = honorarioParaLaCuenta();
    listaDelHonorario($item, '600.0000');

    $propuesto = propuestaDelHonorario($item, unaCuentaCon(contadoParaElHonorario()));

    expect($propuesto->fijo)->toBeFalse()
        ->and($propuesto->valor())->toBe('600.00')
        ->and($propuesto->delMedico)->toBeFalse();
});
