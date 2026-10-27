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
 * Los usuarios del hospital (Mauricio, 26-oct-2026): los mismos que ya
 * están en el servidor de pruebas, que son los que van a producción.
 *
 *   · Las cajeras, cada una con su nombre y su correo: Aida (turno A),
 *     Waldina y Ruth (turno B). El turno es solo el nombre que viene
 *     puesto al abrir la gaveta; quien cubre otro turno lo cambia ahí.
 *   · Laboratorio y consulta externa: todavía con un usuario por área y
 *     correo provisional (`@hospital.test`). Mauricio: «esos después se
 *     cambiarán». Cuando se cambien, el correo nuevo va en `email` y el
 *     de ahora en `antes`.
 *   · Angela Cardona y el Dr. Juan Carlos Cardona Medina:
 *     administración. Rol `direccion`, NO super_admin —el de soporte se
 *     salta las policies, y quien opera el hospital pasa por ellas como
 *     todo el mundo—.
 *
 * Los nombres van tal como están en el servidor: el seeder los escribe,
 * y uno distinto acá le cambiaría el nombre a alguien en el panel.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS MARCADORES QUE SE SEMBRARON POR ERROR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta el 26-oct-2026 la lista tenía cuentas de relleno
 * («Cajera Turno A», `laboratorio.hla@gmail.com`…). Correrla en pruebas
 * las creó al lado de las de verdad, con rol y con 12345678. Ahora cada
 * relleno está en el `antes` de una persona real: en pruebas, donde
 * existen los dos, el relleno queda dado de baja; en una base que solo
 * tiene el relleno —la de desarrollo—, el relleno pasa a ser esa persona
 * con su historia.
 *
 * NO está en `DatabaseSeeder` y se corre a mano:
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
     * Si ya existen LOS DOS —el nuevo y uno de `antes`—, se queda el
     * nuevo y el de `antes` se da de baja: sin roles, inactivo y con
     * borrado lógico, para que no se pueda entrar con él. No se borra la
     * fila: si alguna vez hizo algo, la bitácora sigue diciendo quién.
     *
     * El Dr. Juan Carlos Cardona Medina (26-oct-2026, Mauricio) era el
     * «Dr. Juan Cardona» de `juan.cardona@gmail.com` que se sembró de
     * marcador; su correo real es el de dirección del hospital.
     *
     * Angela entra con `angelacardona@gmail.com`, sin punto: es el que
     * tiene en el servidor de pruebas y el que se le mandó. El seeder
     * decía `angela.cardona@gmail.com` y, al correrlo el 26-oct-2026, le
     * creó una segunda cuenta de dirección con 12345678. Gmail no
     * distingue el punto; el sistema compara letra por letra.
     *
     * `telefono` null deja el que tenga: no se borra el que alguien cargó
     * en el panel.
     *
     * @var list<array{email: string, antes: list<string>, nombre: string, rol: string, turno: string|null, telefono: string|null}>
     */
    public const USUARIOS = [
        [
            'email'    => 'aidaminellyr@gmail.com',
            'antes'    => ['caja.turnoa@gmail.com'],
            'nombre'   => 'AIDA MINELLY RIVERA MONTALVAN',
            'rol'      => 'caja',
            'turno'    => 'Turno A',
            'telefono' => null,
        ],
        [
            'email'    => 'waldylisseth4@gmail.com',
            'antes'    => ['caja.turnob@gmail.com'],
            'nombre'   => 'WALDINA LISSETH LOPEZ PONCE',
            'rol'      => 'caja',
            'turno'    => 'Turno B',
            'telefono' => null,
        ],
        [
            'email'    => 'cruzarguetaruthajeleth@gmail.com',
            'antes'    => ['caja.turnoc@gmail.com'],
            'nombre'   => 'RUTH CRUZ',
            'rol'      => 'caja',
            'turno'    => 'Turno B',
            'telefono' => null,
        ],
        [
            'email'    => 'laboratorio@hospital.test',
            'antes'    => ['laboratorio.hla@gmail.com'],
            'nombre'   => 'LABORATORIO · PRUEBA',
            'rol'      => 'laboratorio',
            'turno'    => null,
            'telefono' => null,
        ],
        [
            'email'    => 'consulta@hospital.test',
            'antes'    => ['consulta.hla@gmail.com'],
            'nombre'   => 'CONSULTA EXTERNA · PRUEBA',
            'rol'      => 'consulta_externa',
            'turno'    => null,
            'telefono' => null,
        ],
        [
            'email'    => 'angelacardona@gmail.com',
            'antes'    => ['angela.cardona@gmail.com'],
            'nombre'   => 'Angela Cardona',
            'rol'      => 'direccion',
            'turno'    => 'Turno C',
            'telefono' => null,
        ],
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

            $this->darDeBajaLosAnteriores($usuario, $datos['antes']);
        }

        $this->command?->warn("Contraseña de los nuevos: {$contrasena}".($forzar ? ' (repuesta también en los que ya existían)' : ''));
        $this->command?->warn('⚠️  Son usuarios de PRUEBA. En el hospital real cada persona va con su propio usuario.');
    }

    /**
     * Si el correo nuevo ya existía y el viejo también, el viejo sobra:
     * se le quitan los roles, se desactiva y se da de baja (borrado
     * lógico). Así no queda una segunda llave de dirección con una
     * contraseña que todos conocen.
     *
     * @param list<string> $correos
     */
    private function darDeBajaLosAnteriores(User $vigente, array $correos): void
    {
        if ($correos === []) {
            return;
        }

        $sobrantes = User::withTrashed()
            ->whereIn('email', $correos)
            ->whereKeyNot($vigente->getKey())
            ->get();

        foreach ($sobrantes as $sobrante) {
            $sobrante->syncRoles([]);
            $sobrante->forceFill(['is_active' => false])->save();

            if (! $sobrante->trashed()) {
                $sobrante->delete();
            }

            $this->command?->warn("✗ Dado de baja {$sobrante->email}: era el correo anterior de {$vigente->email}.");
        }
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
