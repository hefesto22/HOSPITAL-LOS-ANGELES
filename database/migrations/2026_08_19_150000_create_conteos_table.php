<?php

declare(strict_types=1);

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El conteo físico — el documento, no la existencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CONTAR NO ES AJUSTAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Esta tabla es el **instrumento de medición**: qué almacén, quién contó,
 * cuándo, y qué encontró en cada estante. No mueve un solo gramo de
 * inventario.
 *
 * Mover el inventario es otra cosa y tiene su propio documento
 * (`ajustes`). Al cerrar, el conteo genera UNO, con sus líneas, y ahí sí
 * el kardex se mueve. Separarlos es lo que permite contestar por separado
 * las dos preguntas que una auditoría hace siempre: «¿qué contaron?» y
 * «¿qué asentaron?». Si fueran la misma tabla, la segunda respuesta
 * taparía a la primera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN SOLO CONTEO ABIERTO POR ALMACÉN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es un índice único parcial y es de lo más importante de esta migración.
 * Con dos conteos abiertos sobre el mismo estante, el mismo producto se
 * cuenta en los dos, los dos congelan el saldo, y al cerrar el segundo
 * asienta otra vez una diferencia que el primero ya corrigió. El
 * inventario termina el doble de mal que antes de contar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE BORRA, NO SE EDITA DESPUÉS DE CERRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Sin `softDeletes`: un conteo se anula con motivo, y anulado queda
 * visible. Un conteo cerrado explica movimientos del kardex que ya no se
 * pueden tocar, así que él tampoco (§9.0.3). El trigger de abajo lo
 * impide en la base, no en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * A qué estante. El saldo y el costo son por almacén, así que
             * un conteo pertenece a uno solo: contar «el hospital» no
             * significa nada operativamente, porque nadie camina dos
             * bodegas con la misma planilla.
             *
             * Sin `sede_id`: sale del almacén, igual que en existencias,
             * kardex y recepciones. Dos caminos a la misma respuesta es
             * cómo aparecen dos respuestas distintas.
             */
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            $tabla->string('estado', 20)->default(EstadoConteo::Abierto->value);
            $tabla->string('alcance', 20);

            $tabla->string('descripcion', 160)->nullable();

            /*
             * A partir de qué diferencia (en unidades) la línea exige que
             * alguien vuelva a contar antes de poder cerrar.
             *
             * Cero significa «cualquier diferencia exige recuento», que
             * es lo correcto para controlados y para implantes caros. El
             * default sale de la configuración de la sede, no de acá.
             */
            $tabla->decimal('tolerancia_recuento', 14, 4)->default(0);

            $tabla->timestampTz('abierto_en');

            $tabla->timestampTz('cerrado_en')->nullable();
            $tabla->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestampTz('anulado_en')->nullable();
            $tabla->text('motivo_anulacion')->nullable();

            /*
             * Lo que el cierre encontró y NO pudo asentar: diferencias de
             * medicamentos controlados, y líneas cuya existencia cambió
             * tanto entre el conteo y el cierre que el ajuste ya no cabía.
             *
             * Va acá y no en el ajuste porque el ajuste puede no existir:
             * un conteo de estupefacientes por turno donde el único
             * descuadre es de controlados no genera ningún ajuste, y ese
             * es exactamente el caso en el que el hallazgo NO se puede
             * perder. Una notificación en pantalla muere con la sesión.
             */
            $tabla->text('notas_del_cierre')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->timestamps();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $estados = self::comoLista(EstadoConteo::valores());
        $alcances = self::comoLista(AlcanceDeConteo::valores());
        $abierto = EstadoConteo::Abierto->value;
        $cerrado = EstadoConteo::Cerrado->value;
        $anulado = EstadoConteo::Anulado->value;

        // ── Índices ───────────────────────────────────────────────────

        /*
         * 🔴 Uno solo abierto por almacén. Ver el encabezado: sin esto,
         * dos conteos simultáneos asientan la misma diferencia dos veces.
         *
         * Es PARCIAL, así que no estorba al historial: un almacén puede
         * tener cien conteos cerrados y seguir abriendo el siguiente.
         */
        DB::statement(
            "CREATE UNIQUE INDEX conteos_uno_abierto_por_almacen
             ON conteos (almacen_id)
             WHERE estado = '{$abierto}'"
        );

        DB::statement(
            'CREATE INDEX conteos_por_almacen
             ON conteos (almacen_id, abierto_en DESC)'
        );

        DB::statement(
            "CREATE INDEX conteos_abiertos
             ON conteos (abierto_en DESC)
             WHERE estado = '{$abierto}'"
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE conteos
             ADD CONSTRAINT conteos_estado_valido
             CHECK (estado IN ({$estados}))"
        );

        DB::statement(
            "ALTER TABLE conteos
             ADD CONSTRAINT conteos_alcance_valido
             CHECK (alcance IN ({$alcances}))"
        );

        DB::statement(
            'ALTER TABLE conteos
             ADD CONSTRAINT conteos_tolerancia_no_negativa
             CHECK (tolerancia_recuento >= 0)'
        );

        /*
         * Cerrado sin fecha ni firma es un conteo que no se sabe quién
         * cerró — y cerrar es lo que mueve el kardex.
         */
        DB::statement(
            "ALTER TABLE conteos
             ADD CONSTRAINT conteos_cierre_completo
             CHECK (
                 (estado = '{$cerrado}' AND cerrado_en IS NOT NULL AND cerrado_por IS NOT NULL)
                 OR (estado <> '{$cerrado}' AND cerrado_en IS NULL AND cerrado_por IS NULL)
             )"
        );

        DB::statement(
            "ALTER TABLE conteos
             ADD CONSTRAINT conteos_anulacion_completa
             CHECK (
                 (estado = '{$anulado}' AND anulado_en IS NOT NULL
                     AND length(btrim(COALESCE(motivo_anulacion, ''))) >= 10)
                 OR (estado <> '{$anulado}' AND anulado_en IS NULL AND motivo_anulacion IS NULL)
             )"
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * CUATRO OJOS: NO CIERRA EL QUE CONTÓ
         * ─────────────────────────────────────────────────────────────
         *
         * Misma forma exacta que `recepciones` y que `fusiones_de_persona`.
         * Acá pesa más que en las dos: cerrar un conteo asienta faltantes,
         * y un faltante asentado por la misma persona que dijo haber
         * contado es un faltante que nadie verificó.
         *
         * `created_by IS NULL` se admite porque un conteo abierto por un
         * comando o un seeder no tiene autor; en ese caso el candado lo
         * pone el servicio.
         */
        DB::statement(
            'ALTER TABLE conteos
             ADD CONSTRAINT conteos_cuatro_ojos
             CHECK (cerrado_por IS NULL OR created_by IS NULL OR cerrado_por <> created_by)'
        );

        // ── El conteo cerrado no se toca ──────────────────────────────

        /*
         * ⚠️ `CREATE OR REPLACE`, y no `CREATE` a secas, por la razón que
         * ya está documentada en la migración del kardex: `migrate:fresh`
         * dropea tablas, vistas y tipos, pero NO funciones. La segunda
         * corrida se caería entera con «function already exists», y en la
         * suite en paralelo eso son catorce bases fallando por algo que
         * en la primera corrida se veía verde.
         *
         * No se puede reusar `sihla_rechazar_modificacion()` porque
         * aquella rechaza SIEMPRE, y acá el conteo tiene que poder pasar
         * de abierto a cerrado. La condición va en el `WHEN` del trigger.
         */
        DB::unprepared(
            "CREATE OR REPLACE FUNCTION sihla_rechazar_conteo_terminado() RETURNS trigger
             LANGUAGE plpgsql AS \$\$
             BEGIN
                 RAISE EXCEPTION USING
                     ERRCODE = '23001',
                     MESSAGE = format(
                         'El conteo %s está %s: un conteo terminado no se edita ni se borra.',
                         OLD.id, OLD.estado
                     );
             END;
             \$\$"
        );

        DB::unprepared(
            "CREATE TRIGGER conteos_terminados_inmutables
             BEFORE UPDATE OR DELETE ON conteos
             FOR EACH ROW
             WHEN (OLD.estado IN ('{$cerrado}', '{$anulado}'))
             EXECUTE FUNCTION sihla_rechazar_conteo_terminado()"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS conteos_terminados_inmutables ON conteos');

        Schema::dropIfExists('conteos');

        /*
         * Esta función SÍ se dropea acá: nació en esta migración y no la
         * usa nadie más. Es lo contrario de `sihla_rechazar_modificacion()`,
         * que es de `persona_versiones` y por eso el kardex no la toca.
         */
        DB::unprepared('DROP FUNCTION IF EXISTS sihla_rechazar_conteo_terminado()');
    }

    /**
     * Los valores del enum, entrecomillados para el CHECK.
     *
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
