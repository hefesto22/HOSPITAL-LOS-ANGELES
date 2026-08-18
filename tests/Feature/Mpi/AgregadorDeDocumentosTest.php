<?php

declare(strict_types=1);

use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Services\AgregadorDeDocumentos;

function agregador(): AgregadorDeDocumentos
{
    return app(AgregadorDeDocumentos::class);
}

function dni(string $valor = '0801199012345', bool $principal = false): DocumentoDeIdentidad
{
    return new DocumentoDeIdentidad(TipoIdentificador::Dni, $valor, esPrincipal: $principal);
}

it('agrega un documento despues del alta', function (): void {
    $persona = Persona::factory()->create();

    $identificador = agregador()->agregar($persona, dni('0801-1990-12345'));

    expect($identificador->valor)->toBe('0801199012345')
        ->and($identificador->persona_id)->toBe($persona->getKey())
        ->and($identificador->estaVerificado())->toBeFalse();
})->note('El paciente que llegó sin cédula y la trae al día siguiente es rutina, no excepción.');

it('no crea dos veces el mismo documento de la misma persona', function (): void {
    $persona = Persona::factory()->create();

    $primero = agregador()->agregar($persona, dni());
    $segundo = agregador()->agregar($persona, dni());

    expect($segundo->getKey())->toBe($primero->getKey())
        ->and(PersonaIdentificador::query()->count())->toBe(1);
})->note('Agregar dos veces lo mismo no es un error del usuario que merezca un error de base de datos: se devuelve el que ya está.');

it('rechaza el numero que ya es de otra persona', function (): void {
    agregador()->agregar(Persona::factory()->create(), dni());

    agregador()->agregar(Persona::factory()->create(), dni());
})->throws(PosibleDuplicadoException::class);

it('dice de quien es el numero que choca', function (): void {
    $dueno = Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();
    agregador()->agregar($dueno, dni());

    try {
        agregador()->agregar(Persona::factory()->create(), dni());
        expect(false)->toBeTrue();
    } catch (PosibleDuplicadoException $e) {
        expect($e->coincidencias->first()?->resumen())->toContain('PEÑA CRUZ')
            ->and($e->coincidencias->first()?->resumen())->not->toContain('0801199012345');
    }
})->note('Quien atiende necesita saber contra quién chocó para decidir; y el número va enmascarado porque ese texto termina en el log.');

it('permite el conflicto declarado', function (): void {
    agregador()->agregar(Persona::factory()->create(), dni());

    $segundo = agregador()->agregarPeseAlConflicto(
        Persona::factory()->create(),
        dni(),
        'Verificado con el RNP: son dos personas distintas con el mismo numero mal emitido.',
    );

    expect($segundo->en_conflicto)->toBeTrue()
        ->and(PersonaIdentificador::query()->count())->toBe(2);
});

it('baja al principal anterior antes de poner el nuevo', function (): void {
    $persona = Persona::factory()->create();

    $viejo = agregador()->agregar($persona, dni('0801199012345', principal: true));
    $nuevo = agregador()->agregar($persona, new DocumentoDeIdentidad(
        TipoIdentificador::Rtn,
        '08011990123456',
        esPrincipal: true,
    ));

    expect($nuevo->es_principal)->toBeTrue()
        ->and($viejo->fresh()?->es_principal)->toBeFalse();
})->note('La base impone un solo principal por persona con un índice único parcial: si no se baja el anterior primero, el insert choca y el usuario ve un error de base de datos sin sentido.');

it('deja constancia de haber visto el documento fisico', function (): void {
    $persona = Persona::factory()->create();
    $identificador = agregador()->agregar($persona, dni());

    agregador()->verificar($identificador);

    expect($identificador->fresh()?->estaVerificado())->toBeTrue();
})->note('Un DNI dictado por teléfono y uno fotocopiado no valen lo mismo para facturar con RTN ni para reclamar a una aseguradora.');

it('verificar dos veces no cambia la fecha original', function (): void {
    $persona = Persona::factory()->create();
    $identificador = agregador()->agregar($persona, dni());

    agregador()->verificar($identificador);
    $primera = $identificador->fresh()?->verificado_en;

    agregador()->verificar($identificador->fresh() ?? $identificador);

    expect($identificador->fresh()?->verificado_en?->toIso8601String())
        ->toBe($primera?->toIso8601String());
})->note('Haberlo visto es un hecho con fecha; pisarla al volver a apretar el botón reescribiría cuándo pasó.');
