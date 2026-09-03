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

            // 5. La administradora del hospital: la persona real que va a
            //    operar el sistema. Va después de la matriz porque su rol
            //    tiene que llegar ya con permisos —un usuario con un rol
            //    vacío entra al panel y no ve nada, y eso se diagnostica
            //    mal («no me funciona el sistema»)—. Es «direccion», NO
            //    super_admin: el rol de soporte se salta las policies, y
            //    quien opera el hospital todos los días tiene que pasar
            //    por ellas como todo el mundo.
            UsuarioDeDireccionSeeder::class,

            // 6. Vocabulario del catálogo. Va antes que cualquier ítem:
            //    un medicamento sin unidad de dispensación lo rechaza la
            //    base, no el formulario.
            UnidadesSeeder::class,

            // 7. Los porcentajes del Art. 30. De estos ocho numeros sale
            //    el precio de lista de todo el catalogo, asi que se
            //    siembran antes de que exista cualquier tarifario.
            DescuentosLegalesSeeder::class,

            // 8. El margen objetivo. Va después de los descuentos porque
            //    el precio de lista se deriva de los dos: el margen dice
            //    cuánto tiene que dejar el ítem y el descuento máximo
            //    dice desde qué precio hay que partir para que lo deje
            //    incluso con el paciente que menos paga (§4.5).
            MargenesObjetivoSeeder::class,

            // 9. El pagador que siempre existe. Va antes de cualquier
            //    tarifario porque el precio se resuelve SIEMPRE por
            //    convenio: si CONTADO no existiera, una cuenta sin seguro
            //    no tendría a quién cobrarle.
            ConveniosSeeder::class,

            // 10. El vocabulario clínico. No depende de nada del catálogo
            //     comercial —un diagnóstico no tiene precio— pero sí tiene
            //     que existir antes del primer encuentro: sin él, la cuenta
            //     no se le puede reclamar a una aseguradora y el Art. 180
            //     del Código de Salud queda sin con qué cumplirse.
            Cie10DeArranqueSeeder::class,

            // 11. Las especialidades médicas. Van antes del primer
            //     médico: con la lista vacía, quien registra al doctor
            //     inventa la especialidad desde el «crear al vuelo» y a
            //     la semana hay tres formas de escribir «cirugía».
            EspecialidadesSeeder::class,

            BrandingSettingSeeder::class,
        ]);
    }
}
