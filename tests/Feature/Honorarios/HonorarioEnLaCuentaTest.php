<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\HonorarioPropuesto;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Especialidad;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Tarifario;
use App\Services\PreciosDeLaFicha;
use App\Services\ProponedorDeHonorario;
use App\Services\RegistradorDeCargo;
use Database\Seeders\EspecialidadesSeeder;
use Database\Seeders\HonorarioMedicoSeeder;
use Illuminate\Support\Str;

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

it('🔴 en un seguro se propone lo pactado aunque el medico tenga precio general, y con medico se puede cambiar', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create(['nombre' => 'HOSPITAL MILITAR']);

    listaDelHonorario($item, '600.0000');
    Tarifario::factory()->delItem($item)->paraElConvenio($militar)->a('850.0000')->create();

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->create(['medico_id' => $medico->id, 'item_id' => $item->id, 'precio' => '500.0000']);

    $cuenta = unaCuentaCon($militar);
    $conMedico = propuestaDelHonorario($item, $cuenta, $medico);
    $sinMedico = propuestaDelHonorario($item, $cuenta);

    expect($conMedico->fijo)->toBeFalse()
        ->and($conMedico->valor())->toBe('850.00')
        ->and($conMedico->delMedico)->toBeFalse()
        ->and($conMedico->explicacion)->toContain('Cambialo solo si este médico cobra distinto')
        ->and($sinMedico->fijo)->toBeTrue()
        ->and($sinMedico->valor())->toBe('850.00');
})->note('Mauricio, 6-nov-2026: «deja que se pueda editar el monto cuando se agregue qué médico, ya que con unos son especiales». El precio general del médico sigue siendo del particular.');

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

    expect($propuesto->fijo)->toBeFalse()
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

it('el precio del medico para ese seguro viaja en el cargo, y lo tecleado lo cambia', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create();

    $medico = Medico::factory()->create();
    HonorarioMedico::factory()->paraElPagador($militar)->create([
        'medico_id' => $medico->id,
        'item_id'   => $item->id,
        'precio'    => '900.0000',
    ]);

    $cuenta = unaCuentaCon($militar);

    expect(loQueViajaEnElCargo($item, $cuenta, ['precio_acordado' => null, 'medico_id' => $medico->id])?->valor())
        ->toBe('900.00')
        ->and(loQueViajaEnElCargo($item, $cuenta, ['precio_acordado' => '1100', 'medico_id' => $medico->id])?->valor())
        ->toBe('1100.00');
})->note('El tarifario no conoce la lista del doctor: el número tiene que ir en el cargo, cambiado o no.');

it('con medico, en un seguro lo tecleado viaja solo si cambia lo pactado', function (): void {
    $item = honorarioParaLaCuenta();
    $militar = Convenio::factory()->create();

    Tarifario::factory()->delItem($item)->paraElConvenio($militar)->a('1000.0000')->create();

    $medico = Medico::factory()->create();
    $cuenta = unaCuentaCon($militar);

    expect(loQueViajaEnElCargo($item, $cuenta, ['precio_acordado' => '1000', 'medico_id' => $medico->id]))->toBeNull()
        ->and(loQueViajaEnElCargo($item, $cuenta, ['precio_acordado' => '1500', 'medico_id' => $medico->id])?->valor())
        ->toBe('1500.00');
})->note('Nulo: el motor cobra la fila del seguro con su vigencia. 1,500: el doctor especial.');

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

/*
|--------------------------------------------------------------------------
| El doctor por su nombre, y su nombre en la factura
|--------------------------------------------------------------------------
*/

function laDoctoraAlejandra(): Medico
{
    return Medico::factory()->create([
        'nombre'          => 'ALEJANDRA BAQUEDANO FIGUEROA',
        'especialidad_id' => Especialidad::factory()->create(['nombre' => 'GINECOLOGIA Y OBSTETRICIA'])->id,
    ]);
}

it('el seeder deja un solo HONORARIO MEDICO aunque se corra dos veces', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);
    $this->seed(HonorarioMedicoSeeder::class);

    $items = Item::query()->where('codigo', 'HON-MED')->get();

    expect($items)->toHaveCount(1)
        ->and($items->firstOrFail()->tipo)->toBe(TipoItem::Honorario)
        ->and($items->firstOrFail()->categoria_legal_descuento)->toBe(CategoriaLegalDeDescuento::ConsultaEspecializada);
});

it('🔴 el buscador encuentra al medico por su nombre, sin importar tildes ni mayusculas', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);

    $kenia = Medico::factory()->create(['nombre' => 'KENIA DEL CARMEN ORDOÑEZ']);
    Medico::factory()->create(['nombre' => 'JOSE SANDOVAL BUESO']);

    /** @var array<int, string> $encontrados */
    $encontrados = (new ReflectionMethod(CuentasAbiertas::class, 'buscarMedicos'))
        ->invoke(app(CuentasAbiertas::class), 'ordonez');

    expect(array_keys($encontrados))->toBe([$kenia->id]);
});

it('sin el item HONORARIO MEDICO el buscador no ofrece medicos', function (): void {
    Medico::factory()->create(['nombre' => 'KENIA DEL CARMEN ORDOÑEZ']);

    $encontrados = (new ReflectionMethod(CuentasAbiertas::class, 'buscarMedicos'))
        ->invoke(app(CuentasAbiertas::class), 'kenia');

    expect($encontrados)->toBe([]);
})->note('Un médico tocado sin ítem al que ir dejaría una línea sin nada que cobrar.');

it('🔴 el HONORARIO MEDICO sin medico no se carga', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);

    $item = Item::query()->where('codigo', 'HON-MED')->firstOrFail();

    loQueViajaEnElCargo($item, unaCuentaCon(contadoParaElHonorario()), ['precio_acordado' => '500']);
})->throws(CargoException::class, 'Elegí de qué médico es el honorario');

it('🔴 en la factura el honorario dice el nombre del medico y su especialidad', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);

    $item = Item::query()->where('codigo', 'HON-MED')->firstOrFail();
    $alejandra = laDoctoraAlejandra();

    $cargos = app(RegistradorDeCargo::class)->registrar(unaCuentaCon(contadoParaElHonorario()), new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        precioAcordado: Monto::de('1500.00'),
        medicoId: $alejandra->id,
    ));

    expect($cargos->firstOrFail()->texto)->toBe('HONORARIO MÉDICO · ALEJANDRA BAQUEDANO FIGUEROA · GINECOLOGIA Y OBSTETRICIA');
})->note('Mauricio, 6-nov-2026: «en la factura sale honorario, nombre del doctor y especialidad». La factura imprime el texto congelado del cargo.');

/*
|--------------------------------------------------------------------------
| El precio por especialidad (Mauricio, 6-nov-2026)
|--------------------------------------------------------------------------
|
| «Cirugía general es de L 1,000, entonces todos los doctores de cirugía
| general»: la especialidad dice cuál es su honorario, y cada seguro ya
| tiene su precio para ese ítem.
*/

/**
 * @return array{0: Item, 1: Medico}
 */
function cirujanoConSuConsulta(): array
{
    $consulta = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada)
        ->create(['nombre' => 'CONSULTA EXTERNA CIRUGIA GENERAL']);

    $cirugia = Especialidad::factory()->create(['nombre' => 'CIRUGIA GENERAL', 'item_id' => $consulta->id]);

    $cirujano = Medico::factory()->create([
        'nombre'          => 'ROBERTO ABNER SANABRIA PEÑA',
        'especialidad_id' => $cirugia->id,
    ]);

    return [$consulta, $cirujano];
}

function honorarioQueCargaElMedico(Medico $medico): ?Item
{
    /** @var Item|null $item */
    $item = (new ReflectionMethod(CuentasAbiertas::class, 'honorarioParaElMedico'))
        ->invoke(app(CuentasAbiertas::class), $medico->id);

    return $item;
}

it('🔴 el medico tocado carga el honorario de su especialidad, con lo que el seguro pacto para ella', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);

    [$consulta, $cirujano] = cirujanoConSuConsulta();
    $militar = Convenio::factory()->create(['nombre' => 'HOSPITAL MILITAR']);
    Tarifario::factory()->delItem($consulta)->paraElConvenio($militar)->a('1000.0000')->create();

    $item = honorarioQueCargaElMedico($cirujano);
    $propuesto = propuestaDelHonorario($consulta, unaCuentaCon($militar), $cirujano);

    expect($item?->id)->toBe($consulta->id)
        ->and($propuesto->valor())->toBe('1000.00')
        ->and($propuesto->fijo)->toBeFalse();
});

it('sin honorario en su especialidad, el medico va al HONORARIO MEDICO', function (): void {
    $this->seed(HonorarioMedicoSeeder::class);

    $item = honorarioQueCargaElMedico(laDoctoraAlejandra());

    expect($item?->codigo)->toBe('HON-MED');
});

it('el seeder enlaza cada especialidad con su consulta externa y no pisa la que ya eligio el hospital', function (): void {
    $cirugia = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada)
        ->create(['nombre' => 'CONSULTA EXTERNA CIRUGIA GENERAL']);
    $ortopedia = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada)
        ->create(['nombre' => 'CONSULTA EXTERNA ORTOPEDIA']);
    $otra = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada)
        ->create(['nombre' => 'CONSULTA DE ORTOPEDIA PEDIATRICA']);

    $this->seed(EspecialidadesSeeder::class);

    Especialidad::query()->where('codigo', 'ORTOPE')->update(['item_id' => $otra->id]);

    $this->seed(EspecialidadesSeeder::class);

    $enlace = Especialidad::query()->whereIn('codigo', ['CIRGEN', 'ORTOPE', 'GINECO'])->pluck('item_id', 'codigo');

    expect($enlace['CIRGEN'])->toBe($cirugia->id)
        ->and($enlace['ORTOPE'])->toBe($otra->id)
        ->and($enlace['GINECO'])->toBeNull()
        ->and($ortopedia->exists)->toBeTrue();
})->note('Ginecología sin su consulta en el catálogo queda sin enlazar: se cobra como HONORARIO MÉDICO.');

it('en la factura, el honorario de la especialidad dice el medico sin repetir la especialidad', function (): void {
    [$consulta, $cirujano] = cirujanoConSuConsulta();

    $cargos = app(RegistradorDeCargo::class)->registrar(unaCuentaCon(contadoParaElHonorario()), new LineaDeCargo(
        item: $consulta,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        precioAcordado: Monto::de('1000.00'),
        medicoId: $cirujano->id,
    ));

    expect($cargos->firstOrFail()->texto)->toBe('CONSULTA EXTERNA CIRUGIA GENERAL · ROBERTO ABNER SANABRIA PEÑA');
});
