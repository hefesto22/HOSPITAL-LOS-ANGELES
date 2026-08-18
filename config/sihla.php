<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SIHLA — Reglas de negocio configurables
|--------------------------------------------------------------------------
|
| ⚠️ REGLA DE ORO DE ESTE ARCHIVO (§1.1):
|
|    Si un valor necesita HISTORIAL (vigencia) o varía POR SEDE,
|    NO va acá: va en base de datos.
|
| Acá viven los defaults estructurales y las reglas que son iguales para
| toda la instalación y que no hay que poder reconstruir hacia atrás.
|
| Ejemplos de lo que NO va acá, y por qué:
|
|   ✗ Precios              → tarifario con vigencia por convenio (ADR-0003)
|   ✗ % de descuento legal → cambian por ley; hay que saber cuál regía en
|                            la fecha del servicio de una factura de 2027
|   ✗ Márgenes por producto→ por categoría y con vigencia, en base de datos
|   ✗ Rangos de ISV por ítem → atributo del catálogo, por línea (§8.6.1)
|
| Lo que sí va acá son los VALORES POR DEFECTO con los que se siembra la
| base, y las reglas técnicas que no cambian.
|
| Otra clínica cambia este archivo y su .env, y no toca una línea de código.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad de la instalación
    |--------------------------------------------------------------------------
    */

    'organizacion' => [
        'nombre'       => env('SIHLA_ORGANIZACION', 'Hospital Los Ángeles'),
        'pais'         => env('SIHLA_PAIS', 'HN'),
        'moneda'       => env('SIHLA_MONEDA', 'HNL'),
        'zona_horaria' => env('APP_TIMEZONE', 'America/Tegucigalpa'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rangos de edad — DEFAULTS de siembra
    |--------------------------------------------------------------------------
    |
    | ⚠️ Estos son los valores con los que se SIEMBRA la tabla de rangos.
    | Los vigentes se leen de base de datos, porque la ley cambia.
    |
    | EN SALUD EL ÚNICO UMBRAL ES 60 (Art. 30 del Decreto 199-2006). La
    | cuarta edad existe —Decreto 45-2025, La Gaceta 37,047— pero reforma
    | el Art. 31, que es la Sección II de servicios básicos: energía, agua,
    | telecomunicaciones y cable. NO aplica a servicios médicos.
    |
    | Se siembra igual, para que extenderla a salud sea una fila y no un
    | despliegue. Ver docs/dominio-inventario-y-precios.md §4.4.
    |
    | El rango se calcula SIEMPRE contra la fecha del servicio, nunca
    | contra "hoy" ni contra la fecha de facturación (§4.3 de
    | docs/dominio-inventario-y-precios.md).
    |
    */

    'edad' => [
        'rangos_por_defecto' => [
            'normal'  => ['desde' => 0,  'hasta' => 59],
            'tercera' => ['desde' => 60, 'hasta' => 79],
            'cuarta'  => ['desde' => 80, 'hasta' => null],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Precios y márgenes
    |--------------------------------------------------------------------------
    */

    'precios' => [
        /*
         * Piso de margen sobre el costo promedio. 1.20 = 120 %.
         * El precio de lista se deriva del PEOR descuento posible del ítem
         * para que este piso se cumpla en todos los rangos de edad:
         *
         *   lista = costo × (1 + margen) / (1 − descuento_maximo)
         *
         * Es un default: el margen real es por categoría de producto y con
         * vigencia, en base de datos.
         */
        'margen_objetivo_por_defecto' => 1.20,

        /*
         * Si una entrada mueve el costo promedio más que esto, se alerta
         * antes de propagar el precio al mostrador. Un cero de más
         * digitado en el costo de una compra llega a la caja en segundos.
         */
        'umbral_alerta_variacion_costo' => 0.25,

        /*
         * El costo promedio se guarda con MÁS decimales que el dinero.
         * Redondear el promedio a 2 en cada entrada acumula error y a los
         * seis meses el valor de inventario no cuadra con contabilidad.
         */
        'decimales_costo'  => 4,
        'decimales_dinero' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | ISV — Honduras
    |--------------------------------------------------------------------------
    |
    | El régimen es POR LÍNEA de ítem, nunca por factura ni por empresa
    | (Ley del ISV Art. 15 incisos b y d, §8.6.1). Acá solo van las tasas.
    |
    */

    'isv' => [
        'tasa_general'          => 0.15,
        'tasa_especial'         => 0.18,
        'rtn_obligatorio_desde' => 10000.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventario
    |--------------------------------------------------------------------------
    */

    'inventario' => [
        /*
         * Promedio ponderado MÓVIL contra existencia, no histórico.
         * Con existencia 0 o negativa el promedio se reinicia al costo
         * de la entrada.
         */
        'metodo_costeo' => 'promedio_ponderado_movil',

        /*
         * FEFO: sale primero lo que vence primero, no lo que entró primero.
         * En medicamentos, FIFO produce vencimientos en anaquel.
         */
        'politica_salida' => 'fefo',

        'dias_alerta_vencimiento' => [90, 60, 30],

        /*
         * Un envase multidosis abierto suele vencer mucho antes que la
         * fecha impresa. El valor real es por producto; esto es el default.
         */
        'horas_caducidad_post_apertura_por_defecto' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Facturación
    |--------------------------------------------------------------------------
    |
    | La CUENTA del paciente acumula cargos de todos los turnos y de todo
    | el personal mientras el encuentro está abierto. La FACTURA es el
    | documento fiscal, se emite UNA sola vez al alta o al pago, y es
    | inmutable (ADR-0004). No son la misma entidad.
    |
    */

    'facturacion' => [
        /*
         * Doble umbral de alerta del CAI: porcentaje de rango consumido
         * Y días restantes a la fecha límite. Quedarse sin CAI en un
         * hospital significa no poder dar de alta pacientes.
         */
        'alerta_cai_porcentaje_rango' => 0.80,
        'alerta_cai_dias_restantes'   => 30,

        /*
         * Ventana para registrar un cargo con fecha anterior. Después de
         * esto, requiere autorización. Es política, no constante: se
         * sobrescribe por sede.
         */
        'horas_cargo_tardio' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expediente clínico y bitácora
    |--------------------------------------------------------------------------
    */

    'expediente' => [
        'anios_retencion_activo' => 5,
        'anios_retencion_pasivo' => 15,

        /*
         * El acceso de emergencia (break-the-glass) EXPIRA. No otorga
         * permiso permanente: sirve para ese episodio y se revisa.
         */
        'break_the_glass' => [
            'horas_vigencia'          => 24,
            'horas_para_revision'     => 72,
            'motivos_requieren_texto' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operación
    |--------------------------------------------------------------------------
    */

    'operacion' => [
        /*
         * Los turnos son DOS cosas distintas y no se mezclan:
         *
         *  - turno de PERSONAL: quién está activo ahora. Es lo que permite
         *    localizar al médico DE TURNO cuando sale un valor crítico a
         *    las 4:10 am, y alimenta la autorización (rol + relación +
         *    sede + turno, §9.L).
         *  - sesión de CAJA: se abre con fondo, acumula cobros y cierra
         *    cuadrando efectivo contra sistema.
         *
         * Los nombres son configurables: A/B/C hoy, más mañana.
         */
        'turnos_por_defecto' => ['A', 'B', 'C'],

        /*
         * Hora de corte del censo. Un censo que se corre a otra hora da
         * otro número de pacientes-día y la facturación de estancia no
         * cuadra.
         */
        'hora_corte_censo' => '00:00',
    ],

];
