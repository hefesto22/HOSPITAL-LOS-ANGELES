<?php

declare(strict_types=1);

use App\Domain\Enums\TipoDeAjuste;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes — todo lo que entró o salió sin comprarse ni venderse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA PUERTA, UN SOLO REPORTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * La diferencia de un conteo, el frasco que se rompió, el lote que se
 * venció y la recepción que se digitó mal entran todos por acá. Que sean
 * un tipo y no cuatro tablas es lo que hace que «¿qué se ajustó este mes,
 * quién lo hizo y quién lo autorizó?» sea una consulta con un `WHERE`, y
 * no un `UNION` de cuatro formas distintas que alguien tiene que
 * acordarse de mantener sincronizadas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EXISTIR ES YA HABER MOVIDO EL KARDEX
 * ─────────────────────────────────────────────────────────────────────
 *
 * Igual que `recepciones`: no hay borrador. Una fila acá significa que
 * los movimientos ya están asentados y las cantidades base del costo
 * promedio ya se sincronizaron, todo en la misma transacción.
 *
 * Y por eso es **append-only**, con el mismo trigger que protege al
 * kardex y a `persona_versiones`. Un ajuste que se pudiera editar
 * después dejaría el documento diciendo «se rompieron 2» y el kardex
 * diciendo −40, sin que nada delate cuál de los dos miente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL TOPE VA EN LEMPIRAS, NO EN UNIDADES
 * ─────────────────────────────────────────────────────────────────────
 *
 * `valor_absoluto` es la suma de |cantidad| × costo promedio de cada
 * línea, congelada al asentar. Es contra ese número que se decide si el
 * ajuste necesita autorización de dirección.
 *
 * En unidades no serviría: 500 gasas y 2 ampollas de inmunoglobulina son
 * el mismo número y no son el mismo hecho. Un tope en unidades deja pasar
 * exactamente el ajuste que había que mirar.
 *
 * Se guarda en vez de calcularse porque el costo promedio cambia con cada
 * compra: recalcularlo el año que viene daría otro número y el reporte de
 * ajustes dejaría de cuadrar con el de inventario. Mismo argumento que
 * `saldo_despues` en el kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            /*
             * De qué conteo salió, si salió de uno. Nulo en una merma, un
             * vencimiento o una corrección puntual.
             */
            $tabla->foreignId('conteo_id')->nullable()->constrained('conteos')->restrictOnDelete();

            $tabla->string('tipo', 30);

            $tabla->string('referencia', 120)->nullable();

            /*
             * ─────────────────────────────────────────────────────────
             * EL CINTURÓN CONTRA EL DOBLE CLIC (§8.6.2-3, §13.7)
             * ─────────────────────────────────────────────────────────
             *
             * El ajuste que nace de un conteo ya está protegido por
             * `ajustes_uno_por_conteo`. El que se teclea a mano no tenía
             * nada: guardar dos veces la baja de un lote vencido de
             * L 8.000 daba de baja L 16.000 de inventario que no existía,
             * y como todo es append-only, «corregirlo» significa un
             * tercer documento que compense.
             *
             * La clave la genera la pantalla al montarse, así que el
             * segundo envío del MISMO formulario trae la misma y rebota
             * contra el índice único. El botón deshabilitado es cortesía;
             * el cinturón es la restricción única.
             */
            $tabla->uuid('clave_idempotencia')->nullable();

            /*
             * LA FECHA DE NEGOCIO, explícita y asignada por el servicio
             * (§7.5-4). Todo reporte de ajustes filtra por acá y jamás
             * por `created_at`: la merma del sábado que se digita el lunes
             * es del sábado.
             */
            $tabla->date('fecha_operacion');

            /*
             * Y el instante clínico-operativo, que es lo que va al kardex.
             * Dos tiempos, como en todo evento del sistema (§9.0.4).
             */
            $tabla->timestampTz('ocurrido_en');

            /*
             * Obligatorio y con largo mínimo. El motivo tipificado vive en
             * cada línea; esto es el caso concreto: «se cayó la bandeja de
             * la 3 al trasladar al quirófano».
             */
            $tabla->text('motivo');

            $tabla->decimal('valor_absoluto', 14, 2)->default(0);

            $tabla->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestampTz('autorizado_en')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->timestamps();

            /*
             * Sin `updated_by` ni `deleted_by`: acá nada se actualiza ni
             * se borra. Quién lo asentó es lo único que hay que saber.
             */
            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $tipos = self::comoLista(TipoDeAjuste::valores());
        $diferencia = TipoDeAjuste::DiferenciaDeConteo->value;

        // ── Índices ───────────────────────────────────────────────────

        /*
         * Un conteo genera UN ajuste. Si el cierre se pudiera correr dos
         * veces, la diferencia se asentaría dos veces — y el segundo
         * intento dejaría el estante exactamente el doble de mal.
         */
        DB::statement(
            'CREATE UNIQUE INDEX ajustes_uno_por_conteo
             ON ajustes (conteo_id)
             WHERE conteo_id IS NOT NULL'
        );

        /*
         * Parcial porque los ajustes viejos —y los que nazcan de un
         * comando o de un import— no traen clave, y NULL no puede
         * bloquear a NULL.
         */
        DB::statement(
            'CREATE UNIQUE INDEX ajustes_clave_idempotencia
             ON ajustes (clave_idempotencia)
             WHERE clave_idempotencia IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX ajustes_por_almacen
             ON ajustes (almacen_id, fecha_operacion DESC)'
        );

        DB::statement(
            'CREATE INDEX ajustes_por_tipo
             ON ajustes (tipo, fecha_operacion DESC)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE ajustes
             ADD CONSTRAINT ajustes_tipo_valido
             CHECK (tipo IN ({$tipos}))"
        );

        /*
         * El mismo largo mínimo que exige el CHECK del kardex para un
         * ajuste. Los dos números tienen que ser el mismo: si acá se
         * admitieran 5 caracteres, el documento se guardaría y el
         * movimiento se caería con un error de base sin contexto.
         */
        DB::statement(
            'ALTER TABLE ajustes
             ADD CONSTRAINT ajustes_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );

        DB::statement(
            'ALTER TABLE ajustes
             ADD CONSTRAINT ajustes_valor_no_negativo
             CHECK (valor_absoluto >= 0)'
        );

        DB::statement(
            'ALTER TABLE ajustes
             ADD CONSTRAINT ajustes_autorizacion_completa
             CHECK (
                 (autorizado_por IS NULL AND autorizado_en IS NULL)
                 OR (autorizado_por IS NOT NULL AND autorizado_en IS NOT NULL)
             )'
        );

        /*
         * Colgar una merma de un conteo dejaría el reporte de diferencias
         * contando pérdidas que el conteo nunca vio.
         */
        DB::statement(
            "ALTER TABLE ajustes
             ADD CONSTRAINT ajustes_conteo_solo_en_diferencia
             CHECK (conteo_id IS NULL OR tipo = '{$diferencia}')"
        );

        // ── El ajuste asentado no se toca ─────────────────────────────

        /*
         * Reusa `sihla_rechazar_modificacion()`, la misma función que
         * protege a `persona_versiones` y al kardex. NO se crea acá y NO
         * se dropea en el `down()`: es de otra migración, que corre antes
         * y la borra cuando le toca.
         */
        DB::unprepared(
            'CREATE TRIGGER ajustes_inmutable
             BEFORE UPDATE OR DELETE ON ajustes
             FOR EACH ROW EXECUTE FUNCTION sihla_rechazar_modificacion()'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ajustes_inmutable ON ajustes');

        Schema::dropIfExists('ajustes');
    }

    /**
     * @param list<string> $valores
     */
    private static function comoLista(array $valores): string
    {
        return implode(', ', array_map(
            static fn (string $valor): string => "'{$valor}'",
            $valores,
        ));
    }
};
