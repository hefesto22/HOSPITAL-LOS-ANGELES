<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Base de datos — SOLO PostgreSQL
|--------------------------------------------------------------------------
|
| Este archivo tenía las cinco conexiones del esqueleto de Laravel
| (sqlite, mysql, mariadb, pgsql, sqlsrv). Quedó solo pgsql, y es a
| propósito:
|
|  1. §7.1 es la regla de oro del proyecto: el motor y la versión mayor
|     son idénticos en desarrollo, pruebas, CI y producción. Una conexión
|     sqlite configurada y lista para usarse es la tentación exacta que
|     esa regla existe para eliminar — un test verde en SQLite que falla
|     en Postgres es peor que no tener test.
|
|  2. Con `DB_CONNECTION=mysql` mal puesto, antes Laravel se conectaba;
|     ahora falla con "Database connection [mysql] not configured". Un
|     error ruidoso vale más que una conexión silenciosa a la base
|     equivocada.
|
|  3. Los bloques mysql y mariadb traían un ternario de compatibilidad
|     `PHP_VERSION_ID >= 80500 ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA`
|     que en PHP 8.5 es código muerto — PHPStan nivel 7 lo marcaba como
|     comparación siempre verdadera. Se elimina la causa, no el aviso
|     (§9.B6: los errores no se tapan engordando el phpstan.neon).
|
| Si algún día hiciera falta una segunda conexión —réplica de lectura o
| pool separado de reportes vía PgBouncer (§13.5)— se agrega acá como
| otra entrada `pgsql_*`, nunca reactivando otro motor.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión por defecto
    |--------------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Conexiones
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5444'),
            'database'       => env('DB_DATABASE', 'hospital_los_angeles'),
            'username'       => env('DB_USERNAME', 'postgres'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => env('DB_CHARSET', 'utf8'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => env('DB_SCHEMA', 'public'),
            'sslmode'        => env('DB_SSLMODE', 'prefer'),

            /*
             * §7.5.2 — la base guarda UTC; la app convierte a Tegucigalpa.
             *
             * Esto emite SET TIME ZONE 'UTC' en CADA conexión, así que la
             * sesión queda en UTC aunque el servidor, el contenedor o la
             * variable PGTZ estén mal configurados. Sin esto, un servidor
             * en otra zona corre el censo de medianoche y el corte de caja
             * seis horas, y el error no se ve hasta que alguien cuadra el
             * mes.
             */
            'timezone' => 'UTC',

            /*
             * Aparece en pg_stat_activity y en los logs de Postgres: permite
             * saber qué aplicación tiene tomada una conexión o un lock.
             */
            'application_name' => env('APP_NAME', 'SIHLA'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tabla del repositorio de migraciones
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Cliente phpredis (extensión C) — ver la nota del .env. El prefijo es
    | explícito y propio del proyecto: este Redis convive con los de otros
    | sistemas de Mauricio y jamás debe compartir keyspace (§13.6).
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster'    => env('REDIS_CLUSTER', 'redis'),
            'prefix'     => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6391'),
            'database'          => env('REDIS_DB', '0'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6391'),
            'database'          => env('REDIS_CACHE_DB', '1'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
