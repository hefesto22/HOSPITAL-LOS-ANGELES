<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Los usuarios para probar el hospital de punta a punta (12-oct-2026).
 *
 *   · Tres cajeras —turno A, B y C—: cobran y facturan, cada una con su
 *     turno abierto. El turno asignado es solo el nombre que viene
 *     puesto al abrir: la del A que hoy cubre el C abre el suyo y le
 *     pone «TURNO C».
 *   · Laboratorio: registra al paciente, abre la cuenta y carga SOLO
 *     exámenes. No cobra ni factura.
 *   · Consulta externa: lo mismo, pero consultas y procedimientos.
 *   · Angela Cardona y el Dr. Juan Carlos Cardona Medina:
 *     administración. Rol `direccion`, NO super_admin —el de soporte se
 *     salta las policies, y quien opera el hospital pasa por ellas como
 *     todo el mundo—.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ PARA PROBAR — NO ES LA CARGA DE USUARIOS REALES
 * ─────────────────────────────────────────────────────────────────────
 *
 * Contraseña conocida y cajeras por turno en vez de por persona. En el
 * hospital real cada quien va con su nombre (§1.4): cuando falten L 400,
 * el arqueo tiene que señalar a una persona, no a un turno.
 *
 * Por eso NO está en `DatabaseSeeder` y se corre a mano:
 *
 *     php artisan db:seed --class=UsuariosDePruebaSeeder
 *
 * Con `APP_ENV=production` se niega, salvo que el `.env` diga
 * `USUARIOS_DE_PRUEBA=true` —que es lo que se pone en el servidor de
 * pruebas y NUNCA en el del hospital—.
 *
 * La contraseña se escribe SOLO al crear el usuario: si alguien la
 * cambió desde el panel, correr esto de nuevo no lo deja afuera. Para
 * reponerlas: `USUARIOS_DE_PRUEBA_FORZAR_PASSWORD=true`.
 */
class UsuariosDePruebaSeeder extends Seeder
{
    private const CONTRASENA_POR_DEFECTO = '12345678';

    /**
     * Correo, nombre, rol, turno y teléfono.
     *
     * ─────────────────────────────────────────────────────────────────
     * CAMBIAR UN CORREO: `antes`
     * ─────────────────────────────────────────────────────────────────
     *
     * Si a alguien le cambia el correo, el nuevo va en `email` y el viejo
     * en `antes`. El seeder busca primero por el nuevo y, si no lo
     * encuentra, por los de `antes`: le cambia el correo al MISMO usuario,
     * con su historia, su contraseña y su rol. Sin esto quedaban dos: el
     * nuevo, y el viejo con rol de dirección y una contraseña conocida
     * que nadie usa — una puerta abierta.
     *
     * El Dr. Juan Carlos Cardona Medina (26-oct-2026, Mauricio) era el
     * «Dr. Juan Cardona» de `juan.cardona@gmail.com` que se sembró de
     * marcador; su correo real es el de dirección del hospital.
     *
     * `telefono` null deja el que tenga: no se borra el que alguien cargó
     * en el panel.
     *
     * @var list<array{email: string, antes: list<string>, nombre: string, rol: string, turno: string|null, telefono: string|null}>
     */
    public const USUARIOS = [
        ['email' => 'caja.turnoa@gmail.com', 'antes' => [], 'nombre' => 'Cajera Turno A', 'rol' => 'caja', 'turno' => 'Turno A', 'telefono' => null],
        ['email' => 'caja.turnob@gmail.com', 'antes' => [], 'nombre' => 'Cajera Turno B', 'rol' => 'caja', 'turno' => 'Turno B', 'telefono' => null],
        ['email' => 'caja.turnoc@gmail.com', 'antes' => [], 'nombre' => 'Cajera Turno C', 'rol' => 'caja', 'turno' => 'Turno C', 'telefono' => null],
        ['email' => 'laboratorio.hla@gmail.com', 'antes' => [], 'nombre' => 'Laboratorio', 'rol' => 'laboratorio', 'turno' => null, 'telefono' => null],
        ['email' => 'consulta.hla@gmail.com', 'antes' => [], 'nombre' => 'Consulta Externa', 'rol' => 'consulta_externa', 'turno' => null, 'telefono' => null],
        ['email' => 'angela.cardona@gmail.com', 'antes' => [], 'nombre' => 'Angela Cardona', 'rol' => 'direccion', 'turno' => null, 'telefono' => null],
        [
            'email'    => 'hladireccion@gmail.com',
            'antes'    => ['juan.cardona@gmail.com'],
            'nombre'   => 'Dr. Juan Carlos Cardona Medina',
            'rol'      => 'direccion',
            'turno'    => null,
            'telefono' => '9995-2663',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! (bool) env('USUARIOS_DE_PRUEBA', false)) {
            throw new RuntimeException(
                'UsuariosDePruebaSeeder no corre en producción: crea usuarios con contraseña conocida. '
                .'En el servidor de PRUEBAS poné USUARIOS_DE_PRUEBA=true en el .env; en el del hospital, nunca.'
            );
        }

        $contrasena = (string) env('USUARIOS_DE_PRUEBA_PASSWORD', self::CONTRASENA_POR_DEFECTO);
        $forzar = (bool) env('USUARIOS_DE_PRUEBA_FORZAR_PASSWORD', false);

        /*
         * La sede: los roles operativos la exigen. Sin ella la cajera
         * entra pero no puede abrir turno —el correlativo se pide por
         * sede— y el error en el mostrador es incomprensible.
         */
        $sede = Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            throw new RuntimeException('No hay ninguna sede. Corré `php artisan db:seed --class=SedeSeeder` primero.');
        }

        foreach (self::USUARIOS as $datos) {
            $usuario = User::withTrashed()->firstWhere('email', $datos['email'])
                ?? $this->porUnCorreoAnterior($datos['antes'])
                ?? new User;
            $esNuevo = ! $usuario->exists;

            $usuario->fill([
                'name'      => $datos['nombre'],
                'email'     => $datos['email'],
                'sede_id'   => $sede->id,
                'turno'     => $datos['turno'],
                'is_active' => true,
            ]);

            if ($datos['telefono'] !== null) {
                $usuario->setAttribute('phone', $datos['telefono']);
            }

            // Fuera del `fill`: no está en `$fillable`.
            if ($usuario->getAttribute('email_verified_at') === null) {
                $usuario->setAttribute('email_verified_at', now());
            }

            if ($esNuevo || $forzar) {
                $usuario->setAttribute('password', Hash::make($contrasena));
            }

            /*
             * Si estaba dado de baja, vuelve. A mano y no con `restore()`:
             * ese dispara un `save()` propio, y el modelo puede ser nuevo.
             */
            $usuario->setAttribute('deleted_at', null);
            $usuario->save();

            /*
             * `syncRoles`: reconcilia. Un rol agregado a mano en el panel
             * para «probar algo» se quita acá — la matriz manda (§1.4).
             */
            $usuario->syncRoles([$datos['rol']]);

            if ($usuario->hasRole(ShieldUtils::getSuperAdminName())) {
                throw new RuntimeException("{$datos['email']} quedó con super_admin. Ese rol es de soporte y se salta las policies.");
            }

            $this->command?->info(($esNuevo ? '✓ Creado ' : '✓ Al día  ')."{$datos['nombre']} <{$datos['email']}> — {$datos['rol']}");
        }

        $this->command?->warn("Contraseña de los nuevos: {$contrasena}".($forzar ? ' (repuesta también en los que ya existían)' : ''));
        $this->command?->warn('⚠️  Son usuarios de PRUEBA. En el hospital real cada persona va con su propio usuario.');
    }

    /**
     * El usuario que tenía uno de esos correos, si existe.
     *
     * @param list<string> $correos
     */
    private function porUnCorreoAnterior(array $correos): ?User
    {
        if ($correos === []) {
            return null;
        }

        return User::withTrashed()->whereIn('email', $correos)->orderBy('id')->first();
    }
}
