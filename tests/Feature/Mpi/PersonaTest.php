<?php

declare(strict_types=1);

use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\RangoEdad;
use App\Models\Persona;
use App\Support\NormalizadorDeTexto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Identidad y clave de búsqueda
|--------------------------------------------------------------------------
*/

it('asigna un uuid opaco y expone ese uuid en las rutas', function (): void {
    $persona = Persona::factory()->create();

    expect($persona->uuid)->toBeString()
        ->and($persona->uuid)->toHaveLength(36)
        ->and($persona->getRouteKeyName())->toBe('uuid')
        ->and($persona->getKey())->toBeInt();
})->note('El id secuencial no sale del sistema: por una URL se sabría cuántos pacientes lleva el hospital y se podría probar el anterior.');

it('la base calcula la clave de busqueda sin acentos y en minusculas', function (): void {
    $persona = Persona::factory()
        ->llamada('José', 'Antonio', 'Peña', 'Cruz')
        ->create()
        ->refresh();

    expect($persona->nombre_busqueda)->toBe('jose antonio pena cruz');
});

it('recalcula la clave de busqueda aunque la escritura no pase por el modelo', function (): void {
    $persona = Persona::factory()->llamada('Juan', null, 'Perez', 'Lopez')->create();

    DB::table('personas')
        ->where('id', $persona->getKey())
        ->update(['primer_apellido' => 'ÑÁÑEZ']);

    expect($persona->refresh()->nombre_busqueda)->toBe('juan nanez lopez');
})->note('Es el motivo de que la columna sea GENERATED y no un mutator: un import de padrón o un UPDATE a mano no pasan por el modelo, y un nombre que existe pero no se encuentra termina en expediente duplicado.');

it('impide escribir la clave de busqueda a mano', function (): void {
    $persona = Persona::factory()->create();

    DB::table('personas')
        ->where('id', $persona->getKey())
        ->update(['nombre_busqueda' => 'lo que sea']);
})->throws(QueryException::class);

it('la normalizacion de PHP coincide con la de PostgreSQL', function (): void {
    $persona = Persona::factory()
        ->llamada('MARÍA', 'José', 'Ñúñez', 'DE LEÓN')
        ->create(['apellido_casada' => 'Villatoro'])
        ->refresh();

    expect($persona->claveDeBusquedaCalculada())->toBe($persona->nombre_busqueda);
})->note('Si los dos lados se separan, el sistema guarda el nombre y no lo encuentra: no hay error, solo dos expedientes.');

it('colapsa los espacios de las partes vacias del nombre', function (): void {
    $persona = Persona::factory()->llamada('Ana', null, 'Fuentes', null)->create()->refresh();

    expect($persona->nombre_busqueda)->toBe('ana fuentes');
});

/*
|--------------------------------------------------------------------------
| Búsqueda tolerante
|--------------------------------------------------------------------------
*/

it('encuentra por apellido aunque se escriba sin la enie', function (): void {
    Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();
    Persona::factory()->llamada('María', 'Fernanda', 'López', 'Zelaya')->create();

    $encontradas = Persona::buscar('pena')->pluck('primer_apellido')->all();

    expect($encontradas)->toContain('Peña')
        ->and($encontradas)->not->toContain('López');
})->note('Un LIKE no resuelve esto: el teclado de admisión no siempre tiene ñ y nadie escribe los acentos.');

it('encuentra con el nombre completo mal digitado', function (): void {
    Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();

    expect(Persona::buscar('jose antonyo pena cruz'))->toHaveCount(1);
});

it('ordena por parecido: el mas cercano primero', function (): void {
    Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();
    $exacta = Persona::factory()->llamada('Marlon', 'Josué', 'Peña', 'Ramírez')->create();

    expect(Persona::buscar('marlon josue pena ramirez')->first()?->getKey())
        ->toBe($exacta->getKey());
});

it('el scope de busqueda se puede contar', function (): void {
    Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();

    expect(Persona::query()->buscarPorNombre('pena')->count())->toBe(1);
})->note('Por eso el ORDER BY vive en buscar() y no en el scope: PostgreSQL rechaza un count(*) que ordena por una columna sin GROUP BY.');

it('no ofrece de nuevo a una persona que ya fue fusionada', function (): void {
    $sobreviviente = Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();
    $duplicada = Persona::factory()->llamada('Jose', 'Antonio', 'Pena', 'Cruz')->create();

    $duplicada->merged_into = $sobreviviente->getKey();
    $duplicada->merged_at = now();
    $duplicada->save();

    expect(Persona::buscar('jose antonio pena')->pluck('id')->all())
        ->toBe([$sobreviviente->getKey()]);
})->note('El índice trigrama es parcial sobre merged_into IS NULL: buscar tiene que llevar a la sobreviviente, no volver a ofrecer el duplicado que alguien ya resolvió.');

it('devuelve vacio cuando el termino no tiene contenido', function (): void {
    Persona::factory()->create();

    expect(Persona::buscar('   '))->toBeEmpty();
})->note('Sin esta guarda, un campo de búsqueda vacío lista el padrón completo de pacientes.');

/*
|--------------------------------------------------------------------------
| Edad y rango legal
|--------------------------------------------------------------------------
*/

it('resuelve el rango de edad contra la fecha del servicio, no contra hoy', function (): void {
    $persona = Persona::factory()->deEdad(65)->create();

    expect($persona->rangoDeEdadEn(now()))->toBe(RangoEdad::Tercera)
        ->and($persona->rangoDeEdadEn(now()->subYears(10)))->toBe(RangoEdad::Normal);
})->note('Reimprimir una factura de hace diez años no puede cambiarle el descuento: ese día el paciente tenía 55.');

it('detecta la cuarta edad', function (): void {
    expect(Persona::factory()->deEdad(82)->create()->rangoDeEdadEn(now()))
        ->toBe(RangoEdad::Cuarta);
});

it('no inventa un rango de edad cuando no hay fecha de nacimiento', function (): void {
    $nn = Persona::factory()->nn()->create();

    expect($nn->rangoDeEdadEn(now()))->toBeNull()
        ->and($nn->edadEn(now()))->toBeNull();
})->note('Un NN sin identificar no tiene edad; devolver "normal" por defecto sería inventar el dato.');

it('entrega el rango sobre una fecha estimada, pero avisa que no es exacta', function (): void {
    $persona = Persona::factory()->conEdadEstimada(70)->create();

    expect($persona->rangoDeEdadEn(now()))->toBe(RangoEdad::Tercera)
        ->and($persona->fechaNacimientoEsExacta())->toBeFalse()
        ->and($persona->precision_fecha_nacimiento->requiereRevision())->toBeTrue();
})->note('Negarle a un adulto mayor un descuento que la ley obliga es una infracción sancionable; concederlo sobre una estimación es un costo menor y reversible. La asimetría es deliberada.');

/*
|--------------------------------------------------------------------------
| NN
|--------------------------------------------------------------------------
*/

it('permite registrar dos NN sin apellido y sin documento', function (): void {
    Persona::factory()->nn()->create();
    Persona::factory()->nn()->create();

    expect(Persona::query()->where('es_nn', true)->count())->toBe(2);
})->note('Dos accidentados sin documentos la misma noche es una noche normal. Si el sistema no los deja entrar, admisión inventa números.');

it('exige apellido cuando la persona no es un NN', function (): void {
    Persona::factory()->create([
        'primer_apellido'  => null,
        'segundo_apellido' => null,
        'es_nn'            => false,
    ]);
})->throws(QueryException::class);

it('marca al NN para que no se quede sin identificar', function (): void {
    $nn = Persona::factory()->nn()->create();

    expect($nn->es_nn)->toBeTrue()
        ->and($nn->precision_fecha_nacimiento)->toBe(PrecisionFechaNacimiento::Estimada);
});

/*
|--------------------------------------------------------------------------
| Fusión de duplicados (§9.D4)
|--------------------------------------------------------------------------
*/

it('sigue la cadena de fusiones hasta la persona sobreviviente', function (): void {
    $c = Persona::factory()->create();
    $b = Persona::factory()->create();
    $a = Persona::factory()->create();

    $b->merged_into = $c->getKey();
    $b->merged_at = now();
    $b->save();

    $a->merged_into = $b->getKey();
    $a->merged_at = now();
    $a->save();

    expect($a->raiz()->getKey())->toBe($c->getKey())
        ->and($c->raiz()->getKey())->toBe($c->getKey());
})->note('A se fusionó en B y B en C: quien llegue por A tiene que terminar en C, no en B.');

it('la fusion no borra ni mueve la fila duplicada', function (): void {
    $sobreviviente = Persona::factory()->create();
    $duplicada = Persona::factory()->create();

    $duplicada->merged_into = $sobreviviente->getKey();
    $duplicada->merged_at = now();
    $duplicada->save();

    expect(Persona::query()->withTrashed()->count())->toBe(2)
        ->and(Persona::query()->activas()->count())->toBe(1)
        ->and($duplicada->fresh()?->deleted_at)->toBeNull();
})->note('El §9.D4 exige que la fusión sea reversible: deshacerla es borrar el puntero, y para eso la fila tiene que seguir ahí intacta.');

it('la base impide fusionar una persona consigo misma', function (): void {
    $persona = Persona::factory()->create();

    $persona->merged_into = $persona->getKey();
    $persona->merged_at = now();
    $persona->save();
})->throws(QueryException::class)
    ->note('Un ciclo de longitud 1 cuelga cualquier recorrido de la cadena de fusiones.');

it('la base impide una fusion sin fecha', function (): void {
    $sobreviviente = Persona::factory()->create();
    $duplicada = Persona::factory()->create();

    $duplicada->merged_into = $sobreviviente->getKey();
    $duplicada->save();
})->throws(QueryException::class)
    ->note('Una fusión sin fecha ni responsable no se puede auditar ni revertir con criterio.');

/*
|--------------------------------------------------------------------------
| Cotas de plausibilidad
|--------------------------------------------------------------------------
*/

it('impide una defuncion anterior al nacimiento', function (): void {
    Persona::factory()->create([
        'fecha_nacimiento' => '1990-05-10',
        'fecha_defuncion'  => '1989-01-01',
    ]);
})->throws(QueryException::class);

it('impide una fecha de nacimiento imposible', function (): void {
    Persona::factory()->create(['fecha_nacimiento' => '1750-01-01']);
})->throws(QueryException::class)
    ->note('El CHECK usa una cota fija y no CURRENT_DATE: un CHECK debe ser inmutable o el restore de un respaldo puede fallar años después.');

it('normaliza el termino de busqueda igual que la base', function (): void {
    expect(NormalizadorDeTexto::clave('  JOSÉ   Antonio  PEÑA '))->toBe('jose antonio pena');
});
