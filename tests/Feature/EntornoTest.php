<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * TESTS GUARDIA DEL ENTORNO (§7.1).
 *
 * No prueban dominio: protegen los supuestos sobre los que se apoya TODO
 * el dominio. Si alguien "arregla" los tests apuntándolos a SQLite para
 * que corran más rápido, la suite entera se pone roja acá y no en el
 * módulo de facturación seis meses después.
 *
 * Un test verde en SQLite que falla en Postgres es peor que no tener
 * test: da confianza falsa sobre CHECK constraints, índices parciales,
 * únicos con COALESCE, JSONB, CTE, FOR UPDATE y EXCLUDE USING gist.
 */
it('corre los tests contra PostgreSQL, nunca SQLite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('corre contra PostgreSQL 18 o superior', function (): void {
    /** @var string $version */
    $version = DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

    $major = (int) explode('.', $version)[0];

    expect($major)->toBeGreaterThanOrEqual(
        18,
        "La base de tests corre PostgreSQL {$version}. El proyecto exige 18+: "
        .'uuidv7() nativo y restricciones temporales WITHOUT OVERLAPS son parte del diseño.'
    );
});

it('tiene las extensiones que el dominio da por sentadas', function (): void {
    /** @var list<string> $instaladas */
    $instaladas = DB::table('pg_extension')->pluck('extname')->all();

    expect($instaladas)->toContain('pg_trgm')
        ->and($instaladas)->toContain('btree_gist');
});

it('no apunta a la base de desarrollo', function (): void {
    /** @var string $base */
    $base = (string) DB::connection()->getDatabaseName();

    expect($base)->toStartWith('hospital_los_angeles_test');
})->note('RefreshDatabase trunca tablas. Si la suite apuntara a la base de dev, la vacía.');

it('usa la zona horaria de Honduras', function (): void {
    expect(config('app.timezone'))->toBe('America/Tegucigalpa');
});

it('guarda las fechas en UTC en la base', function (): void {
    /** @var object{timezone: string} $fila */
    $fila = DB::selectOne("SELECT current_setting('TimeZone') AS timezone");

    expect($fila->timezone)->toBe('UTC');
})->note('La app convierte a Tegucigalpa; la base guarda UTC (§7.5.2).');
