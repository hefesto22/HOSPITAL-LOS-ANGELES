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
 * La administradora del hospital: la persona real que opera el sistema.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ADMINISTRADORA NO ES SUPER-ADMIN, Y LA DIFERENCIA IMPORTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `super_admin` es de Olympo (soporte). No es «el jefe»: es una llave de
 * mantenimiento. Shield le monta un `Gate::before` que devuelve `true`
 * ANTES de consultar cualquier policy, así que ese rol **se salta las
 * policies escritas a mano** — las que niegan borrar (acá nada se borra,
 * se cierra la vigencia), las de break-the-glass del expediente y las de
 * inmutabilidad. Un usuario del hospital con ese rol no deja rastro de
 * haber pasado por encima de una regla, porque para él la regla no
 * existió.
 *
 * `direccion` es el rol de la administración del hospital y es lo que le
 * corresponde a quien dirige: tiene TODOS los permisos de la matriz
 * (§1.4, `'*' => ['*']`) y cruza sedes, pero **pasa por las policies como
 * todo el mundo**. Puede hacer su trabajo completo; lo que no puede es
 * romper una invariante sin que quede escrito.
 *
 * Por eso este seeder no solo asigna `direccion`: si alguien le puso
 * `super_admin` a mano en el panel, se lo QUITA. La decisión vive acá,
 * en el repo, y no en lo que alguien recuerde haber hecho un martes.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO PISA LA CONTRASEÑA AL RE-SEMBRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * `AdminUserSeeder` usa `updateOrCreate` y re-escribe el hash en cada
 * corrida. Para el usuario de soporte da igual. Para el de ella no: el
 * día que cambie su contraseña desde el panel y alguien corra
 * `db:seed` en el deploy, la contraseña vuelve a la del `.env` y ella
 * queda afuera de su propio sistema sin entender por qué.
 *
 * Acá la contraseña se escribe **solo al crear**. Para reponerla hay un
 * gesto explícito: `DIRECCION_FORZAR_PASSWORD=true`.
 *
 * Variables (§15.1):
 *   DIRECCION_EMAIL     (default: angela.cardona@hospitallosangeles.hn)
 *   DIRECCION_NAME      (default: Angela Cardona)
 *   DIRECCION_PASSWORD  (obligatoria en producción)
 *   DIRECCION_FORZAR_PASSWORD  (default: false)
 */
class UsuarioDeDireccionSeeder extends Seeder
{
    public const ROL = 'direccion';

    private const EMAIL_POR_DEFECTO = 'angela.cardona@hospitallosangeles.hn';

    private const NOMBRE_POR_DEFECTO = 'Angela Cardona';

    private const CONTRASENA_LOCAL = '12345678';

    public function run(): void
    {
        $email = mb_strtolower(trim((string) env('DIRECCION_EMAIL', self::EMAIL_POR_DEFECTO)));
        $nombre = (string) env('DIRECCION_NAME', self::NOMBRE_POR_DEFECTO);
        $contrasena = (string) env('DIRECCION_PASSWORD', '');

        if ($contrasena === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'DIRECCION_PASSWORD no está definida. La administradora del hospital no se crea con una contraseña adivinable: '
                    .'definila en el .env antes del primer seed de producción.'
                );
            }

            $contrasena = self::CONTRASENA_LOCAL;
            $this->command?->warn("⚠️  DIRECCION_PASSWORD vacía. Usando default de desarrollo: {$contrasena}");
        }

        $usuario = User::withTrashed()->firstWhere('email', $email) ?? new User;
        $esNueva = ! $usuario->exists;

        $usuario->fill([
            'name'              => $nombre,
            'email'             => $email,
            'is_active'         => true,
            'sede_id'           => $this->sedeUnica(),
            'email_verified_at' => $usuario->getAttribute('email_verified_at') ?? now(),
        ]);

        if ($esNueva || (bool) env('DIRECCION_FORZAR_PASSWORD', false)) {
            $usuario->setAttribute('password', Hash::make($contrasena));
        }

        /*
         * `deleted_at = null` a mano en vez de `restore()`: restore()
         * dispara un save() propio y acá el modelo puede ser nuevo, así
         * que se guardaría dos veces —o antes de tiempo—. Se resuelve la
         * baja lógica como un atributo más y se graba UNA sola vez.
         */
        $usuario->setAttribute('deleted_at', null);

        $usuario->save();

        /*
         * `syncRoles` y no `assignRole`: reconcilia contra este archivo.
         * Es lo que le quita `super_admin` si alguien se lo puso a mano
         * —el punto entero del comentario de arriba— y también lo que
         * evita que se le vayan acumulando roles operativos sueltos.
         */
        $usuario->syncRoles([self::ROL]);

        $this->command?->info(
            ($esNueva ? '✓ Administradora creada: ' : '✓ Administradora al día: ')
            ."{$nombre} <{$email}> — rol «".self::ROL.'», sin super_admin.'
        );

        if ($usuario->hasRole(ShieldUtils::getSuperAdminName())) {
            throw new RuntimeException(
                'La administradora quedó con super_admin. Ese rol se salta las policies y no puede ser el de un usuario del hospital.'
            );
        }
    }

    /**
     * La sede, cuando hay UNA sola.
     *
     * `direccion` cruza sedes y por eso `sede_id` es nullable para ella
     * (§9.L5): `ContextoSede` resuelve la única sede vigente cuando el
     * usuario no tiene una propia. Se la asignamos igual porque le da un
     * default estable —el turno de caja y el correlativo se piden por
     * sede— y no le quita alcance: `ContextoSede::veTodas()` deja pasar a
     * `direccion` a cualquier sede sin mirar esta columna.
     *
     * Con dos sedes esto devuelve null a propósito: ahí ya no hay default
     * obvio y adivinar es como se termina facturando en la sede que no es.
     */
    private function sedeUnica(): ?int
    {
        /** @var list<int> $vigentes */
        $vigentes = Sede::query()
            ->vigentesEn(now())
            ->limit(2)
            ->pluck('id')
            ->all();

        return count($vigentes) === 1 ? $vigentes[0] : null;
    }
}
