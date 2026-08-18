<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. La sede va primero: todo lo transaccional cuelga de ella
            //    (ADR-0002) y el administrador se le asigna.
            SedeSeeder::class,

            // 2. Crea los once roles del §1.4, todavía sin permisos.
            RoleSeeder::class,

            // 3. Crea el super-admin y —lo importante— ejecuta
            //    shield:generate, que es lo que CREA los permisos de cada
            //    Resource. Antes de este paso no hay nada que asignar.
            AdminUserSeeder::class,

            // 4. Recién ahora se puede aplicar la matriz de permisos.
            MatrizDePermisosSeeder::class,

            // 5. Vocabulario del catálogo. Va antes que cualquier ítem:
            //    un medicamento sin unidad de dispensación lo rechaza la
            //    base, no el formulario.
            UnidadesSeeder::class,

            // 6. Los porcentajes del Art. 30. De estos ocho numeros sale
            //    el precio de lista de todo el catalogo, asi que se
            //    siembran antes de que exista cualquier tarifario.
            DescuentosLegalesSeeder::class,

            BrandingSettingSeeder::class,
        ]);
    }
}
