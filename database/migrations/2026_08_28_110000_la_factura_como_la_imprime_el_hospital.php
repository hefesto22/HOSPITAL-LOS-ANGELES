<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba a la factura para ser LA factura del hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SALIÓ DE MIRAR TRES FACTURAS REALES
 * ─────────────────────────────────────────────────────────────────────
 *
 * El formulario que el hospital viene imprimiendo pide cosas que el
 * esquema no tenía, y ninguna es decorativa:
 *
 *   · **Vencimiento.** «Contado» vence el mismo día; con un convenio
 *     —Hospital Militar, PALIG— vence a treinta. Es la fecha desde la
 *     que se empieza a contar la mora, y sin ella la cobranza no existe.
 *   · **Importe exonerado, y el ISV partido en 15 % y 18 %.** El
 *     formulario tiene las seis casillas separadas porque el SAR las
 *     pide separadas. Una sola columna «gravado» no se puede desglosar
 *     después: hay que sumarlas por régimen al emitir.
 *   · **Datos del cliente exonerado**: código, orden de compra exenta,
 *     constancia de registro exonerado, registro SAG. Van en blanco casi
 *     siempre; cuando el cliente es una institución exonerada, sin ellos
 *     la factura no le sirve.
 *   · **Comentarios.** Es donde el hospital escribe a mano el copago y
 *     el reembolso del seguro. Ya existía `nota`; esto es lo que se
 *     IMPRIME en ese recuadro.
 *   · **Facturador y términos**, congelados: quién emitió y bajo qué
 *     condición. `created_by` dice quién tecleó, pero un usuario se
 *     puede renombrar y el papel no.
 *
 * ⚠️ El CHECK de totales se rehace: ahora el neto es
 * `exonerado + exento + gravado`, y el gravado tiene que cuadrar con sus
 * dos mitades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->date('vence_el')->nullable()->after('fecha_operacion');

            /* «Contado», «HOSPITAL MILITAR», «Crédito 30 días». */
            $tabla->string('terminos', 60)->nullable()->after('vence_el');

            /* Quién emitió, congelado: el usuario se puede renombrar. */
            $tabla->string('facturador', 120)->nullable()->after('terminos');

            $tabla->decimal('exonerado', 14, 2)->default(0)->after('descuento_comercial');
            $tabla->decimal('gravado_15', 14, 2)->default(0)->after('gravado');
            $tabla->decimal('gravado_18', 14, 2)->default(0)->after('gravado_15');
            $tabla->decimal('isv_15', 14, 2)->default(0)->after('isv');
            $tabla->decimal('isv_18', 14, 2)->default(0)->after('isv_15');

            $tabla->string('cliente_telefono', 30)->nullable()->after('cliente_direccion');
            $tabla->string('cliente_codigo', 40)->nullable()->after('cliente_telefono');
            $tabla->string('cliente_orden_exenta', 40)->nullable()->after('cliente_codigo');
            $tabla->string('cliente_constancia_exonerado', 40)->nullable()->after('cliente_orden_exenta');
            $tabla->string('cliente_registro_sag', 40)->nullable()->after('cliente_constancia_exonerado');

            /* Lo que va impreso en el recuadro «Comentarios». */
            $tabla->string('comentarios', 300)->nullable()->after('nota');
        });

        /*
         * La primera columna del papel es «Cod. Producto», y no estaba.
         * Se congela con el renglón: el código de hoy es el que el
         * cliente va a leer si vuelve con la factura en la mano dentro
         * de dos años.
         */
        Schema::table('factura_lineas', function (Blueprint $tabla): void {
            $tabla->string('codigo', 40)->nullable()->after('cargo_id');
        });

        /*
         * El neto ahora lleva tres baldes: exonerado, exento y gravado.
         * El viejo CHECK no conocía el primero.
         */
        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_bruto_cuadra');

        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_bruto_cuadra
             CHECK (exonerado + exento + gravado = bruto - descuento_legal - descuento_comercial)'
        );

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_totales_cuadran');

        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_totales_cuadran
             CHECK (total = exonerado + exento + gravado + isv)'
        );

        /*
         * Y las mitades tienen que sumar el todo. Sin esto, un renglón al
         * 18 % mal clasificado se esconde: el total sigue cuadrando y la
         * declaración mensual sale con el impuesto en la casilla
         * equivocada.
         */
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_gravado_por_tasa_cuadra
             CHECK (gravado = gravado_15 + gravado_18 AND isv = isv_15 + isv_18)'
        );
    }

    public function down(): void
    {
        Schema::table('factura_lineas', function (Blueprint $tabla): void {
            $tabla->dropColumn('codigo');
        });

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_gravado_por_tasa_cuadra');

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn([
                'vence_el', 'terminos', 'facturador', 'exonerado',
                'gravado_15', 'gravado_18', 'isv_15', 'isv_18',
                'cliente_telefono', 'cliente_codigo', 'cliente_orden_exenta',
                'cliente_constancia_exonerado', 'cliente_registro_sag', 'comentarios',
            ]);
        });

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_bruto_cuadra');
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_bruto_cuadra
             CHECK (exento + gravado = bruto - descuento_legal - descuento_comercial)'
        );

        DB::statement('ALTER TABLE facturas DROP CONSTRAINT facturas_totales_cuadran');
        DB::statement(
            'ALTER TABLE facturas ADD CONSTRAINT facturas_totales_cuadran
             CHECK (total = exento + gravado + isv)'
        );
    }
};
