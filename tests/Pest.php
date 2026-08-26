<?php

declare(strict_types=1);
use App\Domain\Enums\TipoEncuentro;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
