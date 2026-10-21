<?php

declare(strict_types=1);

use App\Domain\Enums\MagnitudDeMedida;
use App\Models\Item;
use App\Models\Unidad;
use Database\Seeders\UnidadesSeeder;
use Illuminate\Support\Facades\DB;

/*
 * LAS UNIDADES QUE PIDE LA LISTA DE PRODUCTOS (Mauricio, 14-oct-2026).
 *
 * El Excel de farmacia ofrece 18 formas en «Se dispensa en» y 13 en
 * «Viene en». Todas tienen que existir como unidad, o la carga de la
 * lista no tendría a qué apuntar. Siete eran nuevas y las agrega la
 * migración `2026_10_14_120000_…`; el resto viene de `UnidadesSeeder`.
 */

/**
 * @return array<string, array{0: string, 1: string}>
 */
function unidadesNuevasDeLaLista(): array
{
    return [
        'SUP'    => ['SUPOSITORIO', 'sup'],
        'OVULO'  => ['ÓVULO', 'óv'],
        'PARCHE' => ['PARCHE', 'parche'],
        'TUBO'   => ['TUBO', 'tubo'],
        'PAQ'    => ['PAQUETE', 'paq'],
        'ROLLO'  => ['ROLLO', 'rollo'],
        'GALON'  => ['GALÓN', 'gal'],
    ];
}

function migracionDeUnidadesDeLaLista(): object
{
    return require database_path('migrations/2026_10_14_120000_las_unidades_que_pide_la_lista_de_productos.php');
}

it('la migración deja las siete unidades nuevas, de conteo y sin fracción', function (): void {
    foreach (unidadesNuevasDeLaLista() as $codigo => [$nombre, $simbolo]) {
        $unidad = Unidad::query()->where('codigo', $codigo)->first();

        expect($unidad)->toBeInstanceOf(Unidad::class, "Falta la unidad {$codigo}")
            ->and($unidad?->nombre)->toBe($nombre)
            ->and($unidad?->simbolo)->toBe($simbolo)
            ->and($unidad?->magnitud)->toBe(MagnitudDeMedida::Conteo)
            ->and($unidad?->permite_fraccion)->toBeFalse()
            ->and($unidad?->contiene_envases)->toBeFalse();
    }
});

it('correr la migración otra vez no duplica nada', function (): void {
    migracionDeUnidadesDeLaLista()->up();

    foreach (array_keys(unidadesNuevasDeLaLista()) as $codigo) {
        expect(Unidad::query()->where('codigo', $codigo)->count())->toBe(1);
    }
});

it('si el hospital ya la creó con otro código, aunque sea sin tilde, no la duplica', function (): void {
    DB::table('unidades')->where('codigo', 'OVULO')->delete();
    Unidad::factory()->create(['codigo' => 'OVU', 'nombre' => 'OVULO']);

    migracionDeUnidadesDeLaLista()->up();

    expect(Unidad::query()->where('codigo', 'OVULO')->exists())->toBeFalse()
        ->and(Unidad::query()->where('codigo', 'OVU')->count())->toBe(1);
});

it('el seeder encuentra las que dejó la migración y no las repite', function (): void {
    $this->seed(UnidadesSeeder::class);

    foreach (array_keys(unidadesNuevasDeLaLista()) as $codigo) {
        expect(Unidad::query()->where('codigo', $codigo)->count())->toBe(1);
    }
});

it('existen todas las formas que ofrece la lista de productos', function (string $nombre): void {
    $this->seed(UnidadesSeeder::class);

    expect(Unidad::query()->where('nombre', $nombre)->exists())->toBeTrue("Falta la unidad {$nombre}");
})->with([
    // «Se dispensa en»
    'TABLETA', 'CÁPSULA', 'SOBRE', 'AMPOLLA', 'VIAL', 'FRASCO', 'MILILITRO', 'TUBO', 'GRAMO',
    'SUPOSITORIO', 'ÓVULO', 'PARCHE', 'BOLSA', 'UNIDAD', 'PAR', 'KIT', 'METRO', 'CENTÍMETRO',
    // «Viene en», las que no están arriba
    'CAJA', 'BLÍSTER', 'PAQUETE', 'ROLLO', 'GALÓN',
]);

it('al volver atrás borra solo las que nadie usa', function (): void {
    $tubo = Unidad::query()->where('codigo', 'TUBO')->firstOrFail();
    Item::factory()->medicamento()->create(['unidad_dispensacion_id' => $tubo->id]);

    migracionDeUnidadesDeLaLista()->down();

    expect(Unidad::query()->where('codigo', 'TUBO')->exists())->toBeTrue()
        ->and(Unidad::query()->where('codigo', 'PARCHE')->exists())->toBeFalse();
});
