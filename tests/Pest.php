<?php

declare(strict_types=1);
use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Models\Abono;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\RegistradorDeCargo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Pest\Expectation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Asigna a qué TestCase apunta cada carpeta. Feature usa el TestCase
| que corre con RefreshDatabase + Laravel app boot. Unit usa el base
| (sin DB) — más rápido, ideal para Value Objects y lógica pura.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Feature/Filament');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Custom expectations específicas del dominio Olympo.
*/

/*
 * Se compara contra un STRING, no contra un float: `Monto` guarda el
 * valor en bcmath y expone el redondeado a dos decimales. Escribir
 * `toBeMonto('150.75')` en vez de `toBeMonto(150.75)` deja a la vista
 * que acá no hay punto flotante en ningún lado.
 */
expect()->extend('toBeMonto', function (string $valor, string $moneda = 'HNL'): Expectation {
    /** @var Monto $monto */
    $monto = $this->value;

    expect($monto)->toBeInstanceOf(Monto::class);
    expect($monto->valor())->toBe($valor);
    expect($monto->moneda)->toBe($moneda);

    return $this;
});

expect()->extend('toBeValidRTN', function (): Expectation {
    expect((string) $this->value)->toMatch('/^\d{14}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function actingAsAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'is_active' => true,
    ]);

    test()->actingAs($user);

    return $user;
}

/*
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ESTA FUNCIÓN VIVE ACÁ Y NO EN UN ARCHIVO DE TEST
 * ─────────────────────────────────────────────────────────────────────
 *
 * La usan `MotorDeCargosTest` y `DescuentoComercialTest`. Mientras Pest
 * corría en un proceso, definirla en uno de los dos alcanzaba: las
 * funciones sueltas comparten el espacio global.
 *
 * Con `pest --parallel` eso deja de ser cierto. Cada proceso carga SOLO
 * los archivos que le tocan, así que la función existe o no existe según
 * cómo cayó el reparto —y el reparto cambia cuando se agrega un archivo
 * o cuando el timing es otro—. El síntoma es
 * `Call to undefined function` en un test que nadie tocó.
 *
 * `tests/Pest.php` lo carga TODO proceso. Todo ayudante compartido entre
 * dos archivos de test va acá; el que usa un solo archivo se queda en él.
 */
function unaCuentaCon(Convenio $convenio, int $edad = 40): Cuenta
{
    $sede = Sede::factory()->create();

    $persona = Persona::factory()->create([
        'fecha_nacimiento' => now()->subYears($edad)->subDay()->toDateString(),
    ]);

    $expediente = Expediente::factory()->create([
        'sede_id'    => $sede->id,
        'persona_id' => $persona->id,
    ]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Hospitalizacion,
        convenio: $convenio,
        sede: $sede,
    );
}

/**
 * Un pagador que cubre la fracción que se le pida.
 *
 * `0.5000` es el 50 %. Es el seguro con el que se prueba todo lo que
 * tiene que ver con el reparto entre el paciente y la aseguradora.
 */
function unSeguroQueCubre(string $fraccion): Convenio
{
    return Convenio::factory()->create([
        'tipo'                  => TipoConvenio::AseguradoraPrivada,
        'base_descuento_legal'  => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
        'cobertura_fraccion'    => $fraccion,
        'cubre_por_defecto'     => true,
        'requiere_autorizacion' => false,
    ]);
}

/**
 * Un servicio exento con su fila de tarifario, listo para cargarse.
 *
 * Exento y sin descuento legal a propósito: quien prueba el reparto de
 * una cuenta no quiere el ISV ni el Art. 30 metidos en el número — esos
 * tienen sus propias pruebas.
 *
 * @param numeric-string $precio
 */
function unServicioDe(string $precio): Item
{
    $item = Item::factory()->create([
        'tipo'                      => TipoItem::Servicio,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

/**
 * Le carga a la cuenta un servicio de ese precio.
 *
 * @param numeric-string $precio
 */
function conUnServicioDe(Cuenta $cuenta, string $precio): void
{
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: unServicioDe($precio),
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ));
}

/**
 * Deja entrada esa plata sin pasar por caja: el abono real tiene sus
 * propias pruebas, y acá lo que se prueba es otra cosa.
 *
 * @param numeric-string $monto
 */
function abonarle(Cuenta $cuenta, string $monto): void
{
    $turno = TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id]);

    Abono::factory()->create([
        'sede_id'   => $cuenta->sede_id,
        'cuenta_id' => $cuenta->id,
        'turno_id'  => $turno->id,
        'total'     => $monto,
    ]);
}
