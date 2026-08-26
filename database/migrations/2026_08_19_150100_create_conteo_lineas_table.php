<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se contó en cada estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CORTE VA POR LÍNEA, NO POR DOCUMENTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un hospital no cierra la bodega para contar. Mientras alguien recorre
 * el estante, farmacia sigue despachando — y ahí está el problema que
 * esta tabla resuelve.
 *
 * `cantidad_sistema` se congela **en el instante en que se teclea el
 * conteo de esa línea**, no al abrir el documento. Y al cerrar no se
 * escribe «el saldo queda en 95»: se asienta la **diferencia**.
 *
 *     Se cuenta 09:14 · sistema 100 · contado 95   → diferencia −5
 *     Mientras tanto  · se dispensan 10            → sistema 90
 *     Al cerrar 11:00 · 90 − 5                     = 85 ✔
 *
 * Con un valor absoluto en vez de una diferencia, esas 10 dispensaciones
 * desaparecerían del saldo y el kardex quedaría diciendo que nunca
 * pasaron. La ventana de error que queda son los segundos entre mirar el
 * estante y teclear el número, y esa es inherente a contar a mano.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NADA QUEDA EN CERO POR OMISIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una línea nace con `cantidad_contada` en NULO, que significa «nadie la
 * contó todavía» — que no es lo mismo que «hay cero». El CHECK
 * `conteo_lineas_conteo_completo` impide que exista un conteo sin su
 * saldo congelado, y el cierre de un conteo total se niega mientras
 * quede una línea pendiente.
 *
 * Interpretar lo no contado como cero es cómo un sistema borra el estante
 * entero de un producto porque el que contaba se fue a almorzar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL RECUENTO REEMPLAZA AL CONTEO, Y VUELVE A CONGELAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cuando la diferencia pasa la tolerancia, la línea exige que alguien
 * vuelva a contar (§9.G4). El segundo conteo **pisa** al primero, con su
 * propio saldo congelado y su propia hora: es una observación nueva, no
 * un parche sobre la vieja. Lo que se contó la primera vez queda en
 * `primer_conteo` para que la diferencia entre las dos lecturas se pueda
 * mirar después — es el dato que dice si el problema fue el estante o el
 * que contaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteo_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('conteo_id')->constrained('conteos')->cascadeOnDelete();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();

            /*
             * Nulo para lo que no lleva lote. La existencia se cuenta al
             * mismo nivel al que se guarda: (ítem, lote, almacén). Contar
             * «acetaminofén» sin distinguir lote haría imposible saber
             * cuál de los dos vencimientos es el que falta.
             */
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();

            /*
             * El saldo que decía el sistema EN EL INSTANTE de contar. Es
             * el corte, y es lo que hace que la diferencia signifique
             * algo tres horas después.
             */
            $tabla->decimal('cantidad_sistema', 14, 4)->nullable();

            /*
             * Lo que se vio en el estante. NULO = pendiente.
             */
            $tabla->decimal('cantidad_contada', 14, 4)->nullable();

            /*
             * La calcula PostgreSQL. Así no existe la posibilidad de que
             * un import, un bug o una mano de más dejen una línea donde
             * 100 y 95 dan −4.
             */
            $tabla->decimal('diferencia', 14, 4)
                ->nullable()
                ->storedAs('cantidad_contada - cantidad_sistema');

            $tabla->timestampTz('contado_en')->nullable();
            $tabla->foreignId('contado_por')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Lo que se contó la PRIMERA vez, si hubo recuento. No se usa
             * para asentar nada: se guarda porque la diferencia entre las
             * dos lecturas es el indicador de calidad del conteo.
             *
             * Con su actor y su hora, y no solo el número: la pregunta
             * que el recuento existe para contestar —«¿el problema era el
             * estante o el que contaba?»— no se puede contestar sin saber
             * quién hizo cada lectura.
             */
            $tabla->decimal('primer_conteo', 14, 4)->nullable();
            $tabla->timestampTz('primer_conteo_en')->nullable();
            $tabla->foreignId('primer_conteo_por')->nullable()->constrained('users')->nullOnDelete();

            $tabla->smallInteger('veces_contado')->default(0);

            $tabla->boolean('exige_recuento')->default(false);

            $tabla->string('notas', 200)->nullable();

            $tabla->timestamps();
        });

        // ── Índices ───────────────────────────────────────────────────

        /*
         * 🔴 Una sola línea por producto y lote dentro del conteo.
         *
         * Sin esto, escanear dos veces el mismo frasco crea dos líneas,
         * las dos congelan el mismo saldo, y el cierre asienta la
         * diferencia dos veces. `COALESCE(lote_id, 0)` por lo de siempre:
         * en SQL `NULL = NULL` no es verdadero, así que sin él dos líneas
         * de un insumo sin lote convivirían tranquilas.
         */
        DB::statement(
            'CREATE UNIQUE INDEX conteo_lineas_unica
             ON conteo_lineas (conteo_id, item_id, (COALESCE(lote_id, 0)))'
        );

        /*
         * La pregunta de la pantalla: ¿qué me falta contar? Parcial, así
         * que ocupa lo que ocupan las pendientes y se vacía sola a medida
         * que avanza el conteo.
         */
        DB::statement(
            'CREATE INDEX conteo_lineas_pendientes
             ON conteo_lineas (conteo_id)
             WHERE cantidad_contada IS NULL'
        );

        /*
         * La pregunta del cierre: ¿qué no cuadró? Es sobre la columna
         * generada, que para el índice es una columna más.
         */
        DB::statement(
            'CREATE INDEX conteo_lineas_con_diferencia
             ON conteo_lineas (conteo_id)
             WHERE diferencia <> 0'
        );

        DB::statement(
            'CREATE INDEX conteo_lineas_exigen_recuento
             ON conteo_lineas (conteo_id)
             WHERE exige_recuento'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE conteo_lineas
             ADD CONSTRAINT conteo_lineas_cantidades_no_negativas
             CHECK (
                 (cantidad_sistema IS NULL OR cantidad_sistema >= 0)
                 AND (cantidad_contada IS NULL OR cantidad_contada >= 0)
                 AND (primer_conteo IS NULL OR primer_conteo >= 0)
             )'
        );

        /*
         * 🔴 O están las cuatro columnas del conteo, o no está ninguna.
         *
         * Es el CHECK que hace imposible el cero implícito: no se puede
         * escribir «contado 0» sin decir contra qué saldo, cuándo y quién.
         */
        DB::statement(
            'ALTER TABLE conteo_lineas
             ADD CONSTRAINT conteo_lineas_conteo_completo
             CHECK (
                 (cantidad_contada IS NULL AND cantidad_sistema IS NULL
                     AND contado_en IS NULL AND contado_por IS NULL)
                 OR (cantidad_contada IS NOT NULL AND cantidad_sistema IS NOT NULL
                     AND contado_en IS NOT NULL AND contado_por IS NOT NULL)
             )'
        );

        DB::statement(
            'ALTER TABLE conteo_lineas
             ADD CONSTRAINT conteo_lineas_veces_contado_coherente
             CHECK (
                 veces_contado >= 0
                 AND (primer_conteo IS NULL OR veces_contado >= 2)
                 AND (cantidad_contada IS NOT NULL OR veces_contado = 0)
                 AND (primer_conteo IS NOT NULL OR primer_conteo_en IS NULL)
                 AND (primer_conteo IS NOT NULL OR primer_conteo_por IS NULL)
             )'
        );

        // ── Solo se escribe mientras el conteo está abierto ────────────

        /*
         * ⚠️ `CREATE OR REPLACE` por lo mismo que en las otras dos
         * migraciones con función: `migrate:fresh` no dropea funciones y
         * la segunda corrida se caería con «function already exists».
         *
         * Cuando el conteo ya no existe se deja pasar el DELETE: es el
         * borrado en cascada de un conteo abierto, y ahí la fila padre ya
         * se fue. Bloquearlo dejaría un conteo imposible de eliminar sin
         * SQL crudo.
         */
        DB::unprepared(
            "CREATE OR REPLACE FUNCTION sihla_exigir_conteo_abierto() RETURNS trigger
             LANGUAGE plpgsql AS \$\$
             DECLARE
                 estado_actual text;
                 conteo bigint;
             BEGIN
                 conteo := COALESCE(NEW.conteo_id, OLD.conteo_id);

                 SELECT c.estado INTO estado_actual FROM conteos c WHERE c.id = conteo;

                 IF estado_actual IS NOT NULL AND estado_actual <> 'abierto' THEN
                     RAISE EXCEPTION USING
                         ERRCODE = '23001',
                         MESSAGE = format(
                             'El conteo %s está %s: sus líneas ya no se tocan.',
                             conteo, estado_actual
                         );
                 END IF;

                 IF TG_OP = 'DELETE' THEN
                     RETURN OLD;
                 END IF;

                 RETURN NEW;
             END;
             \$\$"
        );

        DB::unprepared(
            'CREATE TRIGGER conteo_lineas_solo_con_conteo_abierto
             BEFORE INSERT OR UPDATE OR DELETE ON conteo_lineas
             FOR EACH ROW EXECUTE FUNCTION sihla_exigir_conteo_abierto()'
        );
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS conteo_lineas_solo_con_conteo_abierto ON conteo_lineas'
        );

        Schema::dropIfExists('conteo_lineas');

        DB::unprepared('DROP FUNCTION IF EXISTS sihla_exigir_conteo_abierto()');
    }
};
