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
|   ✗ % de cobertura de un seguro → columna del convenio, y congelado en
|                            cada cargo: cambia con cada renegociación
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

        /*
        |----------------------------------------------------------------
        | Conteo físico y ajustes
        |----------------------------------------------------------------
        */

        /*
         * Hasta cuánto puede ajustar bodega o farmacia sin que lo
         * autorice nadie, en lempiras al COSTO PROMEDIO y por documento.
         *
         * ⚠️ Va entre comillas a propósito. Un `1000.00` suelto sería un
         * float, y el §8.6.2 prohíbe el punto flotante para dinero: el
         * tope se lee como texto y entra directo a bcmath.
         *
         * En lempiras y no en unidades porque 500 gasas y 2 ampollas de
         * inmunoglobulina son el mismo número y no son el mismo hecho.
         */
        'tope_ajuste_sin_autorizacion' => env('SIHLA_TOPE_AJUSTE', '1000.00'),

        /*
         * Quién puede autorizar por encima del tope. Nunca el mismo que
         * registra: el servicio lo verifica.
         */
        'roles_que_autorizan_ajuste' => ['direccion'],

        /*
         * A partir de qué diferencia (en unidades de dispensación) una
         * línea de conteo exige que alguien vuelva a contar antes de
         * poder cerrar.
         *
         * Cero = cualquier diferencia exige recuento, que es lo correcto
         * para controlados e implantes caros. Se puede subir por conteo
         * desde la pantalla: contar gasas con tolerancia cero es perder
         * la tarde.
         */
        'tolerancia_recuento_por_defecto' => '0',

        /*
         * Cuántas líneas admite un conteo. No es un capricho de tamaño:
         * cerrarlo bloquea la fila de costo y la de existencia de cada
         * producto que toca, y una transacción larga sobre el inventario
         * frena la farmacia entera (§13.5). Conteos más grandes se parten
         * por sección del estante.
         */
        'maximo_lineas_por_conteo' => 300,

        /*
         * ¿EL HOSPITAL DA DESCUENTOS PROPIOS, ADEMÁS DE LOS DE LEY?
         *
         * Hospital Los Ángeles hoy NO: lo único que descuenta es el
         * Artículo 30, que se aplica solo desde «Descuentos de ley». Con
         * esto en `false` desaparecen la pantalla «Descuentos» y el campo
         * «Descuentos que aplican» de la ficha del ítem.
         *
         * ⚠️ Se esconde, NO se borra, y la diferencia importa:
         *
         *   · La tabla `descuentos` y el paso 3 de `CalculadoraDeCargo`
         *     siguen en pie. Un cargo viejo que hubiera usado un
         *     descuento comercial sigue pudiendo explicar su factura.
         *   · Un hospital termina dando descuentos —a empleados, a un
         *     convenio de cortesía, a una promoción—. El día que pase,
         *     esto se pone en `true` y la pantalla vuelve entera.
         *
         * 🔴 Y esconderla arregla algo concreto: cuando existía, era el
         * primer lugar donde alguien buscaba el descuento del adulto
         * mayor, y ahí NO está. Duplicar la ley con nombre propio hacía
         * que un paciente pudiera recibir 25 % de ley + 25 % comercial.
         */
        'usa_descuentos_propios' => false,

        /*
         * QUÉ CATEGORÍA SE PROPONE AL ELEGIR EL TIPO DE ÍTEM.
         *
         * Un medicamento va a MEDICAMENTOS, un estudio de laboratorio a
         * LABORATORIO. Es evidente para quien carga y por eso mismo no
         * tiene por qué tener que elegirlo dos veces.
         *
         * ⚠️ PROPONE, no impone: se escribe solo si la categoría está
         * vacía, y se puede cambiar. Las gasas son «insumo» pero pueden
         * ir a MATERIAL DE CURACIÓN o a DESCARTABLES según cómo agrupe
         * cada hospital, y esa decisión no la toma un mapa.
         *
         * Los tipos que NO están acá —servicio, procedimiento, paquete,
         * otro— no proponen nada: caben en varias categorías y adivinar
         * mal cuesta más que preguntar.
         *
         * La clave es el `value` del enum `TipoItem`; el valor, el CÓDIGO
         * de la categoría. Si el código no existe, no se propone nada y
         * no pasa nada — por eso la clínica siguiente puede renombrar sus
         * categorías sin que esto reviente.
         */
        'categoria_por_tipo' => [
            'medicamento'         => 'MED',
            'insumo'              => 'MTC',
            'estudio_laboratorio' => 'LAB',
            'estudio_imagen'      => 'RX',
            'estancia'            => 'HOS',
            'honorario'           => 'CON',
        ],

        /*
         * UN SOLO ALMACÉN PARA TODO EL HOSPITAL.
         *
         * Hospital Los Ángeles no divide el inventario: entra la compra,
         * sale la venta al paciente y come el servicio, todo del mismo
         * estante. Con esto en `true` el formulario esconde «Tipo» y
         * «Servicio dueño», el almacén nace como `TipoAlmacen::AlmacenUnico`
         * y crearlo es sede + código + nombre.
         *
         * Es bandera y no borrado del campo porque la clínica siguiente sí
         * separa bodega de farmacia (§1.1): apagarla devuelve los cuatro
         * tipos sin migración, sin deploy y sin perder el histórico de qué
         * salió de qué estante.
         *
         * ⚠️ Si se apaga en un hospital que ya operó en modo único, los
         * almacenes existentes siguen siendo `almacen_unico` — hay que
         * reclasificarlos a mano antes de que los roles empiecen a filtrar.
         */
        'modo_almacen_unico' => true,

        /*
         * Qué estantes toca cada rol, por TIPO de almacén.
         *
         * Se deriva del tipo y no de una asignación usuario↔almacén: el
         * dato ya está, y una tabla más sería una pantalla más y un
         * mantenimiento más para decir lo mismo.
         *
         * Un rol que NO aparece acá no tiene restricción —dirección,
         * auditoría—, y por eso siempre hay alguien que puede cerrar el
         * conteo de cualquier almacén sin romper el control de cuatro
         * ojos.
         *
         * Los valores son los del enum `TipoAlmacen`.
         */
        'almacenes_por_rol' => [
            'bodega'   => ['almacen_unico', 'bodega_central', 'stock_de_servicio'],
            'farmacia' => ['almacen_unico', 'farmacia_venta', 'farmacia_interna'],
        ],
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
         * 🔴 ARRIBA DE ESTE MONTO, LA FACTURA EXIGE RTN.
         *
         * El §8.6 lo dice y el SAR lo revisa: se permite «CONSUMIDOR
         * FINAL» salvo cuando la venta supera este umbral, donde los
         * datos del cliente son obligatorios. En un hospital se cruza
         * constantemente —una sola noche de hospitalización lo pasa—, y
         * por eso el sistema BLOQUEA la emisión, no avisa.
         *
         * Es configurable porque es un número de la ley, y los números
         * de la ley cambian sin avisarle a nadie.
         */
        'umbral_rtn_obligatorio' => '10000.00',

        /*
         * Cómo se llama el cliente cuando no da sus datos y la venta no
         * llega al umbral.
         */
        'consumidor_final' => 'CONSUMIDOR FINAL',

        /*
         * ⚠️ LEYENDAS DE LA FACTURA — CONFIRMAR CON EL SAR.
         *
         * El texto exacto que debe llevar impreso un documento fiscal lo
         * fija la normativa, y es parte de lo que sigue abierto en la
         * pregunta #1 de `docs/dominio.md`. Están acá, en config, para
         * que corregirlas el día que el SAR conteste sea editar una
         * línea y no tocar una vista.
         */
        'leyendas' => [
            'La factura es beneficio de todos, exíjala',
        ],

        /*
         * TOPE DEL DESCUENTO DEL HOSPITAL, cuando no hay uno de ley del
         * cual colgarse.
         *
         * Para todo lo que está en el Artículo 30, el tope lo pone la ley
         * misma: el descuento total de una línea no puede pasar del
         * descuento legal MÁS ALTO de esa categoría (40 % en
         * medicamentos). Eso mantiene dos cosas a la vez —que nadie
         * reciba más que el adulto mayor, y que el piso de margen no se
         * rompa— sin configurar nada.
         *
         * Este número es solo para lo que queda FUERA del Art. 30:
         * cafetería, parqueo, alquileres. Ahí no hay máximo legal, así
         * que lo pone la dirección.
         */
        'tope_descuento_comercial' => '0.30',

        /*
         * ─────────────────────────────────────────────────────────────
         * CUÁNTO REBAJA EL HOSPITAL POR SU CUENTA, SEGÚN A QUIÉN
         * ─────────────────────────────────────────────────────────────
         *
         * Esto NO es ley: es política de dirección, y por eso vive acá y
         * no en `descuentos_legales`. Los tres números son los aprobados:
         *
         *   · Cuarta edad (80+)    → 0 %. Ya recibe el 40 % de ley, que
         *     ES el techo con el que se calculó el precio de lista. Un
         *     punto más no sale del precio: sale del margen.
         *   · Tercera edad (60-79) → hasta 10 % sobre el 25 % de ley.
         *     Total 35 %, que deja al de 80 años pagando menos que al de
         *     65. Ese orden es lo que mantiene el esquema del lado legal.
         *   · Sin descuento de ley → hasta 30 %.
         *
         * ⚠️ Bajarlos es seguro. SUBIRLOS no alcanza para pasar el techo
         * de ley: `CalculadoraDeCargo` verifica ese techo aparte y
         * rechaza el cargo igual. Esta política solo puede ser más
         * estricta que la ley, nunca más floja.
         *
         * Las claves son los `value` de `RangoEdad`, más `sin_rango`
         * para la línea que no lleva descuento legal.
         */
        'descuento_comercial_por_rango' => [
            'sin_rango' => '0.30',
            'tercera'   => '0.10',
            'cuarta'    => '0.00',
        ],

        /*
         * Ventana para registrar un cargo con fecha anterior. Después de
         * esto, requiere autorización. Es política, no constante: se
         * sobrescribe por sede.
         */
        'horas_cargo_tardio' => 24,

        /*
         * Minutos que la cuenta queda CONGELADA después del alta médica
         * para que cada servicio suba lo suyo (§8.6.3).
         *
         * Durante ese rato la cuenta sigue admitiendo cargos —siempre los
         * admite— pero los que entran nacen marcados como tardíos. Es lo
         * que permite medir la demora del egreso sin obligar a nadie a
         * mentir sobre cuándo pasó lo que pasó.
         */
        'minutos_cutoff_egreso' => 120,

        /*
         * Cuántas tarjetas de cuenta abierta se muestran de una. Un
         * hospital de cuarenta camas no llega acá; el tope existe para
         * que la pantalla no se vuelva un scroll infinito el día que
         * alguien olvide cerrar cuentas por un mes.
         */
        'tarjetas_por_pantalla' => 24,

        /*
         * Cuántos ítems devuelve el buscador del modal. Más que esto no
         * se lee: se teclea otra letra o se escanea el código.
         */
        'resultados_de_busqueda' => 12,
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

    /*
    |--------------------------------------------------------------------------
    | Presupuesto al paciente (ADR-0008)
    |--------------------------------------------------------------------------
    |
    | El presupuesto es un estimado que NO genera cargos: solo aporta el
    | denominador de un medidor cuyo numerador es `cuentas.total`.
    |
    | Estos umbrales viven acá y no en constantes de código porque son
    | politica del hospital, no del programa (§1.1). Lo que NO va acá es
    | nada que necesite vigencia o cambie por sede: eso es base de datos.
    |
    */

    'presupuesto' => [
        /*
         * A partir de que se consume esta fraccion, la cuenta se pinta en
         * ambar. Es el aviso temprano: todavia hay margen para hablar con
         * la familia antes de que el numero sea un problema.
         */
        'umbral_alerta' => 0.80,

        /*
         * Dias que vale un presupuesto emitido cuando la plantilla no dice
         * otra cosa. Vencido se recotiza; no se reusa, porque los precios
         * de hace tres meses ya no son los precios.
         */
        'dias_vigencia_por_defecto' => 15,

        /*
         * 🔴 Pasarse del presupuesto AVISA, no bloquea. Un presupuesto
         * agotado jamas detiene un cargo clinico — es la misma regla que
         * impide rechazar la transfusion de las 23:50.
         *
         * Esta bandera existe para que la decision este escrita y sea
         * visible, no para que alguien la apague: ponerla en true exige
         * antes resolver que pasa a las 3 am cuando el autorizador duerme.
         */
        'bloquea_al_exceder' => false,
    ],

    /*
     |--------------------------------------------------------------------
     | Caja
     |--------------------------------------------------------------------
     */
    'caja' => [
        /*
         * Los bancos a los que la familia puede depositar.
         *
         * Vive en config y no en un enum ni en una tabla: la lista cambia
         * cuando el hospital abre o cierra una cuenta bancaria, y eso no
         * merece una migracion. Agregar uno es una linea acá.
         *
         * ⚠️ El nombre que se elija queda ESCRITO en el recibo. Cambiar
         * un texto de esta lista no reescribe los abonos viejos, y está
         * bien: el recibo dice a qué banco se depositó ese día.
         */
        'bancos' => [
            'Banco Atlántida',
            'Banco de Occidente',
            'Ficohsa',
            'BAC Credomatic',
            'Banpaís',
            'Banco Popular',
            'Banrural',
            'Lafise',
            'Davivienda',
            'Banco Azteca',
        ],
    ],

];
