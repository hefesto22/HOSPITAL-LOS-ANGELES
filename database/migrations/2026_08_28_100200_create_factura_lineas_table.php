<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada renglón impreso de la factura, congelado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE LEE DE `cargos` AL REIMPRIMIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque el papel dice lo que decía el día que se imprimió. El ítem se
 * puede haber renombrado, el precio cambiado y hasta el cargo anulado
 * con su reversa: nada de eso puede cambiar una factura ya entregada.
 *
 * `cargo_id` viaja SIN FK —`cargos` está particionada y su PK es
 * `(id, fecha_operacion)`— y sirve para rastrear de vuelta, no para
 * armar el documento.
 *
 * ⚠️ El renglón guarda su propio régimen de ISV. Es lo que permite
 * imprimir el desglose exento/gravado que el SAR exige, sin volver a
 * preguntarle al catálogo qué régimen tiene hoy ese ítem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factura_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();

            $tabla->integer('orden')->default(0);

            $tabla->unsignedBigInteger('cargo_id')->nullable();

            $tabla->string('descripcion', 200);

            $tabla->decimal('cantidad', 14, 4);
            $tabla->decimal('precio_unitario', 14, 4);

            $tabla->decimal('bruto', 14, 2);
            $tabla->decimal('descuento_legal', 14, 2)->default(0);
            $tabla->decimal('descuento_comercial', 14, 2)->default(0);

            $tabla->string('regimen_isv', 20);
            $tabla->decimal('tasa_isv', 6, 4)->default(0);

            $tabla->decimal('exento', 14, 2)->default(0);
            $tabla->decimal('gravado', 14, 2)->default(0);
            $tabla->decimal('isv', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2);

            $tabla->timestamps();
        });

        DB::statement('CREATE INDEX factura_lineas_de_la_factura ON factura_lineas (factura_id, orden)');
        DB::statement('CREATE INDEX factura_lineas_por_cargo ON factura_lineas (cargo_id) WHERE cargo_id IS NOT NULL');

        DB::statement(
            'ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_cantidad_no_cero CHECK (cantidad <> 0)'
        );

        DB::statement(
            'ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_totales_cuadran
             CHECK (total = exento + gravado + isv)'
        );

        DB::statement(
            'ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_bruto_cuadra
             CHECK (exento + gravado = bruto - descuento_legal - descuento_comercial)'
        );

        DB::statement(
            "ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_regimen_conocido
             CHECK (regimen_isv IN ('exento', 'gravado_15', 'gravado_18', 'exonerado'))"
        );

        /*
         * Un renglón exento con ISV, o uno gravado que declara base
         * exenta, son las dos formas de armar mal el desglose que el SAR
         * revisa primero.
         */
        DB::statement(
            "ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_exento_sin_isv
             CHECK (regimen_isv NOT IN ('exento', 'exonerado') OR (gravado = 0 AND isv = 0))"
        );

        DB::statement(
            "ALTER TABLE factura_lineas ADD CONSTRAINT factura_lineas_gravado_sin_exento
             CHECK (regimen_isv IN ('exento', 'exonerado') OR exento = 0)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_lineas');
    }
};
