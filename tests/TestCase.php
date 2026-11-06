<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 🔴 La suite se niega a correr si la base no es de pruebas, ANTES de que RefreshDatabase toque nada.
     *
     * (6-nov-2026) Un `composer update` y un `composer ci` pegados en la terminal del
     * servidor de producción corrieron la suite con el .env real: `composer test` borra
     * la caché de configuración, en el servidor no hay .env.testing, y Laravel cayó al
     * .env de producción. Lo único que salvó la base del hospital fue que su rol no
     * puede crear bases y `--parallel` reventó al crear la copia de pruebas. Sin
     * `--parallel`, RefreshDatabase habría corrido migrate:fresh sobre la base real.
     *
     * El guardia de EntornoTest («no apunta a la base de desarrollo») llega tarde:
     * corre después de RefreshDatabase. Este corre al crear la aplicación, antes de
     * los traits del test.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        /** @var Repository $config */
        $config = $app->make('config');

        $conexion = (string) $config->get('database.default');
        $base = (string) $config->get("database.connections.{$conexion}.database");

        if ($app->environment('production') || preg_match('/_test(_\d+)?$/', $base) !== 1) {
            throw new RuntimeException(
                "La suite no corre contra «{$base}»: la base de pruebas termina en _test "
                .'y RefreshDatabase vacía la que encuentra. Los tests van en la Mac o en CI, '
                .'con .env.testing; nunca en el servidor.'
            );
        }

        return $app;
    }
}
