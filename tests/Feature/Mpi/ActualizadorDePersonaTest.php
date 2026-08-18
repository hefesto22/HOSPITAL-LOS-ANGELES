<?php

declare(strict_types=1);

use App\Models\Persona;
use App\Models\PersonaVersion;
use App\Services\ActualizadorDePersona;

function actualizador(): ActualizadorDePersona
{
    return app(ActualizadorDePersona::class);
}

it('escribe una version con lo que quedo guardado', function (): void {
    $persona = Persona::factory()->llamada('Ana', 'Lucía', 'Fuentes', 'Zelaya')->create();

    actualizador()->actualizar(
        $persona,
        ['apellido_casada' => 'villatoro'],
        'Cambio de apellido por matrimonio',
    );

    $version = PersonaVersion::query()->where('persona_id', $persona->getKey())->latest('version')->first();

    expect($version?->version)->toBe(1)
        ->and($version?->motivo)->toBe('Cambio de apellido por matrimonio')
        ->and($version?->datos['apellido_casada'])->toBe('VILLATORO');
})->note('La foto se toma DESPUÉS de guardar: lo que documenta el historial es lo que quedó en la base, no lo que se intentó guardar.');

it('deja el diff precalculado para la pantalla de auditoria', function (): void {
    $persona = Persona::factory()->llamada('Ana', null, 'Fuentes', null)->create();

    actualizador()->actualizar($persona, ['primer_apellido' => 'Zelaya'], 'Corrección de digitación');

    $cambios = PersonaVersion::query()->where('persona_id', $persona->getKey())->value('cambios');

    expect($cambios)->toHaveKey('primer_apellido')
        ->and($cambios['primer_apellido']['antes'])->toBe('FUENTES')
        ->and($cambios['primer_apellido']['despues'])->toBe('ZELAYA');
})->note('Calcularlo al vuelo obligaría a leer dos filas y compararlas en PHP en cada renglón de la bitácora.');

it('no escribe version cuando no cambio nada', function (): void {
    $persona = Persona::factory()->llamada('Ana', null, 'Fuentes', null)->create();

    actualizador()->actualizar($persona, ['primer_apellido' => 'Fuentes'], 'Corrección de digitación');

    expect(PersonaVersion::query()->where('persona_id', $persona->getKey())->count())->toBe(0);
})->note('Abrir el formulario y apretar guardar no es un cambio. Un historial lleno de versiones idénticas es un historial que nadie lee, y el día que haya que auditar de verdad no se encuentra la fila que importa.');

it('no confunde un objeto Carbon con un cambio de fecha', function (): void {
    $persona = Persona::factory()->create(['fecha_nacimiento' => '1978-03-03']);

    actualizador()->actualizar($persona, ['telefono' => '+504 9999-8888'], 'Actualización de datos de contacto');

    $cambios = PersonaVersion::query()->where('persona_id', $persona->getKey())->value('cambios');

    expect($cambios)->not->toHaveKey('fecha_nacimiento')
        ->and($cambios)->toHaveKey('telefono');
})->note('only() devuelve objetos Carbon y enums; comparar dos instancias distintas con !== da siempre "cambió", y el historial diría que se modificó la fecha de nacimiento cada vez que alguien abre el formulario.');

it('numera las versiones en orden', function (): void {
    $persona = Persona::factory()->llamada('Ana', null, 'Fuentes', null)->create();

    actualizador()->actualizar($persona, ['primer_apellido' => 'Zelaya'], 'Corrección de digitación');
    actualizador()->actualizar($persona, ['telefono' => '+504 2222-1111'], 'Actualización de datos de contacto');

    expect(PersonaVersion::query()->where('persona_id', $persona->getKey())->pluck('version')->all())
        ->toBe([1, 2]);
});

it('respeta la forma canonica al guardar', function (): void {
    $persona = Persona::factory()->llamada('Ana', null, 'Fuentes', null)->create();

    actualizador()->actualizar($persona, ['primer_apellido' => '  zelaya  '], 'Corrección de digitación');

    expect($persona->fresh()?->primer_apellido)->toBe('ZELAYA');
});

it('ignora campos que no son editables', function (): void {
    $sobreviviente = Persona::factory()->create();
    $persona = Persona::factory()->create();

    actualizador()->actualizar(
        $persona,
        ['merged_into' => $sobreviviente->getKey(), 'telefono' => '+504 3333-2222'],
        'Actualización de datos de contacto',
    );

    expect($persona->fresh()?->merged_into)->toBeNull();
})->note('Fusionar no es editar un campo: tiene su propio flujo con doble aprobación y reversa. Que el actualizador pudiera hacerlo por descuido sería la peor puerta trasera del módulo.');
