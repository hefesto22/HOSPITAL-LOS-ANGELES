<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Qué puede hacer cada rol — la matriz del §1.4.
 *
 * Corre DESPUÉS de AdminUserSeeder, porque es ahí donde `shield:generate`
 * crea los permisos de cada Resource. Antes de eso no hay qué asignar.
 *
 * ⚠️ CÓMO ESTÁ CONSTRUIDA, Y POR QUÉ ASÍ:
 *
 * La matriz no nombra permisos exactos (`view_any_user`), sino **recursos
 * por palabra clave**. Shield deriva el nombre del permiso del Resource,
 * y ese nombre cambia cuando se renombra una clase. Atarse al nombre
 * exacto produce un seeder que "corre bien" mientras deja roles sin
 * permisos, en silencio.
 *
 * Todo lo que la matriz NO otorga, queda denegado. Es allowlist, nunca
 * denylist: un permiso nuevo de un módulo futuro no se le concede a nadie
 * por descuido, hay que agregarlo acá a propósito.
 *
 * `syncPermissions` es deliberado: reconcilia contra este archivo y borra
 * lo que alguien haya agregado a mano en el panel. Ese es el punto (§1.4).
 */
class MatrizDePermisosSeeder extends Seeder
{
    /**
     * Rol => recursos que puede tocar, con las acciones permitidas.
     *
     * La clave del recurso es una PALABRA CLAVE que se busca dentro del
     * nombre del permiso. Las acciones son los prefijos de Shield.
     *
     * Hoy solo existen los Resources de usuarios, roles y bitácora. Los
     * recursos de los módulos que faltan se agregan acá conforme se
     * construyen — este archivo crece, no se reescribe.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const MATRIZ = [
        // Dirección ve y hace todo. §1.4 columna "qué NO puede": —
        'direccion' => ['*' => ['*']],

        /*
         * Auditoría es de SOLO LECTURA, y es el único rol operativo que
         * puede leer la bitácora. Que pueda escribir en expediente o
         * facturar destruiría su función: quien audita no puede ser parte
         * de lo auditado.
         */
        'auditoria' => [
            'activity' => ['view_any', 'view'],
            'user'     => ['view_any', 'view'],
        ],

        /*
         * Los roles operativos todavía no tienen Resources propios. Sus
         * permisos aparecen cuando se construya su módulo:
         *   admision    → pacientes, encuentros, camas
         *   caja        → cuentas, facturas, notas de crédito, cierre
         *   medico      → notas, órdenes, prescripción
         *   enfermeria  → signos vitales, MAR, censo
         *   farmacia    → dispensación, lotes, controlados
         *   laboratorio → órdenes, muestras, resultados
         *   imagenes    → estudios, informes
         *   bodega      → entradas, traslados, conteos, ajustes
         *
         * Entran al panel igual (User::canAccessPanel), pero no ven nada
         * hasta que su módulo exista. Eso es correcto: mejor un panel
         * vacío que un permiso concedido por descuido.
         */
        'admision'    => [],
        'caja'        => [],
        'medico'      => [],
        'enfermeria'  => [],
        'farmacia'    => [],
        'laboratorio' => [],
        'imagenes'    => [],
        'bodega'      => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var Collection<int, string> $todos */
        $todos = Permission::query()->where('guard_name', 'web')->pluck('name');

        foreach (self::MATRIZ as $nombreRol => $recursos) {
            $rol = Role::query()
                ->where('name', $nombreRol)
                ->where('guard_name', 'web')
                ->first();

            if (! $rol instanceof Role) {
                $this->command?->warn("  ⚠ Rol {$nombreRol} no existe todavía; corré RoleSeeder primero.");

                continue;
            }

            $rol->syncPermissions($this->resolver($todos, $recursos)->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✓ Matriz de permisos aplicada a los roles del hospital.');
        $this->command?->comment('  Los roles operativos quedan sin permisos hasta que exista su módulo.');
    }

    /**
     * Traduce la matriz a nombres de permiso reales.
     *
     * @param Collection<int, string> $todos
     * @param array<string, list<string>> $recursos
     *
     * @return Collection<int, string>
     */
    private function resolver(Collection $todos, array $recursos): Collection
    {
        if ($recursos === []) {
            return collect();
        }

        if (isset($recursos['*'])) {
            return $todos;
        }

        return $todos->filter(function (string $permiso) use ($recursos): bool {
            foreach ($recursos as $recurso => $acciones) {
                if (! str_contains($permiso, $recurso)) {
                    continue;
                }

                foreach ($acciones as $accion) {
                    if ($accion === '*' || str_starts_with($permiso, $accion.'_')) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }
}
