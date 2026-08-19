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
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LOS NOMBRES SON EXACTOS Y NO PALABRAS CLAVE
 * ─────────────────────────────────────────────────────────────────────
 *
 * La primera versión de este archivo buscaba **por substring en
 * minúsculas** —`str_contains($permiso, 'item')`— y con prefijos tipo
 * `view_any_`, para no atarse al nombre exacto que genera Shield.
 *
 * Falló en silencio durante todo el bloque 3. Shield está configurado con
 * `separator: ':'` y `case: 'pascal'` (ver `config/filament-shield.php`),
 * así que los permisos reales se llaman **`ViewAny:Item`**, y ni
 * `str_contains('ViewAny:Item', 'item')` ni
 * `str_starts_with('ViewAny:Item', 'view_any_')` son verdaderos. Resultado:
 * todos los roles menos `direccion` —que usa el comodín— quedaron con CERO
 * permisos, y los tests no lo vieron porque creaban a mano permisos con
 * nombres que Shield nunca genera.
 *
 * Ahora los nombres son exactos. El riesgo de atarse al nombre exacto —que
 * alguien renombre un Resource y esto deje de conceder en silencio— se
 * resuelve al revés de como se intentó antes: **el seeder valida que cada
 * permiso declarado exista de verdad** y grita si no, y hay un test que
 * falla si la matriz nombra algo que ningún Resource produce.
 *
 * Adivinar es peor que romperse fuerte.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ALLOWLIST, SIEMPRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo lo que la matriz NO otorga queda denegado. Un permiso nuevo de un
 * módulo futuro no se le concede a nadie por descuido: hay que agregarlo
 * acá a propósito.
 *
 * `syncPermissions` es deliberado: reconcilia contra este archivo y borra
 * lo que alguien haya agregado a mano en el panel. Ese es el punto (§1.4).
 */
class MatrizDePermisosSeeder extends Seeder
{
    /**
     * Los afijos que Shield genera, en el orden del `config`.
     *
     * Están acá para que el test pueda verificar que la matriz no inventa
     * acciones. `Delete`, `ForceDelete` y `ForceDeleteAny` existen como
     * permiso pero no se le conceden a nadie: en este sistema nada se
     * borra, se cierra la vigencia. Las policies además los niegan.
     *
     * @var list<string>
     */
    public const ACCIONES = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore',
        'ForceDelete', 'ForceDeleteAny', 'RestoreAny', 'Replicate', 'Reorder',
    ];

    /**
     * Rol => sujeto del permiso, con las acciones concedidas.
     *
     * El sujeto es el nombre del MODELO en PascalCase, tal como lo arma
     * Shield: el Resource de pacientes tiene modelo `Persona`, así que el
     * permiso es `ViewAny:Persona` y no `ViewAny:Paciente`.
     *
     * Los recursos de los módulos que faltan se agregan acá conforme se
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
         *
         * Lee también el margen objetivo y los convenios porque son las
         * dos mitades de la explicación de cada precio: sin verlos, un
         * hallazgo sobre lo que se le cobró a un paciente no se cierra.
         */
        'auditoria' => [
            'Activity'        => ['ViewAny', 'View'],
            'User'            => ['ViewAny', 'View'],
            'FusionDePersona' => ['ViewAny', 'View'],
            'Item'            => ['ViewAny', 'View'],
            'Unidad'          => ['ViewAny', 'View'],
            'MargenObjetivo'  => ['ViewAny', 'View'],
            'Convenio'        => ['ViewAny', 'View'],

            /*
             * Las compras también, y es de lo primero que mira una
             * auditoría: qué entró, con qué papel, quién lo cargó y
             * quién lo confirmó. Sin verlas no se puede cerrar ningún
             * hallazgo de inventario.
             */
            'Proveedor' => ['ViewAny', 'View'],
            'Compra'    => ['ViewAny', 'View'],
            'Recepcion' => ['ViewAny', 'View'],
        ],

        /*
         * PACIENTES — registran admisión Y enfermería. La segunda no es
         * una concesión: si solo admisión pudiera, la enfermera de turno
         * que recibe a un accidentado a las 3 de la mañana terminaría
         * usando la clave de otro, y la bitácora dejaría de servir para lo
         * único que existe: saber quién hizo qué.
         *
         * El resto de los roles clínicos y caja solo LEEN. Bodega no
         * aparece: nunca ve expediente (§1.4).
         *
         * `FusionDePersona` incluye aprobar y deshacer, y eso NO
         * contradice el control de cuatro ojos: la base impide que apruebe
         * quien propuso, así que dos personas de admisión se aprueban
         * entre sí. Si solo dirección pudiera, a las 3 de la mañana no
         * habría quien apruebe y el duplicado seguiría vivo.
         *
         * CATÁLOGO — lo lee todo el mundo, lo escribe dirección. Farmacia,
         * laboratorio, imágenes, quirófano y caja cobran contra el mismo
         * catálogo. Crearlo es otra cosa: al dar de alta un ítem se fija su
         * régimen de ISV y bajo qué numeral del Art. 30 cae su descuento.
         * Equivocarse en cualquiera de los dos es un hallazgo del SAR o una
         * denuncia a la línea 115.
         *
         * CONVENIOS — se LEEN en admisión y en caja: la primera elige el
         * pagador al ingreso, la segunda factura contra él. Crearlos es de
         * dirección, porque dar de alta un convenio incluye declarar sobre
         * qué monto se aplica el descuento del Art. 30, y esa es una
         * decisión con respaldo legal, no del turno.
         */
        'admision' => [
            'Persona'         => ['ViewAny', 'View', 'Create', 'Update'],
            'FusionDePersona' => ['ViewAny', 'View', 'Create', 'Update'],
            'Item'            => ['ViewAny', 'View'],
            'Unidad'          => ['ViewAny', 'View'],
            'Convenio'        => ['ViewAny', 'View'],
        ],

        'enfermeria' => [
            'Persona'         => ['ViewAny', 'View', 'Create', 'Update'],
            'FusionDePersona' => ['ViewAny', 'View', 'Create'],
            'Item'            => ['ViewAny', 'View'],
            'Unidad'          => ['ViewAny', 'View'],
        ],

        'medico' => [
            'Persona' => ['ViewAny', 'View'],
            'Item'    => ['ViewAny', 'View'],
            'Unidad'  => ['ViewAny', 'View'],
        ],

        'caja' => [
            'Persona'  => ['ViewAny', 'View'],
            'Item'     => ['ViewAny', 'View'],
            'Unidad'   => ['ViewAny', 'View'],
            'Convenio' => ['ViewAny', 'View'],
        ],

        /*
         * Farmacia LEE las compras sin poder cargarlas: es la respuesta a
         * «¿ya llegó el pedido?», que hoy se contesta caminando hasta
         * bodega. Cargarlas y confirmarlas es de bodega, que es quien
         * recibe físicamente.
         */
        'farmacia' => [
            'Persona'   => ['ViewAny', 'View'],
            'Item'      => ['ViewAny', 'View'],
            'Unidad'    => ['ViewAny', 'View'],
            'Proveedor' => ['ViewAny', 'View'],
            'Compra'    => ['ViewAny', 'View'],
            'Recepcion' => ['ViewAny', 'View'],
        ],

        'laboratorio' => [
            'Persona' => ['ViewAny', 'View'],
            'Item'    => ['ViewAny', 'View'],
            'Unidad'  => ['ViewAny', 'View'],
        ],

        'imagenes' => [
            'Persona' => ['ViewAny', 'View'],
            'Item'    => ['ViewAny', 'View'],
            'Unidad'  => ['ViewAny', 'View'],
        ],

        /*
         * Bodega LEE el catálogo pero no lo escribe, y no ve expediente:
         * quien recibe una compra no tiene por qué saber quién está
         * internado.
         *
         * ─────────────────────────────────────────────────────────────
         * `Update:Recepcion` ES TAMBIÉN EL PERMISO DE MARCAR REVISADA
         * ─────────────────────────────────────────────────────────────
         *
         * Shield genera once acciones fijas y ninguna se llama «revisar»,
         * así que la revisión viaja en `Update`. Eso NO deja el control
         * en manos del permiso: la base impide con un CHECK que
         * `revisada_por` sea el mismo que `created_by`, así que dos
         * personas de bodega se revisan entre sí — exactamente como dos
         * de admisión se aprueban las fusiones.
         *
         * ─────────────────────────────────────────────────────────────
         * BODEGA NO VE COMPRAS, Y NO ES DESCONFIANZA
         * ─────────────────────────────────────────────────────────────
         *
         * `Compra` es el registro FISCAL: qué facturó el proveedor, con
         * cuánto ISV, en qué se gastó. Quien recibe mercadería no
         * necesita saber a qué precio se negoció ni cuánto se le paga a
         * cada proveedor, y una lista de gastos del hospital circulando
         * por bodega es exactamente la clase de dato que se filtra.
         *
         * El proveedor sí lo da de alta bodega: es quien lo conoce, y a
         * diferencia de un convenio, darlo de alta no declara nada legal
         * —solo un nombre y un RTN—.
         */
        'bodega' => [
            'Item'      => ['ViewAny', 'View'],
            'Unidad'    => ['ViewAny', 'View'],
            'Almacen'   => ['ViewAny', 'View'],
            'Proveedor' => ['ViewAny', 'View', 'Create', 'Update'],
            'Recepcion' => ['ViewAny', 'View', 'Create', 'Update'],
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var Collection<int, string> $todos */
        $todos = Permission::query()->where('guard_name', 'web')->pluck('name');

        $this->avisarDeLosQueNoExisten($todos);

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
     * Todos los permisos que la matriz nombra, sin el comodín.
     *
     * Público porque el test lo usa para verificar que ninguno sea
     * inventado. Es la contracara de haber pasado a nombres exactos.
     *
     * @return list<string>
     */
    public static function permisosDeclarados(): array
    {
        $declarados = [];

        foreach (self::MATRIZ as $recursos) {
            if (isset($recursos['*'])) {
                continue;
            }

            foreach ($recursos as $sujeto => $acciones) {
                foreach ($acciones as $accion) {
                    $declarados[] = "{$accion}:{$sujeto}";
                }
            }
        }

        return array_values(array_unique($declarados));
    }

    /**
     * Traduce la matriz a los permisos reales de ese rol.
     *
     * Comparación EXACTA, no por substring: con `str_contains`, la palabra
     * `Item` también casaría con `ViewAny:ItemPresentacion` el día que ese
     * Resource exista, y el rol se llevaría permisos que nadie le dio.
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

        $permitidos = [];

        foreach ($recursos as $sujeto => $acciones) {
            foreach ($acciones as $accion) {
                $permitidos[] = "{$accion}:{$sujeto}";
            }
        }

        return $todos->intersect($permitidos)->values();
    }

    /**
     * Grita cuando la matriz nombra un permiso que no existe.
     *
     * Es la red que reemplaza al matching difuso: si alguien renombra un
     * Resource, esto sale en la consola del deploy en vez de dejar a un rol
     * mudo durante meses.
     *
     * @param Collection<int, string> $todos
     */
    private function avisarDeLosQueNoExisten(Collection $todos): void
    {
        if ($todos->isEmpty()) {
            $this->command?->warn(
                '  ⚠ No hay ningún permiso en la base. Corré shield:generate antes de la matriz.'
            );

            return;
        }

        $inexistentes = collect(self::permisosDeclarados())->diff($todos);

        if ($inexistentes->isEmpty()) {
            return;
        }

        $this->command?->error(
            '  ✗ La matriz nombra permisos que no existen: '.$inexistentes->implode(', ')
        );
        $this->command?->comment(
            '    Alguien renombró un Resource, o el formato de nombres de Shield cambió.'
        );
    }
}
