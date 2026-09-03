<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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

/*
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ACÁ VIVÍA EL TEST QUE SOSTUVO EL BUG DE ZONA HORARIA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La versión anterior afirmaba `current_setting('TimeZone') === 'UTC'` y
 * lo rotulaba «la base guarda UTC». Estaba verde, y estaba equivocado:
 * confundía CÓMO SE ALMACENA un timestamptz con QUÉ ZONA HABLA LA SESIÓN.
 *
 * `timestamptz` guarda SIEMPRE el instante absoluto en UTC — eso no es
 * configurable. La zona de la sesión decide otra cosa: cómo se interpreta
 * un literal SIN offset que entra, y cómo se imprime uno que sale. Y
 * Laravel manda justamente literales sin offset, en la zona de la app.
 *
 * Con la sesión en UTC, «2026-08-19 01:45:00» (01:45 de Tegucigalpa)
 * entraba como 01:45 UTC: seis horas antes del instante real. El test
 * pasaba porque medía la sesión, no el instante.
 *
 * Por eso ahora se mide EL INSTANTE. Un test de zona horaria que no
 * compara epochs no está probando nada.
 */
it('deja la sesión de PostgreSQL en la zona de la aplicación', function (): void {
    /** @var object{timezone: string} $fila */
    $fila = DB::selectOne("SELECT current_setting('TimeZone') AS timezone");

    expect($fila->timezone)->toBe(config('app.timezone'))
        ->and(config('database.connections.pgsql.timezone'))->toBe(config('app.timezone'));
})->note('La sesión NUNCA queda a merced de la TZ del servidor (§7.5-1).');

it('guarda el instante que ocurrió, no la hora de pared etiquetada mal', function (): void {
    $instante = CarbonImmutable::parse('2026-08-19 01:45:00', 'America/Tegucigalpa');

    DB::statement('CREATE TEMPORARY TABLE sihla_prueba_zona (cuando timestamptz NOT NULL)');

    /*
     * Exactamente como serializa Eloquent: getDateFormat() = 'Y-m-d
     * H:i:s', sin offset, en la zona de la app. Si esto se rompe, se
     * rompe para toda columna timestamptz del sistema.
     */
    DB::table('sihla_prueba_zona')->insert([
        'cuando' => $instante->format('Y-m-d H:i:s'),
    ]);

    /*
     * EXTRACT(EPOCH ...) es independiente de la zona de la sesión: es el
     * instante absoluto y nada más. Es la única comparación que no se
     * puede engañar cambiando una configuración.
     */
    /** @var object{epoca: string} $fila */
    $fila = DB::selectOne('SELECT EXTRACT(EPOCH FROM cuando)::bigint AS epoca FROM sihla_prueba_zona');

    expect((int) $fila->epoca)->toBe(
        $instante->getTimestamp(),
        'El literal que manda Laravel se está interpretando en otra zona: toda columna timestamptz queda corrida.'
    );
})->note('El bug de las seis horas del bloque 5d-1 falla acá y en ningún otro lado.');
