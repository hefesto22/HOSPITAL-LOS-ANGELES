<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El margen que el hospital quiere ganar, por tipo de ítem y con vigencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SIGNIFICA EL NÚMERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es margen SOBRE EL COSTO: `1.2000` es 120 %, o sea que un producto que
 * costó L 10.00 tiene que dejar L 12.00 de ganancia. No es el porcentaje
 * del precio de venta, que sería otra cuenta y otro número.
 *
 * Es el piso, no el objetivo:
 *
 *     precio_lista = costo × (1 + margen) / (1 − descuento_máximo)
 *
 * Dividir por el peor descuento es lo que convierte el 120 % en garantía.
 * Si la lista se fijara solo con `costo × 2.20`, el adulto mayor pagaría
 * 25 % menos y el margen real caería a 65 % (§4.5).
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL NULO ES EL DEFAULT, Y ESO OBLIGA AL `COALESCE`
 * ─────────────────────────────────────────────────────────────────────
 *
 * `tipo_item` nulo significa "para todo lo que no tenga margen propio".
 * Siempre hay una respuesta, y por eso el resolutor nunca se queda sin
 * qué contestar.
 *
 * ⚠️ Pero en SQL `NULL = NULL` no es verdadero, así que una restricción
 * de exclusión sobre la columna cruda **dejaría convivir dos defaults
 * globales** — y ahí el margen del hospital dependería del `ORDER BY`.
 * Por eso la exclusión va sobre `COALESCE(tipo_item, '*')`.
 *
 * Es exactamente el mismo tipo de agujero que el índice único parcial de
 * `persona_identificadores` resolvió con `COALESCE(pais_emision, '--')`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES CONFIGURACIÓN DE ARCHIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `config/sihla.php` tiene un `margen_objetivo_por_defecto`, pero es solo
 * la semilla. El margen real necesita HISTORIAL: cuando alguien pregunte
 * en 2028 por qué un producto se vendía a ese precio en 2026, la
 * respuesta es una fila con fecha, no un valor que se sobrescribió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('margenes_objetivo', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Nulo = default de la instalación. Ver el encabezado.
             *
             * Es `TipoItem` y no una categoría comercial más fina porque
             * es el eje que ya existe y sobre el que Mauricio puede
             * decidir hoy. El día que haga falta separar "medicamento
             * genérico" de "medicamento de marca", se agrega una columna
             * nullable más y un peldaño a la escalera del resolutor: sin
             * migrar datos y sin tocar la fórmula.
             */
            $tabla->string('tipo_item', 30)->nullable();

            /*
             * NUMERIC(6,4): hasta 99.9999, o sea 9999 %. Cuatro decimales
             * porque un margen de 87.5 % existe y redondearlo a 88 % le
             * cambia el precio a todo el catálogo.
             */
            $tabla->decimal('porcentaje', 6, 4);

            /*
             * Por qué ese número. Un margen sin explicación es un margen
             * que nadie se anima a cambiar dos años después.
             */
            $tabla->string('motivo');

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE margenes_objetivo
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        DB::statement(
            "ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_sin_traslape
             EXCLUDE USING gist ((COALESCE(tipo_item, '*')) WITH =, vigencia WITH &&)
             WHERE (deleted_at IS NULL)"
        );

        // ── Defensa en profundidad ────────────────────────────────────

        /*
         * Un margen negativo es vender bajo el costo. Puede ser una
         * decisión comercial real —un producto gancho—, pero nunca por
         * accidente: si algún día hace falta, se quita este CHECK a
         * propósito y se documenta.
         */
        DB::statement(
            'ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_no_negativo
             CHECK (porcentaje >= 0)'
        );

        DB::statement(
            "ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_tipo_conocido
             CHECK (tipo_item IS NULL OR tipo_item IN (
                 'servicio', 'procedimiento', 'medicamento', 'insumo',
                 'estudio_laboratorio', 'estudio_imagen', 'honorario',
                 'estancia', 'paquete', 'otro'
             ))"
        );

        DB::statement(
            'ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE margenes_objetivo
             ADD CONSTRAINT margenes_objetivo_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('margenes_objetivo');
    }
};
