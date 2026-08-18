<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoFusion;
use App\Domain\Exceptions\FusionInvalidaException;
use App\Models\FusionDePersona;
use App\Models\Persona;
use App\Models\PersonaVersion;
use App\Models\User;
use App\Services\FusionadorDePersonas;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function fusionador(): FusionadorDePersonas
{
    return app(FusionadorDePersonas::class);
}

function comoUsuario(string $nombre): User
{
    /** @var User $usuario */
    $usuario = User::factory()->create(['name' => $nombre, 'is_active' => true]);

    test()->actingAs($usuario);

    return $usuario;
}

const MOTIVO = 'Mismo DNI y misma fecha de nacimiento; el registro de ayer se creo por error.';

/*
|--------------------------------------------------------------------------
| Proponer no une nada
|--------------------------------------------------------------------------
*/

it('proponer deja a las dos personas separadas', function (): void {
    comoUsuario('Admisión');

    $duplicada = Persona::factory()->create();
    $sobreviviente = Persona::factory()->create();

    $fusion = fusionador()->proponer($duplicada, $sobreviviente, MOTIVO);

    expect($fusion->estado)->toBe(EstadoFusion::Propuesta)
        ->and($duplicada->fresh()?->merged_into)->toBeNull()
        ->and(Persona::query()->activas()->count())->toBe(2);
})->note('Mientras espera aprobación NO ha pasado nada: los dos pacientes siguen atendiéndose por su cuenta.');

it('no deja proponer una persona consigo misma', function (): void {
    comoUsuario('Admisión');

    $persona = Persona::factory()->create();

    fusionador()->proponer($persona, $persona, MOTIVO);
})->throws(FusionInvalidaException::class);

it('no deja proponer sobre una persona ya fusionada', function (): void {
    $primera = comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $sobreviviente = Persona::factory()->create();

    $fusion = fusionador()->proponer($duplicada, $sobreviviente, MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);

    test()->actingAs($primera);
    fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);
})->throws(FusionInvalidaException::class);

it('no deja apuntar a una sobreviviente que ya fue fusionada', function (): void {
    $primera = comoUsuario('Admisión');
    $intermedia = Persona::factory()->create();
    $raiz = Persona::factory()->create();

    $fusion = fusionador()->proponer($intermedia, $raiz, MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);

    test()->actingAs($primera);
    fusionador()->proponer(Persona::factory()->create(), $intermedia, MOTIVO);
})->throws(FusionInvalidaException::class)
    ->note('Se rechaza y se dice cuál es la raíz, en vez de redirigir en silencio: quien fusiona tiene que ver contra quién lo hace.');

it('no deja dos propuestas abiertas sobre el mismo paciente', function (): void {
    comoUsuario('Admisión');

    $duplicada = Persona::factory()->create();

    fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);
    fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);
})->throws(FusionInvalidaException::class)
    ->note('Quien apruebe la segunda no sabría que existía la primera.');

/*
|--------------------------------------------------------------------------
| El control de cuatro ojos
|--------------------------------------------------------------------------
*/

it('quien propone no puede aprobar', function (): void {
    comoUsuario('Admisión');

    $fusion = fusionador()->proponer(
        Persona::factory()->create(),
        Persona::factory()->create(),
        MOTIVO,
    );

    fusionador()->aprobar($fusion);
})->throws(FusionInvalidaException::class)
    ->note('Fusionar mal es peor que no fusionar: dos pacientes unidos comparten alergias y medicación.');

it('la base tambien impide que apruebe quien propuso', function (): void {
    $usuario = comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();

    DB::table('fusiones_de_persona')->insert([
        'persona_duplicada_id'     => $duplicada->getKey(),
        'persona_sobreviviente_id' => Persona::factory()->create()->getKey(),
        'estado'                   => 'aplicada',
        'motivo'                   => MOTIVO,
        'propuesta_por'            => $usuario->getKey(),
        'propuesta_en'             => now(),
        'resuelta_por'             => $usuario->getKey(),
        'resuelta_en'              => now(),
    ]);
})->throws(QueryException::class)
    ->note('El control de cuatro ojos no vive solo en el servicio: un seeder o un comando de migración de datos se lo saltarían.');

it('otra persona si puede aprobar', function (): void {
    comoUsuario('Admisión');

    $duplicada = Persona::factory()->create();
    $sobreviviente = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, $sobreviviente, MOTIVO);

    $aprobador = comoUsuario('Dirección');
    $aplicada = fusionador()->aprobar($fusion, 'Verificado contra el RNP.');

    expect($aplicada->estado)->toBe(EstadoFusion::Aplicada)
        ->and($aplicada->resuelta_por)->toBe($aprobador->getKey())
        ->and($duplicada->fresh()?->merged_into)->toBe($sobreviviente->getKey());
});

/*
|--------------------------------------------------------------------------
| Aplicar y deshacer
|--------------------------------------------------------------------------
*/

it('la fusion no borra ni mueve filas', function (): void {
    comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);

    expect(Persona::query()->withTrashed()->count())->toBe(2)
        ->and(Persona::query()->activas()->count())->toBe(1)
        ->and($duplicada->fresh()?->deleted_at)->toBeNull();
})->note('Unir es escribir un puntero. Mover las filas hijas de una persona a la otra sería destructivo y no tendría vuelta atrás.');

it('deja versionado el momento de la fusion', function (): void {
    comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);

    $version = PersonaVersion::query()->where('persona_id', $duplicada->getKey())->latest('version')->first();

    expect($version?->motivo)->toStartWith('Fusionada en')
        ->and($version?->datos['merged_into'])->toBe($duplicada->fresh()?->merged_into);
})->note('La versión guarda el estado CON el puntero puesto: comparándola con la anterior se sabe exactamente qué cambió la fusión.');

it('deshacer separa a las dos personas', function (): void {
    comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);
    $deshecha = fusionador()->deshacer($fusion, 'Se confirmo con el RNP que son dos personas distintas.');

    expect($deshecha->estado)->toBe(EstadoFusion::Deshecha)
        ->and($duplicada->fresh()?->merged_into)->toBeNull()
        ->and($duplicada->fresh()?->merged_at)->toBeNull()
        ->and(Persona::query()->activas()->count())->toBe(2);
})->note('§9.D4: la fusión siempre es reversible. Deshacer es borrar el puntero.');

it('quien propuso si puede deshacer', function (): void {
    $proponente = comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);

    test()->actingAs($proponente);
    fusionador()->deshacer($fusion, 'Me equivoque de paciente al proponerla.');

    expect($fusion->fresh()?->estado)->toBe(EstadoFusion::Deshecha);
})->note('Deshacer es una corrección, no una autorización: trabarla obligaría a conseguir a otra persona para arreglar un error que ya está haciendo daño.');

it('no se puede deshacer algo que no se aplico', function (): void {
    comoUsuario('Admisión');
    $fusion = fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->deshacer($fusion, 'Motivo suficientemente largo para el check.');
})->throws(FusionInvalidaException::class);

it('no se resuelve dos veces', function (): void {
    comoUsuario('Admisión');
    $fusion = fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->aprobar($fusion);
    fusionador()->aprobar($fusion);
})->throws(FusionInvalidaException::class);

it('rechazar no une nada', function (): void {
    comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    $rechazada = fusionador()->rechazar($fusion, 'Son dos hermanos con el mismo nombre.');

    expect($rechazada->estado)->toBe(EstadoFusion::Rechazada)
        ->and($duplicada->fresh()?->merged_into)->toBeNull();
});

it('despues de rechazar se puede proponer de nuevo', function (): void {
    comoUsuario('Admisión');
    $duplicada = Persona::factory()->create();
    $fusion = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->rechazar($fusion, 'Son dos hermanos con el mismo nombre.');

    comoUsuario('Admisión 2');
    $segunda = fusionador()->proponer($duplicada, Persona::factory()->create(), MOTIVO);

    expect($segunda->estado)->toBe(EstadoFusion::Propuesta);
})->note('El índice único solo cubre las propuestas abiertas: una rechazada no bloquea para siempre.');

/*
|--------------------------------------------------------------------------
| Trazabilidad
|--------------------------------------------------------------------------
*/

it('la base exige un motivo que sirva', function (): void {
    comoUsuario('Admisión');

    fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), 'dup');
})->throws(QueryException::class)
    ->note('"Duplicado" no es un motivo: lo que sirve después es "mismo DNI, misma fecha de nacimiento, el de 2024 se creó por error".');

it('la bandeja muestra solo lo que espera decision', function (): void {
    comoUsuario('Admisión');
    $pendiente = fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), MOTIVO);
    $otra = fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), MOTIVO);

    comoUsuario('Dirección');
    fusionador()->rechazar($otra, 'No son la misma persona.');

    expect(FusionDePersona::query()->pendientes()->pluck('id')->all())->toBe([$pendiente->getKey()]);
});

it('sabe quien puede resolverla', function (): void {
    $proponente = comoUsuario('Admisión');
    $fusion = fusionador()->proponer(Persona::factory()->create(), Persona::factory()->create(), MOTIVO);

    $otro = comoUsuario('Dirección');

    expect($fusion->puedeResolverla($proponente))->toBeFalse()
        ->and($fusion->puedeResolverla($otro))->toBeTrue();
});
