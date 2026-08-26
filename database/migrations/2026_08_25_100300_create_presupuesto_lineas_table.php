<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las líneas del presupuesto — la cotización congelada (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRECIO SE CONGELA ACÁ, IGUAL QUE EN EL CARGO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el tarifario sube mañana, el papel que la familia firmó no puede
 * cambiar. Por eso la línea guarda el NÚMERO —precio, tarifario, régimen
 * de ISV, descuento legal— y no una referencia que se vuelva a leer.
 *
 * Es el mismo snapshot del §8.5-5, por la misma razón, con una
 * diferencia: el cargo lo congela porque es fiscal; el presupuesto lo
 * congela porque es un compromiso comercial.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES ORÍGENES QUE SE VEN IGUAL EN EL PAPEL
 * ─────────────────────────────────────────────────────────────────────
 *
 * `catalogo` — el precio salió del tarifario. Exige ítem.
 * `manual`   — el honorario del cirujano, que cambia por médico. Puede
 *              tener ítem: es un ítem del catálogo con precio acordado.
 * `holgura`  — el colchón. NUNCA tiene ítem: no es nada que se dispense.
 *
 * Sin esta columna, el reporte de varianza no puede separar «cotizamos
 * mal» de «el cirujano cobró más de lo que dijo», que son dos problemas
 * con dos dueños distintos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA HOLGURA ES UNA LÍNEA, NO UN PORCENTAJE ESCONDIDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el estimado real es 36,500 y se cotiza 40,000, los 3,500 se ven.
 * Repartirlos inflando los precios de las otras líneas dejaría un papel
 * donde cada renglón miente un poco — y es el mismo mecanismo que se
 * descartó para el descuento del adulto mayor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuesto_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('presupuesto_id')->constrained('presupuestos')->cascadeOnDelete();

            $tabla->integer('orden')->default(0);

            $tabla->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();

            $tabla->string('origen', 20);

            /*
             * El nombre CONGELADO. El catálogo se renombra y se corrige;
             * el presupuesto de hace tres meses tiene que seguir diciendo
             * lo que decía cuando se firmó.
             */
            $tabla->string('texto', 200);

            $tabla->decimal('cantidad', 14, 4);
            $tabla->foreignId('unidad_id')->nullable()->constrained('unidades')->restrictOnDelete();

            // ── Snapshot de precio ────────────────────────────────────
            $tabla->decimal('precio_unitario', 14, 4);
            $tabla->foreignId('tarifario_id')->nullable()->constrained('tarifarios')->restrictOnDelete();
            $tabla->string('origen_precio', 30)->nullable();

            /*
             * El descuento de ley del adulto mayor también se cotiza. Un
             * presupuesto que lo ignora le pide a un señor de 70 años más
             * de lo que va a pagar — y esa diferencia la descubre en caja,
             * que es el peor lugar para descubrir cualquier cosa.
             */
            $tabla->string('categoria_legal', 30)->nullable();
            $tabla->decimal('descuento_legal_fraccion', 6, 4)->default(0);
            $tabla->decimal('descuento', 14, 2)->default(0);

            // ── Snapshot de ISV, por línea (§8.6.1) ───────────────────
            $tabla->string('regimen_isv', 20);
            $tabla->decimal('tasa_isv', 5, 4)->default(0);

            $tabla->decimal('bruto', 14, 2);
            $tabla->decimal('subtotal', 14, 2);
            $tabla->decimal('base_exenta', 14, 2)->default(0);
            $tabla->decimal('base_gravada', 14, 2)->default(0);
            $tabla->decimal('isv', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2);

            $tabla->boolean('opcional')->default(false);
            $tabla->string('nota', 200)->nullable();

            $tabla->timestamps();
        });

        DB::statement('CREATE INDEX presupuesto_lineas_orden ON presupuesto_lineas (presupuesto_id, orden)');

        /*
         * Para el cotejo contra los cargos reales: se agrupa por ítem y
         * se compara con lo que efectivamente se cargó.
         */
        DB::statement(
            'CREATE INDEX presupuesto_lineas_por_item ON presupuesto_lineas (item_id) WHERE item_id IS NOT NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            "ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_origen_conocido
             CHECK (origen IN ('catalogo', 'manual', 'holgura'))"
        );

        DB::statement(
            "ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_catalogo_exige_item
             CHECK (origen <> 'catalogo' OR item_id IS NOT NULL)"
        );

        DB::statement(
            "ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_holgura_sin_item
             CHECK (origen <> 'holgura' OR item_id IS NULL)"
        );

        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_cantidad_positiva
             CHECK (cantidad > 0)'
        );

        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_precio_no_negativo
             CHECK (precio_unitario >= 0 AND descuento >= 0)'
        );

        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_texto_no_vacio
             CHECK (length(btrim(texto)) >= 2)'
        );

        /*
         * Los tres cruces de la línea, verificados por la base en cada
         * escritura. Son los mismos que hacen comparable el total del
         * presupuesto con el total de la cuenta.
         */
        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_subtotal_cuadra
             CHECK (subtotal = bruto - descuento)'
        );

        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_bases_cuadran
             CHECK (base_exenta + base_gravada = subtotal)'
        );

        DB::statement(
            'ALTER TABLE presupuesto_lineas ADD CONSTRAINT presupuesto_lineas_total_cuadra
             CHECK (total = subtotal + isv)'
        );

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL EMITIDO NO SE TOCA — Y LO VERIFICA LA BASE
         * ─────────────────────────────────────────────────────────────
         *
         * El Service ya lo impide, pero un `tinker`, un seeder apurado o
         * un import futuro no pasan por el Service. Acá el trigger sí.
         *
         * El `NOT FOUND` deja pasar el borrado en cascada: cuando se
         * elimina el presupuesto entero, sus líneas se van con él sin
         * que este trigger las defienda de su propio padre.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION presupuesto_lineas_solo_en_borrador()
            RETURNS trigger AS $$
            DECLARE
                id_padre bigint;
                estado_padre varchar(20);
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    id_padre := OLD.presupuesto_id;
                ELSE
                    id_padre := NEW.presupuesto_id;
                END IF;

                SELECT estado INTO estado_padre FROM presupuestos WHERE id = id_padre;

                IF NOT FOUND THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;

                IF estado_padre <> 'borrador' THEN
                    RAISE EXCEPTION
                        'El presupuesto % está en estado "%" y sus líneas ya no se tocan. Corregir es emitir uno nuevo que lo sustituya (ADR-0008).',
                        id_padre, estado_padre;
                END IF;

                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER presupuesto_lineas_inmutables
            BEFORE INSERT OR UPDATE OR DELETE ON presupuesto_lineas
            FOR EACH ROW EXECUTE FUNCTION presupuesto_lineas_solo_en_borrador();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS presupuesto_lineas_inmutables ON presupuesto_lineas');
        DB::unprepared('DROP FUNCTION IF EXISTS presupuesto_lineas_solo_en_borrador()');

        Schema::dropIfExists('presupuesto_lineas');
    }
};
