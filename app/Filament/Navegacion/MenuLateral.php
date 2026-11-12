<?php

declare(strict_types=1);

namespace App\Filament\Navegacion;

use Illuminate\Contracts\View\View;

/**
 * El menú lateral: compacto, con color por grupo y con buscador.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIA Y POR QUÉ (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Más práctico, compacto, entendible… y sobre todo identificar cosas
 * rápido». Con cuarenta opciones, buscarlas leyendo una por una es lo
 * lento. Tres cosas lo arreglan, y ninguna toca permisos:
 *
 *   · Compacto: renglones de 32 px en la computadora en vez de 36,
 *     títulos de grupo chicos y lo de configuración recogido. El menú de
 *     dirección pasó de 2,254 px de alto a unos 1,360. En el celular los
 *     renglones siguen altos, para el dedo.
 *   · Color por grupo (`GrupoDelMenu`): cada ícono en un cuadrito tenue
 *     del color de su grupo, y lleno en la pantalla en que se está. Cada
 *     opción tiene su propio ícono; no se repite ninguno.
 *   · Buscador arriba del menú: se teclea «prec» y queda Bases de
 *     precios. Busca en el nombre y en las palabras de `PALABRAS`
 *     —«seguro», «stock», «abonos»—, sin tildes ni mayúsculas, y en el
 *     nombre del grupo solo si así no encuentra nada. La tecla / lo abre
 *     desde cualquier pantalla, las flechas eligen y Enter entra.
 *
 * Es solo presentación: el CSS y el buscador se inyectan con los
 * render hooks del panel y viajan con él, sin compilar nada. Si Filament
 * renombra una clase, el menú se ve como antes y nada se rompe.
 *
 * ⚠️ Las llaves de `PALABRAS` son los nombres EXACTOS del menú. Si se
 * renombra una opción hay que renombrar su llave: `MenuLateralTest`
 * falla si alguna queda huérfana.
 */
final class MenuLateral
{
    /**
     * Sube cuando cambian los grupos: la primera visita con una versión
     * nueva deja recogidos los grupos de `GrupoDelMenu::empiezaRecogido()`.
     * Después manda lo que cada quien abra o cierre.
     */
    public const VERSION = '2026-11-11';

    /**
     * Otras palabras con que la gente busca cada opción.
     *
     * Van sin tildes: el buscador las quita de los dos lados.
     *
     * @var array<string, string>
     */
    public const PALABRAS = [
        'Escritorio'                => 'inicio resumen del dia',
        'Cuentas abiertas'          => 'abrir cuenta cargar cargo internado emergencia ingreso',
        'Caja'                      => 'cobrar cobro abono abonos recibo recibos turno gaveta efectivo tarjeta pago',
        'Abonos recibidos'          => 'abono recibo pago',
        'Turnos de caja'            => 'turno cierre de caja cuadre',
        'Facturas'                  => 'factura anular imprimir cai',
        'Presupuestos'              => 'presupuesto cotizacion cotizar',
        'Todas las cuentas'         => 'buscar cuenta cerradas facturadas anuladas historial bitacora cargo cargos consulta',
        'Pacientes'                 => 'paciente expediente persona dni identidad',
        'Encuentros'                => 'visita atencion ingreso',
        'Fusiones de duplicados'    => 'duplicado repetido unir',
        'Existencias'               => 'stock inventario lote lotes vencimiento vence cuanto hay',
        'Recibir mercadería'        => 'recibir entrada recepcion mercaderia',
        'Medicamentos prestados'    => 'prestamo prestar devolver',
        'Conteo físico'             => 'contar conteo inventario fisico',
        'Ajustes y bajas'           => 'ajuste baja merma vencido perdida',
        'Compras'                   => 'compra comprar factura del proveedor',
        'Proveedores'               => 'proveedor drogueria',
        'Reporte del mes'           => 'reporte mensual ventas ingresos ganancia cuanto entro',
        'Honorarios por médico'     => 'honorario medico doctor pagar',
        'Consumo de productos'      => 'consumo salidas gasto',
        'Productos'                 => 'producto medicamento medicina insumo material farmacia',
        'Catálogo de servicios'     => 'servicio examen laboratorio rayos imagen ultrasonido consulta honorario estancia procedimiento',
        'Principios activos'        => 'principio generico molecula',
        'Categorías'                => 'categoria clasificacion',
        'Unidades de medida'        => 'unidad caja blister tableta frasco',
        'Plantillas de presupuesto' => 'plantilla paquete cirugia',
        'Bases de precios'          => 'precio precios tarifa lista cuanto cuesta',
        'Seguros y convenios'       => 'seguro aseguradora poliza convenio',
        'Descuentos de ley'         => 'tercera edad cuarta edad adulto mayor ley',
        'Descuentos'                => 'descuento rebaja',
        'Márgenes y recargos'       => 'margen recargo ganancia',
        'Médicos'                   => 'medico doctor',
        'Especialidades'            => 'especialidad',
        'Servicios y áreas'         => 'area sala departamento',
        'Almacenes'                 => 'almacen bodega botiquin',
        'Sedes'                     => 'sede sucursal',
        'Rangos de CAI'             => 'cai sar fiscal correlativo',
        'Configuración'             => 'logo color favicon',
        'Usuarios'                  => 'usuario contrasena acceso empleado',
        'Roles'                     => 'rol permiso permisos',
        'Registros de actividad'    => 'bitacora auditoria quien hizo',
    ];

    /**
     * El CSS del menú, para `PanelsRenderHook::HEAD_END`.
     */
    public static function estilos(): View
    {
        return view('filament.menu-lateral.estilos');
    }

    /**
     * El buscador, para `PanelsRenderHook::SIDEBAR_NAV_START`.
     */
    public static function buscador(): View
    {
        return view('filament.menu-lateral.buscador', [
            'palabras'  => self::PALABRAS,
            'recogidos' => self::recogidosDeEntrada(),
            'version'   => self::VERSION,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function recogidosDeEntrada(): array
    {
        return array_values(array_map(
            fn (GrupoDelMenu $grupo): string => $grupo->value,
            array_filter(GrupoDelMenu::cases(), fn (GrupoDelMenu $grupo): bool => $grupo->empiezaRecogido()),
        ));
    }
}
