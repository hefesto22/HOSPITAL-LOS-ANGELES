<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién paga — el pagador de cada cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CONTADO TAMBIÉN ES UN CONVENIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El paciente que paga en caja no es "la ausencia de convenio": es una
 * fila más, con su código y su vigencia. Si fuera un nulo, cada consulta
 * de precio tendría que preguntar «¿hay convenio? si no, la lista», y esa
 * pregunta se olvida en algún lado. Con CONTADO como fila, el precio
 * siempre se resuelve igual: por convenio, siempre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA COLUMNA QUE OBLIGA A DECIDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * `base_descuento_legal` es NOT NULL y **sin default**, y eso es lo más
 * importante de esta tabla. El Art. 30 del Decreto 199-2006 no dice sobre
 * qué monto se calcula el descuento del adulto mayor cuando la factura la
 * paga un seguro; hay tres lecturas defendibles y dan facturas distintas.
 *
 * Un default —cualquiera— convertiría esa duda en una regla que el
 * sistema aplica en silencio y que nadie recuerda haber elegido. Al no
 * haberlo, dar de alta un convenio obliga a leer las tres opciones y a
 * escribir el fundamento. Cuando el SAR o un paciente pregunten, la
 * respuesta es una fila con nombre, fecha y motivo.
 *
 * ⚠️ Deuda declarada: el fundamento vive en la fila del convenio y no en
 * una tabla con vigencia propia. Cambiarlo queda registrado por la
 * bitácora y por `updated_by`, pero no reconstruye «qué criterio regía en
 * marzo». Si el abogado llega a una respuesta que cambie con el tiempo,
 * esto se muda a la tabla de condiciones del convenio, que es donde el
 * incremento 2e ya va a llevar el porcentaje pactado con su vigencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Corto, en mayúsculas y sin espacios: CONTADO, IHSS,
             * MILITAR, ATLANTIDA. Es lo que se teclea en admisión a las
             * tres de la mañana, así que no puede depender de acentos.
             */
            $tabla->string('codigo', 20);
            $tabla->string('nombre', 120);

            $tabla->string('tipo', 30);

            // ── La decisión legal, obligatoria y explicada ────────────
            $tabla->string('base_descuento_legal', 40);
            $tabla->text('fundamento_descuento');

            // ── Identificación fiscal y contacto ──────────────────────
            $tabla->string('rtn', 20)->nullable();
            $tabla->string('contacto', 120)->nullable();
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('correo', 120)->nullable();

            // ── Condiciones operativas ────────────────────────────────

            /*
             * Sin autorización previa, el hospital atiende y después
             * descubre que el pagador no cubría. La factura queda en el
             * aire y el que reclama es el paciente.
             */
            $tabla->boolean('requiere_autorizacion')->default(false);

            /*
             * Nulo = se cobra al momento. Un CHECK impide fiarle al
             * contado, que es una contradicción en los términos.
             */
            $tabla->smallInteger('dias_credito')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * Único PARCIAL: un convenio dado de baja no bloquea el código
         * para siempre. Si mañana se vuelve a firmar con la misma
         * aseguradora, el código vuelve a estar libre.
         */
        DB::statement(
            'CREATE UNIQUE INDEX convenios_codigo_unico
             ON convenios (codigo)
             WHERE deleted_at IS NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        /*
         * La canonicalización también vive en el modelo
         * (`GuardaEnMayusculas`) y en el formulario. Acá está por la
         * misma razón de siempre: el formulario no es la única puerta —
         * un import de padrón o un comando escriben directo.
         */
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_codigo_canonico
             CHECK (codigo = upper(codigo) AND codigo !~ '\\s' AND length(btrim(codigo)) >= 3)"
        );

        DB::statement(
            'ALTER TABLE convenios
             ADD CONSTRAINT convenios_nombre_no_vacio
             CHECK (length(btrim(nombre)) >= 3)'
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_tipo_conocido
             CHECK (tipo IN ('contado', 'aseguradora_privada', 'seguridad_social', 'institucional'))"
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_base_descuento_conocida
             CHECK (base_descuento_legal IN (
                 'sobre_lo_que_paga_el_paciente', 'sobre_el_total_facturado', 'no_aplica'
             ))"
        );

        /*
         * El fundamento no puede ser "n/a". Un convenio con la decisión
         * legal tomada y sin explicación es exactamente el papel que
         * nadie se anima a contradecir dos años después.
         */
        DB::statement(
            'ALTER TABLE convenios
             ADD CONSTRAINT convenios_fundamento_explicado
             CHECK (length(btrim(fundamento_descuento)) >= 10)'
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_contado_sin_credito
             CHECK (tipo <> 'contado' OR dias_credito IS NULL)"
        );

        DB::statement(
            'ALTER TABLE convenios
             ADD CONSTRAINT convenios_credito_no_negativo
             CHECK (dias_credito IS NULL OR dias_credito >= 0)'
        );

        DB::statement(
            'ALTER TABLE convenios
             ADD CONSTRAINT convenios_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        /*
         * Mismas reglas que `RTNField` y `TelefonoHondurasField`, acá
         * abajo. Un RTN mal cargado no se descubre al guardarlo: se
         * descubre cuando el SAR rechaza la declaración del mes.
         */
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_rtn_valido
             CHECK (rtn IS NULL OR rtn ~ '^[0-9]{14}$')"
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_telefono_valido
             CHECK (telefono IS NULL OR telefono ~ '^[239][0-9]{7}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('convenios');
    }
};
