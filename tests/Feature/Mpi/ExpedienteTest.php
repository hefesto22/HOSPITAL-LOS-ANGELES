<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoExpediente;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use Illuminate\Database\QueryException;

it('no permite dos expedientes de la misma persona en la misma sede', function (): void {
    $persona = Persona::factory()->create();
    $sede    = Sede::factory()->create();

    Expediente::factory()->create(['persona_id' => $persona->getKey(), 'sede_id' => $sede->getKey()]);
    Expediente::factory()->create(['persona_id' => $persona->getKey(), 'sede_id' => $sede->getKey()]);
})->throws(QueryException::class)
    ->note('Dos personas de admisión atendiendo al mismo paciente el mismo día abrirían dos carpetas, y a partir de ahí la mitad de las notas van a una y la mitad a la otra.');

it('permite que la misma persona tenga expediente en dos sedes', function (): void {
    $persona = Persona::factory()->create();

    Expediente::factory()->create(['persona_id' => $persona->getKey(), 'sede_id' => Sede::factory()]);
    Expediente::factory()->create(['persona_id' => $persona->getKey(), 'sede_id' => Sede::factory()]);

    expect(Expediente::query()->where('persona_id', $persona->getKey())->count())->toBe(2);
})->note('La identidad es de la organización y la carpeta es de la sede: el mismo paciente en dos sedes es una persona con dos expedientes, no dos personas.');

it('no permite dos expedientes con el mismo numero', function (): void {
    Expediente::factory()->create(['numero' => 'EXP-HLA-00000001']);
    Expediente::factory()->create(['numero' => 'EXP-HLA-00000001']);
})->throws(QueryException::class)
    ->note('El número lleva el código de la sede adentro, así que es único en toda la organización: si dos sedes pudieran emitir el mismo texto, buscar por número no serviría.');

it('resuelve el estado de archivo contra la fecha que se le pasa', function (): void {
    $reciente = Expediente::factory()->create(['ultima_atencion_el' => now()->subYears(2)]);
    $viejo    = Expediente::factory()->pasivo()->create();
    $antiguo  = Expediente::factory()->depurable()->create();

    expect($reciente->estadoEn(now()))->toBe(EstadoExpediente::Activo)
        ->and($viejo->estadoEn(now()))->toBe(EstadoExpediente::Pasivo)
        ->and($antiguo->estadoEn(now()))->toBe(EstadoExpediente::Depurable);
})->note('Los plazos salen de config/sihla.php: la norma de conservación puede cambiar y no se puede depender de un despliegue para cumplirla.');

it('una atencion nueva saca la carpeta del archivo pasivo', function (): void {
    $expediente = Expediente::factory()->pasivo()->create();

    expect($expediente->estado)->toBe(EstadoExpediente::Pasivo);

    $expediente->registrarAtencion(now());

    expect($expediente->fresh()?->estado)->toBe(EstadoExpediente::Activo);
})->note('El estado no es una etiqueta que alguien pone: es consecuencia de la última atención.');

it('usa el numero como llave de ruta, no el id', function (): void {
    $expediente = Expediente::factory()->create(['numero' => 'EXP-HLA-00000042']);

    expect($expediente->getRouteKeyName())->toBe('numero')
        ->and($expediente->getRouteKey())->toBe('EXP-HLA-00000042');
})->note('Es lo que el personal ya tiene escrito en la carpeta; y de paso, una URL con el id secuencial diría cuántos pacientes lleva el hospital.');

it('cuando no hubo ninguna atencion cuenta desde que se abrio', function (): void {
    $expediente = Expediente::factory()->create([
        'abierto_el'         => now()->subYears(9)->toDateString(),
        'ultima_atencion_el' => null,
    ]);

    expect($expediente->estadoEn(now()))->toBe(EstadoExpediente::Pasivo);
})->note('Un expediente que se abrió y nunca se usó también envejece; sin este caso quedaría activo para siempre.');
