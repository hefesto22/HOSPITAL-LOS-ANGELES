<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Crea/actualiza el super-admin inicial del sistema y deja Shield
 * listo (genera permisos para todos los Resources, asigna super_admin).
 *
 * Las credenciales se leen de variables de entorno (§15.1) con
 * defaults clásicos memorables para desarrollo:
 *   ADMIN_EMAIL    (default: admin@gmail.com)
 *   ADMIN_PASSWORD (default en local: 12345678 — NUNCA en producción)
 *   ADMIN_NAME     (default: Administrador)
 *
 * En producción FALLA si ADMIN_PASSWORD está vacío. Esto fuerza al
 * deployer a setear una contraseña real antes del primer seed.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Credenciales por defecto SOLO para desarrollo local.
     * Memorables para acelerar el setup al clonar la plantilla.
     */
    private const DEFAULT_EMAIL = 'admin@gmail.com';

    private const DEFAULT_PASSWORD = '12345678';

    private const DEFAULT_NAME = 'Administrador';

    /**
     * Las contraseñas que este archivo y el `.env.example` reparten.
     *
     * ⚠️ El guard de «ADMIN_PASSWORD vacío» NO alcanzaba: el `.env` de
     * producción se arma copiando `.env.example`, que trae
     * `ADMIN_PASSWORD=12345678` escrito. Con eso la variable no está
     * vacía, el guard no dispara, y el hospital arranca con un
     * super-admin —el rol que se salta TODAS las policies— con la
     * contraseña que está publicada en el repo.
     *
     * @var list<string>
     */
    private const CONTRASENAS_QUEMADAS = ['12345678', 'password', 'secret', 'admin'];

    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', self::DEFAULT_EMAIL);
        $password = (string) env('ADMIN_PASSWORD', '');
        $nombre = (string) env('ADMIN_NAME', self::DEFAULT_NAME);

        if (app()->environment('production') && in_array($password, self::CONTRASENAS_QUEMADAS, true)) {
            throw new RuntimeException(
                "ADMIN_PASSWORD sigue siendo la de la plantilla ({$password}). Es una contraseña pública: está escrita en .env.example, "
                .'y este usuario es super_admin, que se salta las policies. Poné una contraseña real en el .env antes de sembrar producción.'
            );
        }

        if ($password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'ADMIN_PASSWORD no está definido. Define las credenciales del super-admin en .env antes de ejecutar este seeder en producción.'
                );
            }

            $password = self::DEFAULT_PASSWORD;
            $this->command?->warn("⚠️  ADMIN_PASSWORD vacío. Usando default de desarrollo: {$password}");
            $this->command?->warn('   Define ADMIN_PASSWORD en tu .env antes de deployar a producción.');
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $nombre,
                'password'          => Hash::make($password),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        // Asegura que el rol super_admin exista (lo crea RoleSeeder, pero
        // por si este seeder corre solo, hacemos un fallback defensivo).
        $admin->assignRole(Utils::getSuperAdminName());

        $this->command?->info("✓ Super-admin listo: {$email}");

        // ─── Filament Shield: generar permisos para todos los Resources ─────
        // Genera permisos con el formato de `config/filament-shield.php`:
        // separator ":" y case pascal, o sea `ViewAny:Item`, `Create:Convenio`.
        // Sin esto, los Resources NO aparecen en el sidebar (Shield los oculta).
        //
        // ⚠️ Genera SOLO los permisos, no las policies, y es a propósito: las
        // policies viven escritas a mano en `app/Policies/` porque llevan
        // reglas que Shield no puede saber —acá nada se borra, se cierra la
        // vigencia— y `--option=all` las sobreescribiría en cada seed.
        //
        // El precio de eso es que un Resource nuevo necesita su policy
        // escrita a mano. Y no es opcional: **sin policy, Filament no
        // deniega, permite** (ver `get_authorization_response()` en el
        // helper de Filament). El test `PoliticasDelCatalogoTest` falla si
        // algún modelo se queda sin la suya.
        $this->command?->info('Generando permisos de Shield para todos los Resources…');

        Artisan::call('shield:generate', [
            '--all'            => true,
            '--option'         => 'permissions',
            '--panel'          => 'admin',
            '--no-interaction' => true,
        ]);

        // ─── Sincroniza TODOS los permisos al rol super_admin ───────────────
        // shield:super-admin asigna el rol y sincroniza el set completo de
        // permisos generados, garantizando que el super-admin vea TODO.
        Artisan::call('shield:super-admin', [
            '--user' => $admin->id,
        ]);

        $this->command?->info('✓ Shield configurado. Super-admin tiene todos los permisos.');
    }
}
