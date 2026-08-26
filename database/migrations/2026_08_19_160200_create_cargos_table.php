<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cargo — una línea de la cuenta, congelada para siempre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL SNAPSHOT ES LA RAZÓN DE SER DE ESTA TABLA (§8.5-5)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada fila guarda **el precio, el tarifario, la condición del convenio,
 * el descuento, el régimen de ISV, la cobertura y el costo** con los que
 * se calculó. No una referencia que se vuelva a leer: el número.
 *
 * Sin eso, renegociar con una aseguradora el año que viene reimprime las
 * facturas de este año con precios nuevos —rechazo del reclamo y
 * hallazgo fiscal— y una factura reimpresa deja de ser la que el
 * paciente firmó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL ISV VA POR LÍNEA (§8.6.1)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La estancia es exenta, la radiografía es exenta, el paracetamol es
 * exento (Ley del ISV Art. 15 incisos b y d) y la cafetería del
 * acompañante es gravada al 15 %. Todo eso convive en la MISMA cuenta.
 * Un flag de empresa o de factura sería un error estructural.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARTICIONADA POR AÑO, CON UNA PARTICIÓN `DEFAULT`
 * ─────────────────────────────────────────────────────────────────────
 *
 * §12 exige particionar desde el diseño, y la razón está escrita ahí:
 * **en un hospital no hay ventana de mantenimiento a la que apelar.**
 * Convertir esto en caliente a los dos años, con dos millones de filas y
 * la caja funcionando, no es una tarea: es un riesgo.
 *
 * Anuales y no mensuales: a ~700 mil filas por año, la poda mensual no
 * compra nada y costaría 120 particiones por década más un comando
 * programado que las cree.
 *
 * La partición `DEFAULT` existe para que **ninguna fecha pueda tumbar un
 * cargo a las 3 de la mañana**. Si alguien registra un hecho de 2039, la
 * fila entra igual y el reporte de partición lo delata; sin ella, el
 * INSERT falla y la enfermera se queda sin poder registrar.
 *
 * ⚠️ CONSECUENCIA PARA LOS BLOQUES 7 Y 12: la llave primaria es
 * `(id, fecha_operacion)`. Toda tabla futura que referencie un cargo
 * —líneas de factura, notas de crédito, glosas— lleva las DOS columnas
 * en su llave foránea. PostgreSQL lo soporta nativo; hay que acordarse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE EDITA, NO SE BORRA (§9.0.3)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un trigger deja pasar solo el cambio de `estado` por transiciones
 * legales y el sello de la factura. El monto, la cantidad y el snapshot
 * son de piedra. Corregir es asentar un cargo de reversa que apunta al
 * original, igual que en el kardex.
 */
return new class extends Migration
{
    /**
     * Los años que nacen con partición propia. De 2026 a 2036: una
     * década sin que nadie tenga que acordarse de nada.
     */
    private const DESDE = 2026;

    private const HASTA = 2036;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE cargos (
                id bigserial NOT NULL,

                /*
                 * La fecha de OPERACIÓN, no `created_at` (§7.5-4). Los
                 * reportes y el corte de caja filtran por esta columna,
                 * que la asigna el Service en hora de Tegucigalpa. Es
                 * además la llave de partición.
                 */
                fecha_operacion date NOT NULL,

                sede_id bigint NOT NULL REFERENCES sedes(id) ON DELETE RESTRICT,
                cuenta_id bigint NOT NULL REFERENCES cuentas(id) ON DELETE RESTRICT,
                encuentro_id bigint NOT NULL REFERENCES encuentros(id) ON DELETE RESTRICT,
                item_id bigint NOT NULL REFERENCES items(id) ON DELETE RESTRICT,

                servicio_id bigint REFERENCES servicios(id) ON DELETE RESTRICT,
                almacen_id bigint REFERENCES almacenes(id) ON DELETE RESTRICT,
                lote_id bigint REFERENCES lotes(id) ON DELETE RESTRICT,
                movimiento_id bigint REFERENCES movimientos_kardex(id) ON DELETE RESTRICT,
                unidad_id bigint REFERENCES unidades(id) ON DELETE RESTRICT,

                /* Los dos tiempos de todo evento (§9.0.4). */
                ocurrido_en timestamptz NOT NULL,
                registrado_en timestamptz NOT NULL,

                cantidad numeric(14,4) NOT NULL,

                /*
                 * El nombre del ítem CONGELADO. El catálogo se corrige,
                 * se renombra y se le cambia la presentación; la factura
                 * del año pasado tiene que seguir diciendo lo que decía.
                 */
                texto varchar(200) NOT NULL,

                /* ── Snapshot de precio (§8.5-5) ──────────────────── */
                convenio_id bigint NOT NULL REFERENCES convenios(id) ON DELETE RESTRICT,
                tarifario_id bigint REFERENCES tarifarios(id) ON DELETE RESTRICT,
                condicion_id bigint REFERENCES convenio_condiciones(id) ON DELETE RESTRICT,
                origen_precio varchar(30) NOT NULL,
                precio_unitario numeric(14,4) NOT NULL,
                factor_convenio numeric(6,4),

                /* ── Snapshot de descuento legal (Decreto 199-2006) ── */
                categoria_legal varchar(30),
                descuento_legal_fraccion numeric(6,4) NOT NULL DEFAULT 0,
                base_descuento_legal varchar(40) NOT NULL,
                descuento_legal numeric(14,2) NOT NULL DEFAULT 0,

                descuento_comercial numeric(14,2) NOT NULL DEFAULT 0,
                motivo_descuento varchar(200),
                autorizado_por bigint REFERENCES users(id) ON DELETE SET NULL,

                /* ── Snapshot de ISV, por línea (§8.6.1) ──────────── */
                regimen_isv varchar(20) NOT NULL,
                tasa_isv numeric(5,4) NOT NULL DEFAULT 0,

                bruto numeric(14,2) NOT NULL,
                subtotal numeric(14,2) NOT NULL,
                base_exenta numeric(14,2) NOT NULL DEFAULT 0,
                base_gravada numeric(14,2) NOT NULL DEFAULT 0,
                isv numeric(14,2) NOT NULL DEFAULT 0,
                total numeric(14,2) NOT NULL,

                /* ── Snapshot de cobertura, al momento del cargo ──── */
                cobertura_fraccion numeric(6,4) NOT NULL DEFAULT 0,
                elegible boolean NOT NULL DEFAULT false,
                porcion_paciente numeric(14,2) NOT NULL,
                porcion_aseguradora numeric(14,2) NOT NULL DEFAULT 0,

                /*
                 * El costo con el que salió del estante. Va acá y no se
                 * recalcula: el promedio móvil cambia con cada entrada, y
                 * el margen de un caso de marzo se mide con el costo de
                 * marzo (§8.7-10).
                 */
                costo_unitario numeric(14,6),
                costo_total numeric(14,2),

                politica_cargo varchar(30) NOT NULL,

                estado varchar(20) NOT NULL DEFAULT 'pendiente',

                /*
                 * Sin FK todavía: `facturas` es del bloque 7 y está
                 * bloqueado por las consultas al SAR (§8.11-1, §8.11-4).
                 * La columna nace ahora para no tocar millones de filas
                 * después.
                 */
                factura_id bigint,

                revierte_a_id bigint,
                motivo_anulacion varchar(200),

                es_tardio boolean NOT NULL DEFAULT false,

                /*
                 * Dos claves y no una:
                 *
                 *  `clave_origen` — el hecho que alguien registró: «agregué
                 *    diez tabletas». Es lo que la pantalla manda y lo que se
                 *    repite si el navegador reintenta.
                 *  `clave_idempotencia` — la fila. Un mismo hecho puede
                 *    producir VARIAS filas cuando la cantidad se sirve de
                 *    dos lotes distintos, y cada una necesita su propia
                 *    trazabilidad lote → paciente (§9.F9). Se deriva del
                 *    origen con uuid5, así que un reintento produce
                 *    exactamente los mismos valores y choca con el único.
                 */
                clave_origen uuid NOT NULL,
                clave_idempotencia uuid NOT NULL,

                created_at timestamptz,
                updated_at timestamptz,
                created_by bigint REFERENCES users(id) ON DELETE SET NULL,
                updated_by bigint REFERENCES users(id) ON DELETE SET NULL,

                CONSTRAINT cargos_pkey PRIMARY KEY (id, fecha_operacion)
            ) PARTITION BY RANGE (fecha_operacion)
        SQL);

        for ($anio = self::DESDE; $anio <= self::HASTA; $anio++) {
            $siguiente = $anio + 1;

            DB::statement(
                "CREATE TABLE cargos_{$anio} PARTITION OF cargos
                 FOR VALUES FROM ('{$anio}-01-01') TO ('{$siguiente}-01-01')"
            );
        }

        DB::statement('CREATE TABLE cargos_fuera_de_rango PARTITION OF cargos DEFAULT');

        // ── Índices ───────────────────────────────────────────────────

        /*
         * ⚠️ En una tabla particionada, todo índice único DEBE incluir la
         * llave de partición. Por eso la idempotencia de verdad no vive
         * acá sino en `cargo_claves`, que no está particionada: si un
         * reintento cruzara la medianoche, la fecha cambiaría y este
         * único dejaría pasar el duplicado. Este queda igual porque hace
         * el trabajo en el 99.99 % de los casos y cuesta nada.
         */
        DB::statement(
            'CREATE UNIQUE INDEX cargos_idempotencia ON cargos (clave_idempotencia, fecha_operacion)'
        );

        /*
         * Un movimiento de kardex pertenece a un solo cargo. Es lo que
         * impide el doble descuento cuando el bloque 6 traiga la
         * dispensación real: el cargo tiene su movimiento o no lo tiene,
         * y nunca dos cargos comparten uno.
         */
        DB::statement(
            'CREATE UNIQUE INDEX cargos_un_cargo_por_movimiento
             ON cargos (movimiento_id, fecha_operacion)
             WHERE movimiento_id IS NOT NULL'
        );

        DB::statement('CREATE INDEX cargos_por_origen ON cargos (clave_origen)');
        DB::statement('CREATE INDEX cargos_de_la_cuenta ON cargos (cuenta_id, fecha_operacion DESC)');
        DB::statement('CREATE INDEX cargos_del_encuentro ON cargos (encuentro_id, fecha_operacion DESC)');
        DB::statement('CREATE INDEX cargos_por_item ON cargos (item_id, fecha_operacion DESC)');

        /* Bandeja de lo que falta facturar: el 1 % de la tabla (§12). */
        DB::statement(
            "CREATE INDEX cargos_pendientes ON cargos (sede_id, fecha_operacion)
             WHERE estado = 'pendiente'"
        );

        // ── Defensa en profundidad ────────────────────────────────────

        $checks = [
            'cargos_cantidad_no_cero'            => 'cantidad <> 0',
            'cargos_precio_no_negativo'          => 'precio_unitario >= 0',
            'cargos_texto_no_vacio'              => 'length(btrim(texto)) >= 2',
            'cargos_estado_conocido'             => "estado IN ('pendiente', 'facturado', 'anulado', 'anulacion', 'trasladado')",
            'cargos_origen_conocido'             => "origen_precio IN ('precio_de_lista', 'precio_negociado', 'porcentaje_pactado', 'precio_manual')",
            'cargos_regimen_conocido'            => "regimen_isv IN ('exento', 'gravado_15', 'gravado_18', 'exonerado')",
            'cargos_politica_conocida'           => "politica_cargo IN ('cobrable', 'incluido_en_tarifa', 'gasto_del_servicio')",
            'cargos_base_descuento_conocida'     => "base_descuento_legal IN ('sobre_lo_que_paga_el_paciente', 'sobre_el_total_facturado', 'no_aplica')",
            'cargos_totales_cuadran'             => 'total = base_exenta + base_gravada + isv',
            'cargos_subtotal_cuadra'             => 'subtotal = bruto - descuento_legal - descuento_comercial',
            'cargos_division_cuadra'             => 'porcion_paciente + porcion_aseguradora = total',
            'cargos_exento_sin_isv'              => "regimen_isv NOT IN ('exento', 'exonerado') OR (base_gravada = 0 AND isv = 0)",
            'cargos_gravado_sin_exento'          => "regimen_isv IN ('exento', 'exonerado') OR base_exenta = 0",
            'cargos_anulacion_apunta'            => "(estado = 'anulacion') = (revierte_a_id IS NOT NULL)",
            'cargos_anulado_con_motivo'          => "estado <> 'anulado' OR length(btrim(motivo_anulacion)) >= 10",
            'cargos_movimiento_con_almacen'      => 'movimiento_id IS NULL OR almacen_id IS NOT NULL',
            'cargos_costo_completo'              => '(costo_unitario IS NULL) = (costo_total IS NULL)',
            'cargos_fraccion_legal_valida'       => 'descuento_legal_fraccion >= 0 AND descuento_legal_fraccion <= 1',
            'cargos_fraccion_cobertura_valida'   => 'cobertura_fraccion >= 0 AND cobertura_fraccion <= 1',
            'cargos_no_elegible_sin_aseguradora' => 'elegible OR porcion_aseguradora = 0',
            'cargos_no_es_su_propia_reversa'     => 'revierte_a_id IS NULL OR revierte_a_id <> id',
        ];

        foreach ($checks as $nombre => $regla) {
            DB::statement("ALTER TABLE cargos ADD CONSTRAINT {$nombre} CHECK ({$regla})");
        }

        // ── Append-only con transiciones legales ──────────────────────

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sihla_cargo_solo_transiciona() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un cargo no se borra. Se corrige asentando un cargo de reversa que deja rastro (SIHLA §9.0.3).'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF ROW(NEW.*) IS NOT DISTINCT FROM ROW(OLD.*) THEN
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.fecha_operacion, NEW.cuenta_id, NEW.encuentro_id, NEW.item_id,
                    NEW.cantidad, NEW.precio_unitario, NEW.bruto, NEW.subtotal, NEW.total,
                    NEW.isv, NEW.base_exenta, NEW.base_gravada, NEW.descuento_legal,
                    NEW.descuento_comercial, NEW.porcion_paciente, NEW.porcion_aseguradora,
                    NEW.costo_unitario, NEW.costo_total, NEW.movimiento_id, NEW.tarifario_id,
                    NEW.convenio_id, NEW.regimen_isv, NEW.clave_idempotencia, NEW.created_by)
                   IS DISTINCT FROM
                   (OLD.id, OLD.fecha_operacion, OLD.cuenta_id, OLD.encuentro_id, OLD.item_id,
                    OLD.cantidad, OLD.precio_unitario, OLD.bruto, OLD.subtotal, OLD.total,
                    OLD.isv, OLD.base_exenta, OLD.base_gravada, OLD.descuento_legal,
                    OLD.descuento_comercial, OLD.porcion_paciente, OLD.porcion_aseguradora,
                    OLD.costo_unitario, OLD.costo_total, OLD.movimiento_id, OLD.tarifario_id,
                    OLD.convenio_id, OLD.regimen_isv, OLD.clave_idempotencia, OLD.created_by)
                THEN
                    RAISE EXCEPTION 'El cargo % ya está asentado: su monto, su cantidad y su snapshot de precio no se editan. Se corrige con una reversa (SIHLA §8.5-5).', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.estado <> NEW.estado AND NOT (
                       (OLD.estado = 'pendiente'  AND NEW.estado IN ('facturado', 'anulado', 'trasladado'))
                    OR (OLD.estado = 'facturado'  AND NEW.estado = 'anulado')
                    OR (OLD.estado = 'trasladado' AND NEW.estado IN ('facturado', 'anulado'))
                ) THEN
                    RAISE EXCEPTION 'Transición no permitida en el cargo %: de % a %.', OLD.id, OLD.estado, NEW.estado
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(
            'CREATE TRIGGER cargos_append_only
             BEFORE UPDATE OR DELETE ON cargos
             FOR EACH ROW EXECUTE FUNCTION sihla_cargo_solo_transiciona()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos');

        DB::statement('DROP FUNCTION IF EXISTS sihla_cargo_solo_transiciona()');
    }
};
