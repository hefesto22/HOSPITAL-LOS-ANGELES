<?php

declare(strict_types=1);

use App\Models\Almacen;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Servicio;
use App\Support\NormalizadorDeTexto;
use App\Support\TextoCanonico;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La regla
|--------------------------------------------------------------------------
*/

it('guarda los nombres en mayusculas', function (): void {
    $persona = Persona::factory()->llamada('josé', 'antonio', 'peña', 'cruz')->create();

    expect($persona->primer_nombre)->toBe('JOSÉ')
        ->and($persona->primer_apellido)->toBe('PEÑA')
        ->and($persona->segundo_apellido)->toBe('CRUZ');
});

it('conserva la enie y las tildes', function (): void {
    $persona = Persona::factory()->llamada('José', null, 'Peña', 'Núñez')->create();

    expect($persona->primer_nombre)->toBe('JOSÉ')
        ->and($persona->primer_apellido)->toBe('PEÑA')
        ->and($persona->segundo_apellido)->toBe('NÚÑEZ');
})->note('El nombre guardado es el que sale impreso en la factura, y la factura tiene que coincidir letra por letra con el DNI. Para el RNP, PENA y PEÑA son dos apellidos distintos.');

it('busca sin tildes aunque guarde con tildes', function (): void {
    Persona::factory()->llamada('José', 'Antonio', 'Peña', 'Cruz')->create();

    expect(Persona::buscar('pena')->first()?->primer_apellido)->toBe('PEÑA');
})->note('Ahí está la uniformidad que uno busca al querer quitar las tildes: se busca sin ellas y se imprime con ellas. No hace falta elegir.');

it('recorta y colapsa los espacios', function (): void {
    $persona = Persona::factory()->llamada('  juan   carlos ', null, ' perez ', null)->create();

    expect($persona->primer_nombre)->toBe('JUAN CARLOS')
        ->and($persona->primer_apellido)->toBe('PEREZ');
});

it('convierte el vacio en nulo', function (): void {
    $persona = Persona::factory()->create(['segundo_nombre' => '   ']);

    expect($persona->segundo_nombre)->toBeNull();
})->note('Un formulario que envía "" y un import que no manda el campo dicen lo mismo —no hay dato— y producían dos estados distintos: después un whereNull encontraba unos y no otros.');

/*
|--------------------------------------------------------------------------
| Dónde NO se aplica
|--------------------------------------------------------------------------
*/

it('no toca el correo', function (): void {
    $persona = Persona::factory()->create(['email' => 'Jose.Pena@Gmail.com']);

    expect($persona->email)->toBe('Jose.Pena@Gmail.com');
})->note('La parte local de un correo es sensible a mayúsculas del lado del servidor: convertirlo puede romper el envío o el ingreso.');

it('no toca la nota de identificacion del NN', function (): void {
    $persona = Persona::factory()->nn()->create([
        'nota_identificacion' => 'Varón, ~40 años, tatuaje en antebrazo derecho.',
    ]);

    expect($persona->nota_identificacion)->toBe('Varón, ~40 años, tatuaje en antebrazo derecho.');
})->note('Es texto libre descriptivo: en mayúsculas se vuelve ilegible, igual que una nota clínica.');

/*
|--------------------------------------------------------------------------
| El formulario no es la única puerta
|--------------------------------------------------------------------------
*/

it('aplica aunque la escritura no venga de un formulario', function (): void {
    $persona = Persona::query()->create([
        'primer_nombre'   => 'ana lucía',
        'primer_apellido' => 'fuentes',
    ]);

    expect($persona->primer_nombre)->toBe('ANA LUCÍA');
})->note('Un seeder, un import de padrón o un comando de consola escriben directo al modelo; una regla que solo vive en el formulario no es una regla.');

it('un update masivo se salta el modelo, y por eso esto no es la garantia final', function (): void {
    $persona = Persona::factory()->llamada('José', null, 'Peña', null)->create();

    DB::table('personas')->where('id', $persona->getKey())->update(['primer_nombre' => 'jose']);

    expect($persona->fresh()?->primer_nombre)->toBe('jose');
})->note('Esta prueba documenta el límite, no un bug. A diferencia de nombre_busqueda —que la calcula PostgreSQL y no puede desviarse jamás— esto vive en Eloquent, y un UPDATE masivo no dispara eventos. Es una de las razones del §11: los Services son la única puerta de escritura.');

/*
|--------------------------------------------------------------------------
| Catálogo
|--------------------------------------------------------------------------
*/

it('aplica al catalogo del hospital', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'hla', 'nombre' => 'hospital los ángeles']);
    $servicio = Servicio::factory()->create(['sede_id' => $sede->getKey(), 'nombre' => 'emergencia']);
    $almacen = Almacen::factory()->create(['sede_id' => $sede->getKey(), 'nombre' => 'bodega central']);

    expect($sede->codigo)->toBe('HLA')
        ->and($sede->nombre)->toBe('HOSPITAL LOS ÁNGELES')
        ->and($servicio->nombre)->toBe('EMERGENCIA')
        ->and($almacen->nombre)->toBe('BODEGA CENTRAL');
});

/*
|--------------------------------------------------------------------------
| La regla, aislada
|--------------------------------------------------------------------------
*/

it('la clave de busqueda sigue ignorando tildes', function (): void {
    expect(NormalizadorDeTexto::clave('PEÑA'))->toBe('pena')
        ->and(TextoCanonico::mayusculas('peña'))->toBe('PEÑA');
})->note('Las dos reglas conviven a propósito: TextoCanonico es cómo se imprime, NormalizadorDeTexto es cómo se busca.');

it('no convierte con strtoupper a secas', function (): void {
    expect(TextoCanonico::mayusculas('peña josé'))->toBe('PEÑA JOSÉ')
        ->and(strtoupper('peña josé'))->not->toBe('PEÑA JOSÉ');
})->note('strtoupper() no convierte ñ ni las vocales acentuadas: "peña" quedaría "PEñA", que es peor que no haber hecho nada.');
