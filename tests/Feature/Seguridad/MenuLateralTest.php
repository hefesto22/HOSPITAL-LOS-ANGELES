<?php

declare(strict_types=1);

use App\Filament\Navegacion\GrupoDelMenu;
use App\Filament\Navegacion\MenuLateral;
use App\Filament\Pages\Escritorio;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * ─────────────────────────────────────────────────────────────────────
 * EL MENÚ LATERAL (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Más práctico, compacto, entendible… y sobre todo identificar cosas
 * rápido». Lo que este archivo cuida:
 *
 *   · que los grupos salgan de `GrupoDelMenu` y en su orden, y que
 *     ninguna opción se escape a un grupo escrito a mano;
 *   · el menú entero, opción por opción: moverlo es una decisión y se
 *     hace acá a la vista, no de pasada en un Resource;
 *   · que no se repita ningún ícono ni ningún nombre, que es lo que
 *     hace que se encuentre de un vistazo;
 *   · que las palabras del buscador sean de opciones que existen.
 *
 * Se mira cada Resource y cada página del panel sin permisos: el menú
 * de cada rol es este mismo, con menos renglones.
 *
 * ⚠️ Ayudante con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Las dos que hoy están apagadas: igual tienen su lugar en el menú.
    config([
        'sihla.expediente.fusiones_de_duplicados' => true,
        'sihla.inventario.usa_descuentos_propios' => true,
    ]);
});

/**
 * Cada opción que el panel pondría en el menú, sin mirar permisos.
 *
 * @return list<array{clase: string, grupo: ?string, orden: int, icono: ?string, nombre: string}>
 */
function opcionesDelMenuLateral(): array
{
    $panel = Filament::getPanel('admin');
    $opciones = [];

    foreach ([...$panel->getResources(), ...$panel->getPages()] as $clase) {
        if (! is_subclass_of($clase, Resource::class) && ! is_subclass_of($clase, Page::class)) {
            continue;
        }

        if (! $clase::shouldRegisterNavigation()) {
            continue;
        }

        $icono = $clase::getNavigationIcon();
        $grupo = $clase::getNavigationGroup();

        $opciones[] = [
            'clase'  => $clase,
            'grupo'  => $grupo instanceof UnitEnum ? $grupo->name : $grupo,
            'orden'  => (int) $clase::getNavigationSort(),
            'icono'  => $icono instanceof BackedEnum ? (string) $icono->value : (is_string($icono) ? $icono : null),
            'nombre' => (string) $clase::getNavigationLabel(),
        ];
    }

    return $opciones;
}

it('los grupos del panel son los de GrupoDelMenu, en su orden', function (): void {
    $grupos = Filament::getPanel('admin')->getNavigationGroups();

    $nombres = array_values(array_map(
        fn (NavigationGroup|string $grupo): ?string => $grupo instanceof NavigationGroup ? $grupo->getLabel() : $grupo,
        $grupos,
    ));

    expect($nombres)->toBe(array_map(fn (GrupoDelMenu $grupo): string => $grupo->value, GrupoDelMenu::cases()));
});

it('hospital y seguridad empiezan recogidos; lo de todos los dias no', function (): void {
    $recogidos = [];

    foreach (Filament::getPanel('admin')->getNavigationGroups() as $grupo) {
        if ($grupo instanceof NavigationGroup && $grupo->isCollapsed()) {
            $recogidos[] = $grupo->getLabel();
        }
    }

    expect($recogidos)->toBe(['Hospital', 'Seguridad']);
    expect(MenuLateral::recogidosDeEntrada())->toBe(['Hospital', 'Seguridad']);
});

it('🔴 ninguna opcion se escapa a un grupo escrito a mano', function (): void {
    $validos = array_map(fn (GrupoDelMenu $grupo): string => $grupo->value, GrupoDelMenu::cases());

    foreach (opcionesDelMenuLateral() as $opcion) {
        if ($opcion['clase'] === Escritorio::class) {
            expect($opcion['grupo'])->toBeNull();

            continue;
        }

        expect($opcion['grupo'])->toBeIn(
            $validos,
            "{$opcion['clase']} cae en «{$opcion['grupo']}», que no es de GrupoDelMenu: se iría al final del menú"
        );
    }
});

it('el menu entero, grupo por grupo y en orden', function (): void {
    $menu = [];

    foreach (opcionesDelMenuLateral() as $opcion) {
        if ($opcion['grupo'] !== null) {
            $menu[$opcion['grupo']][$opcion['orden']] = $opcion['nombre'];
        }
    }

    $enOrden = [];

    foreach (GrupoDelMenu::cases() as $grupo) {
        $opciones = $menu[$grupo->value] ?? [];
        ksort($opciones);
        $enOrden[$grupo->value] = array_values($opciones);
    }

    expect($enOrden)->toBe([
        'Cuentas y caja' => [
            'Cuentas abiertas', 'Caja', 'Abonos recibidos', 'Turnos de caja',
            'Facturas', 'Presupuestos', 'Todas las cuentas', 'Todos los cargos',
        ],
        'Pacientes'             => ['Pacientes', 'Encuentros', 'Fusiones de duplicados'],
        'Farmacia e inventario' => [
            'Existencias', 'Recibir mercadería', 'Medicamentos prestados', 'Conteo físico',
            'Ajustes y bajas', 'Compras', 'Proveedores',
        ],
        'Reportes' => ['Reporte del mes', 'Honorarios por médico', 'Consumo de productos'],
        'Catálogo' => [
            'Productos', 'Catálogo de servicios', 'Principios activos', 'Categorías',
            'Unidades de medida', 'Plantillas de presupuesto',
        ],
        'Precios y seguros' => [
            'Bases de precios', 'Seguros y convenios', 'Descuentos de ley', 'Descuentos', 'Márgenes y recargos',
        ],
        'Hospital' => [
            'Médicos', 'Especialidades', 'Servicios y áreas', 'Almacenes', 'Sedes', 'Rangos de CAI', 'Configuración',
        ],
        'Seguridad' => ['Usuarios', 'Roles', 'Registros de actividad'],
    ]);
})->note('Abonos recibidos y Turnos de caja cuelgan de Caja: en el menú se ven solo estando en Caja (MenuDeCajaTest).');

it('dentro de un grupo no hay dos opciones con el mismo orden', function (): void {
    $vistos = [];

    foreach (opcionesDelMenuLateral() as $opcion) {
        $llave = ($opcion['grupo'] ?? '—').'#'.$opcion['orden'];

        expect(array_key_exists($llave, $vistos))->toBeFalse(
            "{$opcion['clase']} comparte el orden {$opcion['orden']} con ".($vistos[$llave] ?? '')
        );

        $vistos[$llave] = $opcion['clase'];
    }
});

it('🔴 no se repite ningun icono ni ningun nombre', function (): void {
    $iconos = [];
    $nombres = [];

    foreach (opcionesDelMenuLateral() as $opcion) {
        expect($opcion['icono'])->not->toBeNull("{$opcion['clase']} no tiene ícono");

        $icono = (string) $opcion['icono'];

        expect(array_key_exists($icono, $iconos))->toBeFalse("{$opcion['clase']} repite el ícono de ".($iconos[$icono] ?? ''));
        expect(array_key_exists($opcion['nombre'], $nombres))->toBeFalse("«{$opcion['nombre']}» está dos veces en el menú");

        $iconos[$icono] = $opcion['clase'];
        $nombres[$opcion['nombre']] = $opcion['clase'];
    }
});

it('cada palabra del buscador es de una opcion que existe', function (): void {
    $nombres = array_column(opcionesDelMenuLateral(), 'nombre');

    foreach (array_keys(MenuLateral::PALABRAS) as $nombre) {
        expect($nombres)->toContain($nombre);
    }
})->note('Si se renombra una opción y no su llave en PALABRAS, el buscador deja de encontrarla por sus otras palabras.');

it('cada grupo trae su color para los dos modos', function (): void {
    foreach (GrupoDelMenu::grupos() as $indice => $grupo) {
        $caso = GrupoDelMenu::cases()[$indice];
        $atributos = $grupo->getExtraSidebarAttributeBag();

        expect($atributos->get('data-grupo'))->toBe($caso->name);
        expect((string) $atributos->get('style'))
            ->toContain('--grupo-claro:'.$caso->colorClaro())
            ->toContain('--grupo-oscuro:'.$caso->colorOscuro());
    }
});

it('la pantalla trae el buscador, el CSS y el color de su grupo', function (): void {
    $rol = Role::findOrCreate('direccion', 'web');
    $rol->givePermissionTo(Permission::findOrCreate('ViewAny:Item', 'web'));

    actingAsAdmin()->assignRole($rol);

    $this->get('/bases-de-precios')
        ->assertOk()
        ->assertSee('id="sihla-menu-buscar"', false)
        ->assertSee('Buscar en el menú')
        ->assertSee('.fi-main-sidebar .fi-sidebar-nav', false)
        ->assertSee('data-grupo="PreciosYSeguros"', false)
        ->assertSee('--grupo-claro:#008300', false)
        ->assertSee('Precios y seguros');
});
