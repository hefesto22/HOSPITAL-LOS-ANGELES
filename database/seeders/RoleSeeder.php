<?php

declare(strict_types=1);

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los once roles reales del hospital (§1.4).
 *
 * ⚠️ ESTE ARCHIVO ES LA ÚNICA FUENTE DE VERDAD DE LOS ROLES.
 * Nunca se crean ni se ajustan roles a mano en el panel: un permiso dado
 * por el panel no queda registrado en ninguna parte, no viaja al deploy y
 * nadie puede auditar por qué existe.
 *
 * Este seeder solo CREA los roles. Los permisos se asignan en
 * MatrizDePermisosSeeder, que corre después de que Shield haya generado
 * los permisos de cada Resource.
 *
 * La columna "qué NO puede" del §1.4 es la parte importante y es la que
 * protege el test tests/Feature/Seguridad/MatrizDeRolesTest.php.
 */
class RoleSeeder extends Seeder
{
    /**
     * Rol => para qué existe. El texto es documentación viva: si alguien
     * no sabe a qué rol asignar a una persona nueva, lee esto.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'direccion'   => 'Dirección / propietario: márgenes, costos, tarifarios, reportes y autorizaciones excepcionales. Cruza sedes.',
        'admision'    => 'Recepción: registra pacientes, abre encuentros, asigna cama, captura póliza y autorización. NO ve notas clínicas ni costos.',
        'caja'        => 'Cajera / facturación: cobra, emite factura y nota de crédito, aplica anticipos, liquida cuentas. NO ve expediente clínico ni edita precios.',
        'medico'      => 'Médico tratante: nota clínica, diagnóstico, órdenes, prescripción, informe. NO ve costos ni expedientes sin relación de atención.',
        'enfermeria'  => 'Enfermería: signos vitales, administración de medicamentos, notas, censo. NO prescribe ni factura.',
        'farmacia'    => 'Regente y auxiliares: dispensa, recibe, controla lotes, libro de controlados, reporte ARSA. NO prescribe.',
        'laboratorio' => 'Químico / técnico: recibe muestra, procesa, valida resultado, notifica valor crítico. NO modifica la orden médica ni ve costos.',
        'imagenes'    => 'Técnico radiólogo / radiólogo: ejecuta estudio, informa, adjunta enlace PACS. NO factura ni edita la orden.',
        'bodega'      => 'Almacén central: entradas, traslados, conteos, ajustes con motivo. NO vende, no dispensa a paciente, no ve expediente.',
        'auditoria'   => 'Auditoría médica y de privacidad: lee bitácoras, revisa break-the-glass, audita glosas. NO escribe en expediente ni factura.',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // super_admin es de Olympo (soporte), no del hospital.
        Role::firstOrCreate(
            ['name' => Utils::getSuperAdminName(), 'guard_name' => 'web'],
        );

        /*
         * `panel_user` se conserva por compatibilidad con Shield, pero ya
         * NO es la llave de entrada al panel: User::canAccessPanel() ahora
         * deja pasar a cualquier usuario activo CON rol. Sin eso, los diez
         * roles de abajo quedaban fuera del sistema.
         */
        Role::firstOrCreate(
            ['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web'],
        );

        foreach (array_keys(self::ROLES) as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        }

        $this->command?->info('✓ Roles del hospital listos: '.implode(', ', array_keys(self::ROLES)));
    }
}
