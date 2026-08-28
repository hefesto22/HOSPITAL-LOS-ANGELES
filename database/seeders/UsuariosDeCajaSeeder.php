<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Los tres turnos de caja, para poder probar el módulo de plata.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTO ES PARA PROBAR, NO PARA PRODUCCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El §1.4 lo dice sin ambigüedad: **ningún usuario compartido**. Un
 * usuario «caja_a» que usan tres personas distintas en tres semanas
 * rompe justo lo que el arqueo existe para dar — cuando falten L 400,
 * el sistema va a poder decir en qué turno pasó, pero no quién tenía la
 * gaveta. Y el turno de caja es de una PERSONA precisamente por eso.
 *
 * En el hospital real, cada cajera tiene su usuario con su nombre, y el
 * «turno A» es el nombre que ella le pone al turno cuando lo abre —ese
 * campo existe para eso—.
 *
 * Por eso este seeder:
 *
 *   · NO está en `DatabaseSeeder`. Se corre a mano cuando se quiere
 *     probar: `php artisan db:seed --class=UsuariosDeCajaSeeder`.
 *   · **Se niega a correr en producción.** No es una advertencia que se
 *     ignora: tira excepción.
 *
 * Contraseña: `CAJA_PASSWORD` del `.env`, o `12345678` en local.
 */
class UsuariosDeCajaSeeder extends Seeder
{
    private const CONTRASENA_LOCAL = '12345678';

    /**
     * @var list<array{turno: string, email: string, nombre: string}>
     */
    private const CAJEROS = [
        ['turno' => 'A', 'email' => 'caja.a@hospital.test', 'nombre' => 'CAJA · TURNO A'],
        ['turno' => 'B', 'email' => 'caja.b@hospital.test', 'nombre' => 'CAJA · TURNO B'],
        ['turno' => 'C', 'email' => 'caja.c@hospital.test', 'nombre' => 'CAJA · TURNO C'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'UsuariosDeCajaSeeder no corre en producción: crea usuarios de turno compartidos con contraseña conocida. '
                .'En el hospital real, cada cajera se da de alta con su propio usuario desde «Usuarios» (§1.4).'
            );
        }

        $contrasena = (string) env('CAJA_PASSWORD', self::CONTRASENA_LOCAL);

        /*
         * La sede: los roles operativos SÍ la exigen. Sin ella, la cajera
         * entra pero no puede abrir turno —el correlativo se pide por
         * sede— y el error sería incomprensible en el mostrador.
         */
        $sede = Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            throw new RuntimeException('No hay ninguna sede. Corré `php artisan db:seed --class=SedeSeeder` primero.');
        }

        foreach (self::CAJEROS as $cajero) {
            $usuario = User::updateOrCreate(
                ['email' => $cajero['email']],
                [
                    'name'              => $cajero['nombre'],
                    'password'          => Hash::make($contrasena),
                    'sede_id'           => $sede->id,
                    'is_active'         => true,
                    'email_verified_at' => now(),
                ],
            );

            /*
             * `syncRoles` y no `assignRole`: reconcilia. Si alguien le
             * agregó un rol a mano en el panel para «probar algo», acá
             * se le quita. La matriz manda (§1.4).
             */
            $usuario->syncRoles(['caja']);

            $this->command?->info("✓ {$cajero['nombre']} — {$cajero['email']}");
        }

        $this->command?->warn("Contraseña de los tres: {$contrasena}");
        $this->command?->warn('⚠️  Son usuarios de PRUEBA. En producción cada cajera va con su propio nombre: el arqueo señala a una persona, no a un turno.');
    }
}
