<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El rango de numeración que el SAR autorizó, con su CAI.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ ES ESTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El SAR entrega una resolución con: un CAI, un rango de correlativos
 * —del 1 al 5,000, por ejemplo— y una FECHA LÍMITE DE EMISIÓN. Fuera de
 * ese rango o pasada esa fecha, el documento no vale: no es un detalle
 * de formato, es una factura que el cliente no puede usar y que al
 * hospital le cuesta una multa.
 *
 * El número completo se arma con los cuatro segmentos:
 *
 *     000  -  001  -  01  -  00000001
 *     ↑       ↑       ↑      ↑
 *     estab.  punto   tipo   correlativo
 *
 * 🔴 LOS TRES PRIMEROS SEGMENTOS SE COPIAN DE LA RESOLUCIÓN, NO SE
 * DEDUCEN. En especial el TIPO: qué dos dígitos le tocan a la factura y
 * cuáles a la nota de crédito lo dice el SAR, y adivinarlo es emitir con
 * un número que no corresponde al rango autorizado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BLOQUEO DECLARADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Las preguntas #1 y #4 de `docs/dominio.md` siguen abiertas: la
 * vigencia exacta del CAI bajo el Acuerdo 481-2017, y el trámite de
 * autoimpresor. Por eso el CHECK del formato del CAI es PERMISIVO —solo
 * verifica largo— en vez de imponer una máscara que podría rechazar un
 * CAI real. Cuando el SAR conteste, se aprieta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `siguiente` ES UNA COLUMNA, Y ACÁ SÍ CORRESPONDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo lo demás en este sistema se deriva (§9.G1). Un correlativo fiscal
 * no: tiene que poder tomarse con `SELECT … FOR UPDATE` y avanzar en la
 * misma transacción, porque dos cajas emitiendo al mismo tiempo NO
 * pueden sacar el mismo número. Derivarlo de `MAX(correlativo)` deja esa
 * ventana abierta, y el resultado son dos facturas con el mismo número
 * ante el SAR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rangos_cai', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            $tabla->string('tipo', 20);

            $tabla->string('cai', 40);

            /*
             * Los tres segmentos de la izquierda, tal como vienen en la
             * resolución. `char` y no `int`: son códigos con ceros a la
             * izquierda —«001» no es 1— y guardarlos como número los
             * pierde.
             */
            $tabla->char('establecimiento', 3);
            $tabla->char('punto_emision', 3);
            $tabla->char('tipo_codigo', 2);

            $tabla->unsignedBigInteger('desde');
            $tabla->unsignedBigInteger('hasta');
            $tabla->unsignedBigInteger('siguiente');

            $tabla->date('fecha_limite_emision');

            /*
             * Solo uno activo por sede, tipo y punto de emisión. Es el
             * que la caja consume; los demás quedan como historia.
             */
            $tabla->boolean('activo')->default(true);

            $tabla->string('resolucion', 60)->nullable();
            $tabla->string('nota', 300)->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX rangos_cai_unico ON rangos_cai (cai)');

        DB::statement(
            'CREATE UNIQUE INDEX rangos_cai_uno_activo
             ON rangos_cai (sede_id, tipo, punto_emision)
             WHERE activo'
        );

        DB::statement('CREATE INDEX rangos_cai_por_vencer ON rangos_cai (fecha_limite_emision) WHERE activo');

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE rangos_cai ADD CONSTRAINT rangos_cai_tipo_conocido
             CHECK (tipo IN ('factura', 'nota_de_credito', 'nota_de_debito'))"
        );

        /*
         * Permisivo a propósito mientras la pregunta #1 siga abierta: el
         * CAI son 32 caracteres hexadecimales, pero cómo se agrupan con
         * guiones es lo que hay que confirmar con el SAR.
         */
        DB::statement(
            'ALTER TABLE rangos_cai ADD CONSTRAINT rangos_cai_formato
             CHECK (length(btrim(cai)) BETWEEN 32 AND 40)'
        );

        DB::statement(
            "ALTER TABLE rangos_cai ADD CONSTRAINT rangos_cai_segmentos_numericos
             CHECK (establecimiento ~ '^[0-9]{3}$'
                AND punto_emision ~ '^[0-9]{3}$'
                AND tipo_codigo ~ '^[0-9]{2}$')"
        );

        DB::statement(
            'ALTER TABLE rangos_cai ADD CONSTRAINT rangos_cai_rango_valido
             CHECK (desde >= 1 AND hasta >= desde)'
        );

        /*
         * `siguiente` puede quedar en `hasta + 1`: eso ES el rango
         * agotado. Fuera de ahí, el correlativo se salió de lo
         * autorizado y ninguna factura debería existir con ese número.
         */
        DB::statement(
            'ALTER TABLE rangos_cai ADD CONSTRAINT rangos_cai_siguiente_en_rango
             CHECK (siguiente >= desde AND siguiente <= hasta + 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rangos_cai');
    }
};
