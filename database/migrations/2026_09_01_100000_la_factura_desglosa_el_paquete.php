<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La factura de una cirugía puede salir renglón por renglón.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ DECIDE CADA COLUMNA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `convenios.desglosa_paquetes` — la PREFERENCIA del pagador. Una
 * aseguradora que adjudica renglón por renglón necesita ver los
 * renglones; al paciente de contado le sirve más el papel corto. Es
 * dato y no código porque el convenio que se firme el mes que viene
 * tiene que poder llegar con la suya sin un despliegue (§1.1).
 *
 * `facturas.paquetes_desglosados` — LO QUE PASÓ en esta factura.
 *
 * `factura_lineas.encabezado` — el renglón que nombra la cirugía y no
 * cobra nada. Ver abajo por qué no puede ser simplemente una fila con
 * ceros.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ LA DECISIÓN SE CONGELA Y NO SE LEE AL IMPRIMIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * La tentación es leer la preferencia del convenio al imprimir y no
 * guardar nada. **No.** La factura es inmutable y lleva número de CAI:
 * si el papel se armara con la preferencia del día, cambiarla haría que
 * el MISMO CORRELATIVO produjera dos documentos distintos — que es
 * exactamente lo que el SAR audita cuando revisa una secuencia.
 *
 * Así que se congela al emitir, igual que el precio y el nombre del
 * cliente, y de ahí el papel sale siempre igual.
 *
 * Y la columna guarda lo que OCURRIÓ, no lo que se pidió: si se pidió
 * desglosar y el cargo no tenía de dónde —una cirugía sin presupuesto, o
 * una donde todavía no se entregó nada— la factura sale con el renglón
 * del paquete y acá queda `false`. Un `true` que no se corresponde con
 * las líneas sería peor que no tener la columna.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL ENCABEZADO ES UNA BANDERA, NO UNA FILA DE CEROS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Desglosada, la factura pierde la palabra «APENDICECTOMIA»: quedan
 * diecinueve renglones sueltos y ni el paciente ni el ajustador saben de
 * qué procedimiento hablan. Así que arriba va un renglón que la nombra,
 * con su número de presupuesto, y que no cobra nada.
 *
 * No alcanza con ponerle ceros y confiar:
 *
 *   · `factura_lineas_cantidad_no_cero` RECHAZA cantidad 0, así que la
 *     fila igual lleva un 1 — y sin bandera el papel imprimiría
 *     «1 · 0.00 · 0.00 · 0.00» al lado del nombre de la cirugía, que
 *     parece una línea que falló, no un título.
 *   · Y sobre todo: sin marca explícita, nada impide que mañana alguien
 *     le meta un importe a un renglón que el ojo lee como título. El
 *     CHECK de abajo hace que eso sea imposible, no improbable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenios', function (Blueprint $tabla): void {
            $tabla->boolean('desglosa_paquetes')->default(false)->after('cubre_por_defecto');
        });

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->boolean('paquetes_desglosados')->default(false)->after('lineas');
        });

        Schema::table('factura_lineas', function (Blueprint $tabla): void {
            $tabla->boolean('encabezado')->default(false)->after('orden');
        });

        /*
         * Un título no cobra. Ni hoy ni por accidente dentro de dos años.
         */
        DB::statement(
            'ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_encabezado_no_cobra
             CHECK (
                 NOT encabezado
                 OR (bruto = 0 AND descuento_legal = 0 AND descuento_comercial = 0
                     AND exento = 0 AND gravado = 0 AND isv = 0 AND total = 0)
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE factura_lineas DROP CONSTRAINT IF EXISTS factura_lineas_encabezado_no_cobra');

        Schema::table('factura_lineas', function (Blueprint $tabla): void {
            $tabla->dropColumn('encabezado');
        });

        Schema::table('facturas', function (Blueprint $tabla): void {
            $tabla->dropColumn('paquetes_desglosados');
        });

        Schema::table('convenios', function (Blueprint $tabla): void {
            $tabla->dropColumn('desglosa_paquetes');
        });
    }
};
