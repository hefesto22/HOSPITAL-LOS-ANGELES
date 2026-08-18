<?php

declare(strict_types=1);

use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\PersonaVersion;
use App\Models\Sede;
use App\Services\RegistradorDePacientes;

function registrador(): RegistradorDePacientes
{
    return app(RegistradorDePacientes::class);
}

function pacienteConDni(string $dni = '0801199012345'): DatosDePaciente
{
    return new DatosDePaciente(
        primerNombre: 'José',
        primerApellido: 'Peña',
        segundoNombre: 'Antonio',
        segundoApellido: 'Cruz',
        fechaNacimiento: now()->setDate(1978, 3, 3),
        documentos: [new DocumentoDeIdentidad(TipoIdentificador::Dni, $dni, esPrincipal: true)],
        telefono: '+504 9999-8888',
    );
}

/*
|--------------------------------------------------------------------------
| El camino normal
|--------------------------------------------------------------------------
*/

it('crea persona, documentos, version 1 y expediente en una sola operacion', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    $expediente = registrador()->registrar(pacienteConDni(), $sede);

    expect($expediente->numero)->toBe('EXP-HLA-00000001')
        ->and($expediente->sede_id)->toBe($sede->getKey())
        ->and(Persona::query()->count())->toBe(1)
        ->and(PersonaIdentificador::query()->count())->toBe(1)
        ->and(PersonaVersion::query()->where('version', 1)->count())->toBe(1);
})->note('Las cuatro escrituras van juntas: si la del expediente fallara aparte, quedaría una persona que existe en el historial y que nadie puede atender — y el correlativo ya consumido.');

it('la version 1 guarda lo que quedo en la base, no lo que se intento guardar', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni(), $sede);

    $version = PersonaVersion::query()->where('version', 1)->first();

    expect($version?->datos['primer_apellido'])->toBe('Peña')
        ->and($version?->motivo)->toBe('Registro inicial del paciente');
});

it('normaliza el documento al guardarlo', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni('0801-1990-12345'), $sede);

    expect(PersonaIdentificador::query()->value('valor'))->toBe('0801199012345');
});

/*
|--------------------------------------------------------------------------
| Duplicados
|--------------------------------------------------------------------------
*/

it('lanza cuando el DNI ya esta registrado', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni(), $sede);
    registrador()->registrar(pacienteConDni(), $sede);
})->throws(PosibleDuplicadoException::class);

it('no deja basura cuando rechaza el registro', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni(), $sede);

    try {
        registrador()->registrar(pacienteConDni(), $sede);
    } catch (PosibleDuplicadoException) {
        // Esperado.
    }

    expect(Persona::query()->count())->toBe(1)
        ->and(Expediente::query()->count())->toBe(1)
        ->and(PersonaVersion::query()->count())->toBe(1);
})->note('La detección corre ANTES de abrir la transacción: no hay nada que revertir porque no se escribió nada.');

it('la excepcion trae los candidatos para no tener que volver a buscar', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni(), $sede);

    try {
        registrador()->registrar(pacienteConDni(), $sede);
        expect(false)->toBeTrue();
    } catch (PosibleDuplicadoException $e) {
        expect($e->coincidencias)->toHaveCount(1)
            ->and($e->coincidencias->first()?->resumen())->toContain('Peña Cruz, José Antonio');
    }
})->note('Una excepción que solo dice "duplicado" obliga a buscar otra vez para saber contra quién chocó, con el paciente esperando en el mostrador.');

it('registra pese al conflicto dejando la justificacion escrita', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrar(pacienteConDni(), $sede);

    $segundo = registrador()->registrarPeseAlConflicto(
        pacienteConDni(),
        $sede,
        'El paciente presenta DNI ya registrado a otra persona. Verificar con RNP.',
    );

    expect($segundo->numero)->toBe('EXP-HLA-00000002')
        ->and(PersonaIdentificador::query()->where('en_conflicto', true)->count())->toBe(1)
        ->and(Persona::query()->count())->toBe(2);
})->note('Es la salida de emergencia del §8.2: la diferencia con inventar un número es que acá el conflicto queda registrado COMO conflicto, con nombre, hora y explicación.');

/*
|--------------------------------------------------------------------------
| Emergencia
|--------------------------------------------------------------------------
*/

it('registra un NN sin pedir un solo dato', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    $expediente = registrador()->registrarNn($sede, 'Varón, ~40 años, lo trajo la ambulancia.');

    $persona = $expediente->persona;

    expect($expediente->numero)->toBe('EXP-HLA-00000001')
        ->and($persona->es_nn)->toBeTrue()
        ->and($persona->primer_apellido)->toBeNull()
        ->and($persona->fecha_nacimiento)->toBeNull()
        ->and($persona->nota_identificacion)->toContain('ambulancia');
})->note('Cada campo obligatorio en esa pantalla son segundos de alguien que debería estar poniendo una vía.');

it('permite registrar dos NN la misma noche', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    registrador()->registrarNn($sede);
    registrador()->registrarNn($sede);

    expect(Expediente::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Persona que ya existe
|--------------------------------------------------------------------------
*/

it('abre expediente a una persona que ya existe', function (): void {
    $sede    = Sede::factory()->create(['codigo' => 'HLA']);
    $persona = Persona::factory()->create();

    $expediente = registrador()->abrirExpedienteEn($persona, $sede);

    expect($expediente->persona_id)->toBe($persona->getKey())
        ->and($expediente->numero)->toBe('EXP-HLA-00000001');
})->note('Es el camino de quien estaba registrado como acompañante y ahora se enferma.');

it('no abre dos expedientes en la misma sede', function (): void {
    $sede    = Sede::factory()->create(['codigo' => 'HLA']);
    $persona = Persona::factory()->create();

    $primero = registrador()->abrirExpedienteEn($persona, $sede);
    $segundo = registrador()->abrirExpedienteEn($persona, $sede);

    expect($segundo->getKey())->toBe($primero->getKey())
        ->and(Expediente::query()->count())->toBe(1);
})->note('Es idempotente a propósito: que abrir dos veces la misma carpeta reviente con un error de base de datos no le sirve a nadie en un mostrador.');

it('abre expediente en la segunda sede a la misma persona', function (): void {
    $primera = Sede::factory()->create(['codigo' => 'HLA']);
    $segunda = Sede::factory()->create(['codigo' => 'HLA2']);
    $persona = Persona::factory()->create();

    $uno = registrador()->abrirExpedienteEn($persona, $primera);
    $dos = registrador()->abrirExpedienteEn($persona, $segunda);

    expect($uno->numero)->toBe('EXP-HLA-00000001')
        ->and($dos->numero)->toBe('EXP-HLA2-00000001')
        ->and($persona->getKey())->toBe($dos->persona_id);
})->note('Misma persona, dos carpetas: la historia clínica se arma por persona y cada sede conserva el archivo legal que SESAL le habilita.');

it('abre el expediente en la sede que se le pasa, no en la del contexto', function (): void {
    $elegida = Sede::factory()->create(['codigo' => 'HLA2']);
    Sede::factory()->create(['codigo' => 'HLA']);

    $expediente = registrador()->registrar(pacienteConDni(), $elegida);

    expect($expediente->sede_id)->toBe($elegida->getKey())
        ->and($expediente->numero)->toStartWith('EXP-HLA2-');
})->note('Dejar que la sede seleccionada en la pantalla decida es cómo se termina con el expediente de un paciente colgando de la sede equivocada.');
