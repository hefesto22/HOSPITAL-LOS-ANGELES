<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo pactado con el convenio cuando NO hay precio negociado ítem por ítem.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO VIVE EN `convenios`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una columna en la tabla del convenio habría sido un renglón menos. Pero
 * el porcentaje pactado **se renegocia**: cada renovación cambia el
 * número, y si se sobreescribe, las facturas del año pasado dejan de
 * poder explicarse. Con vigencia propia, «¿por qué en marzo le cobramos
 * esto al IHSS?» se contesta con una fila fechada.
 *
 * Es la misma razón por la que el margen objetivo no vive en
 * `config/sihla.php`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SIGNIFICA EL NÚMERO: LO QUE PAGA, NO LO QUE DESCUENTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `factor_sobre_lista` es **la fracción de la lista que el pagador
 * paga**: `0.8500` = paga el 85 %, o sea lista menos 15 %.
 *
 * Guardar el descuento (`0.1500`) habría sido más parecido a como se
 * habla —«el IHSS paga lista menos 15 %»—, pero obliga a un signo
 * negativo el día que un convenio pague MÁS que la lista, cosa que pasa
 * cuando el tarifario institucional es más alto. Con el factor, ese caso
 * es `1.1000` y se lee solo. La pantalla sigue mostrando «−15 %» al lado,
 * que es como lo piensa quien negocia.
 *
 * Por eso el CHECK es `> 0` y no `<= 1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_condiciones', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('convenio_id')->constrained('convenios')->restrictOnDelete();

            /*
             * NUMERIC(6,4): hasta 99.9999. Cuatro decimales porque un
             * pactado de 87.5 % existe y redondearlo a 88 % le cambia el
             * precio a todo lo que ese convenio consume.
             */
            $tabla->decimal('factor_sobre_lista', 6, 4);

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
            "ALTER TABLE convenio_condiciones
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        /*
         * Sin `COALESCE` acá: `convenio_id` es NOT NULL, así que el
         * agujero del `NULL = NULL` no existe en esta tabla.
         */
        DB::statement(
            'ALTER TABLE convenio_condiciones
             ADD CONSTRAINT convenio_condiciones_sin_traslape
             EXCLUDE USING gist (convenio_id WITH =, vigencia WITH &&)
             WHERE (deleted_at IS NULL)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        /*
         * Cero dejaría todo el catálogo gratis para ese pagador. Si de
         * verdad un convenio no cobra nada, eso no es un factor: es otra
         * decisión y otra conversación.
         */
        DB::statement(
            'ALTER TABLE convenio_condiciones
             ADD CONSTRAINT convenio_condiciones_factor_positivo
             CHECK (factor_sobre_lista > 0)'
        );

        DB::statement(
            'ALTER TABLE convenio_condiciones
             ADD CONSTRAINT convenio_condiciones_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE convenio_condiciones
             ADD CONSTRAINT convenio_condiciones_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('convenio_condiciones');
    }
};
