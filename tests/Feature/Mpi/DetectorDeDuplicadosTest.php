<?php

declare(strict_types=1);

use App\Domain\Enums\NivelDeCoincidencia;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Services\DetectorDeDuplicados;
use Carbon\CarbonInterface;

function detector(): DetectorDeDuplicados
{
    return new DetectorDeDuplicados;
}

/**
 * @param array<int, DocumentoDeIdentidad> $documentos
 */
function datosDe(
    string $primerNombre,
    ?string $primerApellido = null,
    ?CarbonInterface $fechaNacimiento = null,
    array $documentos = [],
    ?string $segundoNombre = null,
    ?string $segundoApellido = null,
): DatosDePaciente {
    return new DatosDePaciente(
        primerNombre: $primerNombre,
        primerApellido: $primerApellido,
        segundoNombre: $segundoNombre,
        segundoApellido: $segundoApellido,
        fechaNacimiento: $fechaNacimiento,
        documentos: $documentos,
    );
}

/*
|--------------------------------------------------------------------------
| Por documento — es prueba, y bloquea
|--------------------------------------------------------------------------
*/

it('bloquea cuando el DNI ya esta registrado', function (): void {
    $existente = Persona::factory()->llamada('José', null, 'Peña', 'Cruz')->create();

    PersonaIdentificador::query()->create([
        'persona_id' => $existente->getKey(),
        'tipo'       => TipoIdentificador::Dni->value,
        'valor'      => '0801199012345',
    ]);

    $coincidencias = detector()->buscar(datosDe(
        'Otro', 'Nombre', null,
        [new DocumentoDeIdentidad(TipoIdentificador::Dni, '0801-1990-12345')],
    ));

    expect($coincidencias)->toHaveCount(1)
        ->and($coincidencias->first()?->nivel)->toBe(NivelDeCoincidencia::Documento)
        ->and(detector()->bloquean($coincidencias))->toBeTrue();
})->note('El número exacto es prueba, no parecido: o es el mismo paciente, o alguien digitó mal. Ninguna de las dos se arregla creando una persona nueva.');

it('no muestra el numero completo del documento en el aviso', function (): void {
    $existente = Persona::factory()->create();

    PersonaIdentificador::query()->create([
        'persona_id' => $existente->getKey(),
        'tipo'       => TipoIdentificador::Dni->value,
        'valor'      => '0801199012345',
    ]);

    $razon = detector()->buscar(datosDe(
        'Otro', 'Nombre', null,
        [new DocumentoDeIdentidad(TipoIdentificador::Dni, '0801199012345')],
    ))->first()?->razon ?? '';

    expect($razon)->toContain('2345')
        ->and($razon)->not->toContain('0801199012345');
})->note('Ese texto termina en el log y en Sentry: un DNI completo ahí es dato personal saliendo por la puerta de atrás, sin bitácora ni control de acceso.');

it('lleva al sobreviviente cuando el documento estaba en una persona fusionada', function (): void {
    $sobreviviente = Persona::factory()->create();
    $duplicada     = Persona::factory()->create();

    PersonaIdentificador::query()->create([
        'persona_id' => $duplicada->getKey(),
        'tipo'       => TipoIdentificador::Dni->value,
        'valor'      => '0801199012345',
    ]);

    $duplicada->merged_into = $sobreviviente->getKey();
    $duplicada->merged_at   = now();
    $duplicada->save();

    $coincidencia = detector()->buscar(datosDe(
        'Quien', 'Sea', null,
        [new DocumentoDeIdentidad(TipoIdentificador::Dni, '0801199012345')],
    ))->first();

    expect($coincidencia?->persona->getKey())->toBe($sobreviviente->getKey());
})->note('Sin esto, admisión abriría el expediente de un duplicado que alguien ya se tomó el trabajo de resolver.');

/*
|--------------------------------------------------------------------------
| Por nombre — es parecido, y solo avisa
|--------------------------------------------------------------------------
*/

it('avisa sin bloquear cuando solo se parece el nombre', function (): void {
    Persona::factory()->llamada('Juan', 'Carlos', 'Pérez', 'López')
        ->create(['fecha_nacimiento' => '1978-03-03']);

    $coincidencias = detector()->buscar(datosDe(
        'Juan', 'Perez', now()->setDate(1990, 1, 1), [], 'Carlos', 'Lopez',
    ));

    expect($coincidencias)->toHaveCount(1)
        ->and($coincidencias->first()?->nivel)->toBe(NivelDeCoincidencia::Media)
        ->and(detector()->bloquean($coincidencias))->toBeFalse();
})->note('"Juan Pérez" hay veinte en cualquier hospital de Honduras. Una advertencia que salta siempre no advierte de nada: admisión aprende a darle continuar sin leerla.');

it('sube el nivel cuando ademas coincide la fecha de nacimiento', function (): void {
    Persona::factory()->llamada('Juan', 'Carlos', 'Pérez', 'López')
        ->create(['fecha_nacimiento' => '1978-03-03']);

    $coincidencias = detector()->buscar(datosDe(
        'Juan', 'Perez', now()->setDate(1978, 3, 3), [], 'Carlos', 'Lopez',
    ));

    expect($coincidencias->first()?->nivel)->toBe(NivelDeCoincidencia::Alta)
        ->and(detector()->bloquean($coincidencias))->toBeFalse();
});

it('no descarta al candidato porque la fecha no coincida', function (): void {
    Persona::factory()->llamada('Juan', 'Carlos', 'Pérez', 'López')
        ->create(['fecha_nacimiento' => '1978-03-03']);

    $coincidencias = detector()->buscar(datosDe(
        'Juan', 'Perez', now()->setDate(1978, 3, 30), [], 'Carlos', 'Lopez',
    ));

    expect($coincidencias)->toHaveCount(1);
})->note('El dedazo en la fecha es de los errores de captura más comunes: dos fechas distintas con el mismo nombre siguen siendo un candidato, solo que más débil.');

it('no propone a alguien que no se parece', function (): void {
    Persona::factory()->llamada('María', 'Fernanda', 'López', 'Zelaya')->create();

    expect(detector()->buscar(datosDe('Bartolomé', 'Kowalczyk')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Casos límite
|--------------------------------------------------------------------------
*/

it('no busca duplicados de un NN', function (): void {
    Persona::factory()->nn()->create();
    Persona::factory()->nn()->create();

    expect(detector()->buscar(DatosDePaciente::nn()))->toBeEmpty();
})->note('Un NN se llama NN igual que todos los demás NN: buscarle duplicados devolvería todos los del hospital y frenaría una emergencia por nada.');

it('no repite a la misma persona cuando coincide por documento y por nombre', function (): void {
    $existente = Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();

    PersonaIdentificador::query()->create([
        'persona_id' => $existente->getKey(),
        'tipo'       => TipoIdentificador::Dni->value,
        'valor'      => '0801199012345',
    ]);

    $coincidencias = detector()->buscar(datosDe(
        'José', 'Peña', null,
        [new DocumentoDeIdentidad(TipoIdentificador::Dni, '0801199012345')],
        'Antonio', 'Cruz',
    ));

    expect($coincidencias)->toHaveCount(1)
        ->and($coincidencias->first()?->nivel)->toBe(NivelDeCoincidencia::Documento);
})->note('Mostrarla dos veces le hace creer a admisión que hay dos pacientes cuando hay uno; y se queda con la aparición más fuerte, que es la del documento.');

it('rechaza un DNI con longitud equivocada antes de llegar a la base', function (): void {
    new DocumentoDeIdentidad(TipoIdentificador::Dni, '08011990123');
})->throws(App\Domain\Exceptions\ValueObjectInvalidoException::class);

it('exige el pais de emision de un pasaporte', function (): void {
    new DocumentoDeIdentidad(TipoIdentificador::Pasaporte, 'A1234567');
})->throws(App\Domain\Exceptions\ValueObjectInvalidoException::class)
    ->note('El mismo número de pasaporte puede existir en dos países; sin el país, el segundo turista choca contra el primero.');
