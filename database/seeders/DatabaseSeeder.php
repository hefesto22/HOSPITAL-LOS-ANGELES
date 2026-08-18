<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // La sede va PRIMERO: todo lo transaccional cuelga de ella
            // (ADR-0002) y el usuario administrador se le asigna.
            SedeSeeder::class,

            RoleSeeder::class,
            AdminUserSeeder::class,
            BrandingSettingSeeder::class,
        ]);
    }
}
