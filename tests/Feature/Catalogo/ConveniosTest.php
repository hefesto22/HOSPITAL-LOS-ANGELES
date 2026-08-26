<?php

declare(strict_types=1);

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Models\Convenio;
use Carbon\Carbon;
use Database\Seeders\ConveniosSeeder;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| El pagador que siempre existe
|--------------------------------------------------------------------------
*/

it('el seeder deja CONTADO listo y con el descuento ya resuelto', function (): void {
    (new ConveniosSeeder)->run();

    $contado = Convenio::query()->alContado()->sole();

    expect($contado->tipo)->toBe(TipoConvenio::Contado)
        ->and($contado->base_descuento_legal)->toBe(BaseDelDescuentoLegal::SobreLoQuePagaElPaciente)
        ->and($contado->aplicaDescuentoLegal())->toBeTrue()
        ->and($contado->dias_credito)->toBeNull();
})->note('Es el único convenio que se siembra: cuando paga el paciente, lo que paga ES el total facturado y las tres lecturas del Art. 30 coinciden. En los demás la ley no resuelve, así que se cargan a mano.');

it('el seeder se puede correr dos veces sin duplicar', function (): void {
    (new ConveniosSeeder)->run();
    (new ConveniosSeeder)->run();

    expect(Convenio::query()->alContado()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Lo que la base no deja pasar
|--------------------------------------------------------------------------
*/

it('guarda el codigo y el nombre en mayusculas venga de donde venga', function (): void {
    $convenio = Convenio::query()->create([
        'codigo'               => 'seg-001',
        'nombre'               => '  seguros del pais  ',
        'tipo'                 => TipoConvenio::AseguradoraPrivada,
        'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
        'fundamento_descuento' => 'El descuento cae sobre el deducible que paga el asegurado.',
        'vigencia_desde'       => '2026-01-01',
    ]);

    expect($convenio->codigo)->toBe('SEG-001')
        ->and($convenio->nombre)->toBe('SEGUROS DEL PAIS');
})->note('La canonicalización vive en el modelo y no solo en el formulario: un import de padrón o un comando escriben directo, y ahí el formulario no existe. La base lo vuelve a exigir con un CHECK.');

it('no deja dos convenios vivos con el mismo codigo', function (): void {
    Convenio::factory()->create(['codigo' => 'IHSS']);
    Convenio::factory()->create(['codigo' => 'IHSS']);
})->throws(QueryException::class);

it('libera el codigo cuando el convenio se da de baja', function (): void {
    $viejo = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);
    $viejo->delete();

    $nuevo = Convenio::factory()->create(['codigo' => 'ATLANTIDA']);

    expect($nuevo->exists)->toBeTrue()
        ->and(Convenio::query()->where('codigo', 'ATLANTIDA')->count())->toBe(1);
})->note('El índice único es PARCIAL, con `where deleted_at is null`. Si mañana se vuelve a firmar con la misma aseguradora, el código está libre; sin eso quedaría quemado para siempre.');

it('no deja fiarle al contado', function (): void {
    Convenio::factory()->contado()->create(['dias_credito' => 30]);
})->throws(QueryException::class)
    ->note('Treinta días de crédito al que paga en caja es una contradicción en los términos. El formulario limpia el campo, y la base lo rechaza igual por si la escritura no vino del formulario.');

it('no acepta una decision legal sin fundamento', function (): void {
    Convenio::factory()->create(['fundamento_descuento' => 'n/a']);
})->throws(QueryException::class)
    ->note('Un convenio con la lectura del Art. 30 elegida y sin explicación es exactamente el papel que nadie se anima a contradecir dos años después.');

it('no acepta un RTN que no tiene catorce digitos', function (): void {
    Convenio::factory()->create(['rtn' => '0801998501234']);
})->throws(QueryException::class);

it('no acepta un codigo en minusculas escrito directo en la base', function (): void {
    Convenio::query()->insert([
        'codigo'               => 'ihss',
        'nombre'               => 'INSTITUTO',
        'tipo'                 => TipoConvenio::SeguridadSocial->value,
        'base_descuento_legal' => BaseDelDescuentoLegal::NoAplica->value,
        'fundamento_descuento' => 'Regimen publico con su propio esquema de beneficios.',
        'vigencia_desde'       => '2026-01-01',
    ]);
})->throws(QueryException::class)
    ->note('`insert()` esquiva los eventos del modelo, así que el trait de mayúsculas no corre. Ese es justamente el hueco que tapa el CHECK.');

/*
|--------------------------------------------------------------------------
| Vigencia y lectura del Art. 30
|--------------------------------------------------------------------------
*/

it('respeta la fecha del servicio y no el dia de hoy', function (): void {
    Convenio::factory()->vigenteEntre('2026-01-01', '2026-06-30')->create(['codigo' => 'VIEJO']);
    Convenio::factory()->vigenteEntre('2026-07-01')->create(['codigo' => 'NUEVO']);

    $enMarzo = Convenio::query()->vigentesEn(Carbon::parse('2026-03-15'))->pluck('codigo')->all();
    $enAgosto = Convenio::query()->vigentesEn(Carbon::parse('2026-08-19'))->pluck('codigo')->all();

    expect($enMarzo)->toBe(['VIEJO'])
        ->and($enAgosto)->toBe(['NUEVO']);
})->note('Reimprimir la factura de un ingreso de marzo tiene que dar el pagador de marzo, no el que se dio de alta en septiembre.');

it('responde si aplica el descuento segun lo que declaro el convenio', function (): void {
    $conDescuento = Convenio::factory()
        ->conBase(BaseDelDescuentoLegal::SobreElTotalFacturado)
        ->create(['codigo' => 'CON-DTO']);

    $sinDescuento = Convenio::factory()
        ->conBase(BaseDelDescuentoLegal::NoAplica)
        ->create(['codigo' => 'SIN-DTO']);

    expect($conDescuento->aplicaDescuentoLegal())->toBeTrue()
        ->and($sinDescuento->aplicaDescuentoLegal())->toBeFalse();
})->note('El sistema no interpreta la ley: repite la lectura que alguien declaró al dar de alta el convenio, con su fundamento al lado.');

it('solo el contado no admite credito', function (): void {
    expect(TipoConvenio::Contado->admiteCredito())->toBeFalse()
        ->and(TipoConvenio::AseguradoraPrivada->admiteCredito())->toBeTrue()
        ->and(TipoConvenio::SeguridadSocial->admiteCredito())->toBeTrue()
        ->and(TipoConvenio::Institucional->admiteCredito())->toBeTrue();
});

it('las tres lecturas del articulo tienen explicacion escrita', function (): void {
    foreach (BaseDelDescuentoLegal::cases() as $base) {
        expect(mb_strlen($base->explicacion()))->toBeGreaterThan(60)
            ->and($base->etiqueta())->not->toBeEmpty();
    }
})->note('Si alguien agrega una cuarta lectura sin explicarla, este test falla. La pantalla muestra la explicación al lado de cada opción: sin ella, la decisión se toma a ciegas.');

/*
|--------------------------------------------------------------------------
| 🔴 El seguro externo se anota, no se le cobra
|--------------------------------------------------------------------------
*/

it('🔴 el seguro de reembolso no paga: paga el paciente', function (): void {
    expect(TipoConvenio::Reembolso->pagaUnTercero())->toBeFalse()
        ->and(TipoConvenio::Reembolso->admiteCredito())->toBeFalse();
})->note('🔴 «¿El hospital le factura a la aseguradora?» es la pregunta que separa un convenio de un seguro externo. Acá la respuesta es no: el paciente paga en caja al precio de lista y reclama él. Tratarlo como pagador dejaría una cuenta por cobrar contra una aseguradora que nunca recibió factura y no sabe que existe.');

it('🔴 la base impide fiarle credito a un seguro de reembolso', function (): void {
    Convenio::factory()->reembolso()->create(['dias_credito' => 30]);
})->throws(QueryException::class)
    ->note('🔴 El CHECK decía «tipo <> contado». Dejarlo atado al nombre del tipo viejo permitía darle treinta días de crédito a alguien a quien jamás se le va a mandar una factura.');

it('🔴 la base impide que un seguro de reembolso cubra algo', function (): void {
    /*
     * `->reembolso()` deja los días de crédito en nulo a propósito: sin
     * eso, la fila violaría PRIMERO el CHECK del crédito y la prueba
     * pasaría por el motivo equivocado.
     */
    Convenio::factory()->reembolso()->create([
        'cobertura_fraccion' => '0.8000',
        'cubre_por_defecto'  => true,
    ]);
})->throws(QueryException::class)
    ->note('🔴 Si cubriera, la cuenta repartiría entre paciente y aseguradora — y la aseguradora no está en esta conversación. Todo le toca al paciente, que es quien después reclama.');

it('un seguro de reembolso se puede dar de alta con sus datos', function (): void {
    $palic = Convenio::factory()->reembolso()->create();

    expect($palic->tipo)->toBe(TipoConvenio::Reembolso)
        ->and($palic->tipo->pagaUnTercero())->toBeFalse();
})->note('Es para lo que existe: que la aseguradora se dé de alta UNA vez con su nombre y su RTN, en vez de escribirse de veinte formas distintas en un campo libre, y que salga impresa junto a la póliza para que el paciente pueda reclamar.');
