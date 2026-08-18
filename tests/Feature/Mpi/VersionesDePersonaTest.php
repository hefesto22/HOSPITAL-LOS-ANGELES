<?php

declare(strict_types=1);

use App\Models\Persona;
use App\Models\PersonaVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @param array<string, mixed> $atributos
 */
function versionDe(Persona $persona, int $numero, array $atributos = []): PersonaVersion
{
    /** @var PersonaVersion $version */
    $version = PersonaVersion::query()->create(array_merge([
        'persona_id'    => $persona->getKey(),
        'version'       => $numero,
        'datos'         => $persona->only(PersonaVersion::camposVersionados()),
        'motivo'        => 'Registro inicial',
        'registrado_en' => now(),
    ], $atributos));

    return $version;
}

it('guarda la foto completa de los datos, no solo el cambio', function (): void {
    $persona = Persona::factory()->llamada('Ana', 'Lucía', 'Fuentes', 'Zelaya')->create();

    $version = versionDe($persona, 1);

    expect($version->datos)->toHaveKey('primer_apellido')
        ->and($version->datos['primer_apellido'])->toBe('FUENTES')
        ->and($version->datos)->toHaveKey('fecha_nacimiento');
})->note('Solo el diff obliga a reconstruir el estado sumando todas las versiones anteriores; basta con que una se haya guardado mal para que la reconstrucción mienta sin avisar.');

it('reconstruye el apellido que llevaba la factura de hace dos anios', function (): void {
    $persona = Persona::factory()->llamada('Ana', 'Lucía', 'Fuentes', 'Zelaya')->create();
    versionDe($persona, 1, ['registrado_en' => now()->subYears(3)]);

    $persona->apellido_casada = 'Villatoro';
    $persona->save();
    versionDe($persona, 2, ['motivo' => 'Cambio de apellido por matrimonio']);

    $alMomentoDeFacturar = PersonaVersion::query()
        ->where('persona_id', $persona->getKey())
        ->where('registrado_en', '<=', now()->subYears(2))
        ->orderByDesc('version')
        ->first();

    expect($alMomentoDeFacturar?->datos['apellido_casada'])->toBeNull()
        ->and($persona->fresh()?->apellido_casada)->toBe('VILLATORO');
})->note('La factura del año pasado salió con el apellido de soltera y el SAR la puede auditar: tiene que reimprimirse exactamente igual que se emitió.');

it('la base impide modificar una version historica', function (): void {
    $version = versionDe(Persona::factory()->create(), 1);

    DB::table('persona_versiones')
        ->where('id', $version->getKey())
        ->update(['motivo' => 'otra cosa']);
})->throws(QueryException::class)
    ->note('Append-only del ADR-0004 hecho cumplir por un trigger, no por un comentario: un tinker a las 11 de la noche no lee comentarios.');

it('la base impide borrar una version historica', function (): void {
    $version = versionDe(Persona::factory()->create(), 1);

    DB::table('persona_versiones')->where('id', $version->getKey())->delete();
})->throws(QueryException::class);

it('un save sobre una version ya guardada tambien falla', function (): void {
    $version = versionDe(Persona::factory()->create(), 1);

    $version->motivo = 'corregido';
    $version->save();
})->throws(QueryException::class)
    ->note('El candado está en la base, así que aplica igual venga de Eloquent, de un seeder o de psql.');

it('no permite dos veces el mismo numero de version para una persona', function (): void {
    $persona = Persona::factory()->create();

    versionDe($persona, 1);
    versionDe($persona, 1);
})->throws(QueryException::class)
    ->note('"La versión 3 del expediente de fulano" tiene que señalar una sola fila en un informe de auditoría.');

it('numera las versiones por persona, no globalmente', function (): void {
    versionDe(Persona::factory()->create(), 1);
    versionDe(Persona::factory()->create(), 1);

    expect(PersonaVersion::query()->where('version', 1)->count())->toBe(2);
});

it('deja constancia de por que cambio el dato', function (): void {
    $persona = Persona::factory()->create();

    $version = versionDe($persona, 1, ['motivo' => 'Corrección de digitación en fecha de nacimiento']);

    expect($version->motivo)->toBe('Corrección de digitación en fecha de nacimiento')
        ->and($version->persona()->first()?->getKey())->toBe($persona->getKey());
});
