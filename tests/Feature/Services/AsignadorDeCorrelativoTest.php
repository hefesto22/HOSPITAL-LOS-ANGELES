<?php

declare(strict_types=1);

use App\Domain\Enums\TipoCorrelativo;
use App\Models\Correlativo;
use App\Models\Sede;
use App\Services\AsignadorDeCorrelativo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Un correlativo repetido en un expediente clínico significa dos personas
 * distintas con el mismo número, y eso no se arregla después: se arrastra.
 *
 * El asignador no tiene estado, así que se resuelve con una función y no
 * con `$this->asignador` en un beforeEach — PHPStan no puede analizar
 * propiedades dinámicas sobre el `$this` de un closure de Pest, y no vale
 * la pena una exclusión para algo que se evita escribiéndolo mejor.
 */
function asignador(): AsignadorDeCorrelativo
{
    return new AsignadorDeCorrelativo;
}

it('arma el numero con el formato del parrafo 10.3', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    expect(asignador()->siguiente($sede, TipoCorrelativo::Encuentro))
        ->toBe('ENC-HLA-'.now()->year.'-000001');
});

it('omite el anio en las secuencias que no reinician', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    expect(asignador()->siguiente($sede, TipoCorrelativo::Expediente))
        ->toBe('EXP-HLA-00000001')
        ->and(TipoCorrelativo::Expediente->reiniciaAnualmente())->toBeFalse();
})->note('El expediente es la identidad del paciente en el hospital: reiniciarlo produciría dos pacientes con el mismo número en años distintos.');

it('nunca repite un numero', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    $emitidos = [];

    for ($i = 0; $i < 250; $i++) {
        $emitidos[] = asignador()->siguiente($sede, TipoCorrelativo::Expediente);
    }

    expect($emitidos)->toHaveCount(250)
        ->and(array_unique($emitidos))->toHaveCount(250);
});

it('entrega numeros contiguos, sin huecos, en operacion normal', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    for ($i = 1; $i <= 10; $i++) {
        expect(asignador()->siguiente($sede, TipoCorrelativo::Cuenta))
            ->toEndWith(str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    }
});

it('lleva contadores separados por sede', function (): void {
    $primera = Sede::factory()->create(['codigo' => 'HLA']);
    $segunda = Sede::factory()->create(['codigo' => 'HLA2']);

    asignador()->siguiente($primera, TipoCorrelativo::Expediente);
    asignador()->siguiente($primera, TipoCorrelativo::Expediente);

    expect(asignador()->siguiente($segunda, TipoCorrelativo::Expediente))
        ->toBe('EXP-HLA2-00000001');
})->note('Un contador global serializaría las dos sedes contra la misma fila: en emergencia, una sede esperando a la otra para abrir un expediente.');

it('lleva contadores separados por tipo', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    asignador()->siguiente($sede, TipoCorrelativo::OrdenLaboratorio);
    asignador()->siguiente($sede, TipoCorrelativo::OrdenLaboratorio);

    expect(asignador()->siguiente($sede, TipoCorrelativo::OrdenImagen))
        ->toBe('IMG-HLA-'.now()->year.'-000001');
})->note('Laboratorio sacando órdenes no debe bloquear a imágenes.');

it('toma el lock de la fila del contador', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    DB::enableQueryLog();
    asignador()->siguiente($sede, TipoCorrelativo::Expediente);
    $consultas = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($consultas)->toContain('for update');
})->note('Sin FOR UPDATE, dos transacciones leen el mismo valor y emiten el mismo número.');

it('no crea dos contadores para la misma sede, tipo y anio', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    asignador()->siguiente($sede, TipoCorrelativo::Expediente);
    asignador()->siguiente($sede, TipoCorrelativo::Expediente);

    expect(Correlativo::query()
        ->where('sede_id', $sede->id)
        ->where('tipo', TipoCorrelativo::Expediente->value)
        ->count())->toBe(1);
})->note('El índice único usa COALESCE(anio, 0): sin eso, NULL ≠ NULL permitiría varias filas de expediente por sede, cada una con su propio contador.');

it('la base impide crear un contador duplicado', function (): void {
    $sede = Sede::factory()->create();

    Correlativo::query()->create([
        'sede_id'       => $sede->id,
        'tipo'          => TipoCorrelativo::Expediente->value,
        'anio'          => null,
        'ultimo_numero' => 5,
    ]);

    Correlativo::query()->create([
        'sede_id'       => $sede->id,
        'tipo'          => TipoCorrelativo::Expediente->value,
        'anio'          => null,
        'ultimo_numero' => 0,
    ]);
})->throws(QueryException::class);

it('respeta la longitud declarada por cada tipo', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    expect(asignador()->siguiente($sede, TipoCorrelativo::Accession))
        ->toBe('ACC-HLA-0000000001');
})->note('DICOM exige unicidad global del accession number; el PACS lo usa como llave.');

it('informa cuantos numeros lleva consumidos', function (): void {
    $sede = Sede::factory()->create(['codigo' => 'HLA']);

    asignador()->siguiente($sede, TipoCorrelativo::Muestra);
    asignador()->siguiente($sede, TipoCorrelativo::Muestra);
    asignador()->siguiente($sede, TipoCorrelativo::Muestra);

    expect(asignador()->consumidos($sede, TipoCorrelativo::Muestra))->toBe(3);
});
