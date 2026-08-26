<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\CuentaException;
use App\Domain\Exceptions\EncuentroException;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ayudantes — con nombres propios, que Pest carga todo en un solo proceso
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ El nombre lleva «DeEncuentros» a propósito.
 *
 * Pest carga TODOS los archivos de test en un solo proceso, así que las
 * funciones sueltas comparten un único espacio global. `elAbridor()` ya
 * existe en `ConteoFisicoTest` para `AbridorDeConteo`: repetirlo acá es
 * un fatal por redeclaración al correr la suite, y antes de eso hace que
 * PHPStan resuelva las llamadas contra el servicio equivocado.
 */
function elAbridorDeEncuentros(): AbridorDeEncuentro
{
    return app(AbridorDeEncuentro::class);
}

/**
 * @return array{0: Persona, 1: Expediente, 2: Sede}
 */
function unPacienteConExpediente(): array
{
    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create();
    $expediente = Expediente::factory()->create([
        'sede_id'    => $sede->id,
        'persona_id' => $persona->id,
    ]);

    return [$persona, $expediente, $sede];
}

/*
|--------------------------------------------------------------------------
| Abrir la cuenta
|--------------------------------------------------------------------------
*/

it('abre el encuentro y su cuenta en un solo acto', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    $cuenta = elAbridorDeEncuentros()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Hospitalizacion,
        convenio: $contado,
        sede: $sede,
        motivo: 'Politraumatismo por accidente de tránsito',
    );

    expect($cuenta)->toBeInstanceOf(Cuenta::class)
        ->and($cuenta->estado)->toBe(EstadoCuenta::Abierta)
        ->and($cuenta->numero)->not->toBeEmpty()
        ->and($cuenta->total)->toBe('0.00')
        ->and($cuenta->lineas)->toBe(0)
        ->and($cuenta->encuentro->estado)->toBe(EstadoEncuentro::Abierto)
        ->and($cuenta->encuentro->tipo)->toBe(TipoEncuentro::Hospitalizacion)
        ->and($cuenta->encuentro->numero)->not->toBe($cuenta->numero);
})->note('Un encuentro sin cuenta es un paciente que se está atendiendo y no tiene dónde acumular lo que consume. La mitad del caso de uso es peor que ninguna (§9.A13).');

it('el numero de encuentro y el de cuenta son correlativos distintos', function (): void {
    /*
     * Dos pacientes y no dos aperturas del mismo: desde ADR-0007 un
     * paciente no puede tener dos cuentas vivas. Lo que este test prueba
     * —que encuentro y cuenta llevan contadores separados— no necesitaba
     * que fuera la misma persona.
     */
    [$uno, $expedienteUno, $sede] = unPacienteConExpediente();
    [$otro, $expedienteOtro] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    $primera = elAbridorDeEncuentros()->abrir($uno, $expedienteUno, TipoEncuentro::Ambulatorio, $contado, $sede);
    $segunda = elAbridorDeEncuentros()->abrir($otro, $expedienteOtro, TipoEncuentro::Ambulatorio, $contado, $sede);

    expect($primera->numero)->not->toBe($segunda->numero)
        ->and($primera->encuentro->numero)->not->toBe($segunda->encuentro->numero);
});

it('no deja abrir dos hospitalizaciones vivas del mismo paciente', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Hospitalizacion, $contado, $sede);

    elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Hospitalizacion, $contado, $sede);
})->throws(EncuentroException::class, 'ya tiene un ingreso de hospitalización abierto');

it('la base tambien lo impide, sin pasar por el servicio', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();

    Encuentro::factory()->de($persona, $expediente)->internado()->create();

    DB::table('encuentros')->insert([
        'sede_id'       => $sede->id,
        'expediente_id' => $expediente->id,
        'persona_id'    => $persona->id,
        'numero'        => 'ENC-DUPLICADO-1',
        'tipo'          => TipoEncuentro::Hospitalizacion->value,
        'estado'        => EstadoEncuentro::Abierto->value,
        'abierto_en'    => now(),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
})->throws(QueryException::class)
    ->note('El servicio verifica y la base también. El servicio no es la única puerta: un import o un comando escriben directo algún día.');

it('🔴 el internado que baja a consulta no abre una segunda cuenta', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Hospitalizacion, $contado, $sede);

    elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);
})->throws(CuentaException::class)
    ->note('🔴 ESTE TEST DECÍA LO CONTRARIO, y el cambio es deliberado (ADR-0007). Su nota vieja era: «el internado que baja a consulta externa es un hecho normal; bloquearlo obligaría a inventar un encuentro falso». Lo primero sigue siendo cierto; lo segundo no: la consulta se carga en la cuenta que el paciente YA tiene abierta, y no hace falta inventar nada. Lo que sí era falso es lo que quedaba escondido detrás — dos cuentas vivas terminan en dos facturas, y una atención asegurada se cubre por UNA sola. El internado que baja a consulta no genera una cuenta aparte: genera una línea más en la suya.');

it('rechaza el expediente de otra persona', function (): void {
    [$persona, , $sede] = unPacienteConExpediente();
    $ajeno = Expediente::factory()->create(['sede_id' => $sede->id]);
    $contado = Convenio::factory()->contado()->create();

    elAbridorDeEncuentros()->abrir($persona, $ajeno, TipoEncuentro::Ambulatorio, $contado, $sede);
})->throws(EncuentroException::class, 'no es de este paciente');

it('rechaza un pagador que ya no esta vigente', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();

    $vencido = Convenio::factory()->create([
        'codigo'         => 'VENCIDO',
        'vigencia_desde' => now()->subYears(2)->toDateString(),
        'vigencia_hasta' => now()->subMonth()->toDateString(),
    ]);

    elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $vencido, $sede);
})->throws(CuentaException::class, 'no está vigente hoy');

/*
|--------------------------------------------------------------------------
| Lo que la cuenta tiene que dejar preparado
|--------------------------------------------------------------------------
*/

/**
 * Cierra una cuenta como quedaría después de que el paciente pagó.
 *
 * Hace falta desde ADR-0007: un paciente no puede tener dos cuentas
 * vivas, así que probar el reingreso exige que la anterior esté cerrada
 * — que además es lo que pasa de verdad diez días después.
 */
function yaPago(Cuenta $cuenta): void
{
    $cuenta->forceFill([
        'estado'     => EstadoCuenta::Cerrada,
        'cerrada_en' => now(),
    ])->saveQuietly();
}

it('enlaza el ingreso anterior cuando fue hace menos de 30 dias', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    /*
     * `Carbon::setTestNow()` y no `travelTo()`: esa función no existe en
     * el espacio global —es un método del TestCase— así que el análisis
     * estático no la encuentra y el test se cae en runtime.
     */
    $haceDiezDias = now()->subDays(10);

    Carbon::setTestNow($haceDiezDias);
    $anterior = elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);
    Carbon::setTestNow();

    yaPago($anterior);

    $nuevo = elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);

    expect($nuevo->encuentro->encuentro_anterior_id)->toBe($anterior->encuentro_id);
})->note('§9.K14: el reingreso a menos de 30 días es indicador de calidad y moneda de negociación. Reconstruirlo después es imposible si cada ingreso es una isla.');

it('no enlaza un ingreso de hace mas de 30 dias', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    Carbon::setTestNow(now()->subDays(90));
    $vieja = elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);
    Carbon::setTestNow();

    yaPago($vieja);

    $nuevo = elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);

    expect($nuevo->encuentro->encuentro_anterior_id)->toBeNull();
});

it('solo puede haber una cuenta abierta por encuentro', function (): void {
    [$persona, $expediente, $sede] = unPacienteConExpediente();
    $contado = Convenio::factory()->contado()->create();

    $cuenta = elAbridorDeEncuentros()->abrir($persona, $expediente, TipoEncuentro::Ambulatorio, $contado, $sede);

    DB::table('cuentas')->insert([
        'sede_id'      => $sede->id,
        'encuentro_id' => $cuenta->encuentro_id,
        'numero'       => 'CTA-DUPLICADA-1',
        'convenio_id'  => $contado->id,
        'estado'       => EstadoCuenta::Abierta->value,
        'abierta_en'   => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
})->throws(QueryException::class)
    ->note('Con dos cuentas abiertas, el cargo siguiente no sabría a cuál ir.');

it('el encuentro no se cierra sin decir como termino', function (): void {
    $encuentro = Encuentro::factory()->create();

    DB::table('encuentros')
        ->where('id', $encuentro->id)
        ->update([
            'estado'         => EstadoEncuentro::Cerrado->value,
            'alta_medica_en' => now(),
            'cerrado_en'     => now(),
        ]);
})->throws(QueryException::class)
    ->note('§9.K9: tipificar el egreso no es burocracia. La fuga y el alta voluntaria tienen consecuencias distintas, y la defunción tiene flujo propio.');

it('no se puede liquidar la cuenta de alguien a quien nadie dio de alta', function (): void {
    $encuentro = Encuentro::factory()->create();

    DB::table('encuentros')
        ->where('id', $encuentro->id)
        ->update(['alta_administrativa_en' => now()]);
})->throws(QueryException::class)
    ->note('§9.K8: los tres tiempos del egreso tienen un orden, y no es opinable.');
