<?php

declare(strict_types=1);

use App\Domain\Enums\TipoMovimiento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LOS EXÁMENES GASTAN REACTIVOS (16-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «son cajas que vienen en kit para x cantidad de pruebas…
 * una caja de 25 kits es para 25 exámenes; se usa 1, quedan 24; ingresa
 * otra caja de 26, serían 50».
 *
 * El examen y el kit son dos cosas distintas, y por eso dos tablas:
 *
 *   · `consumos_de_servicio` — la RECETA, que se configura: qué gasta
 *     cada examen y cuánto. GLUCOSA gasta 1 prueba del kit de glucosa;
 *     BILIRRUBINA gasta 1 de total y 1 de directa; GLUCOSA POST PRANDIAL
 *     gasta del mismo kit que GLUCOSA. Por eso no alcanza con que el
 *     examen sea él mismo el inventario.
 *
 *   · `consumos_de_cargo` — lo que de verdad se GASTÓ cuando se cargó el
 *     examen: qué kit, de qué lote, cuánto, con qué movimiento de kardex.
 *     Es la trazabilidad lote → paciente (§9.F9) y lo que se devuelve si
 *     el cargo se anula. Con el kit en stock infinito queda igual, sin
 *     movimiento: es lo que dice cuánto se usó mientras no se cuenta.
 *
 * Y el kardex aprende un tipo nuevo, `salida_por_consumo_de_servicio`:
 * el kit no se le entregó al paciente, así que no es una dispensación.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rehacerElCheckDelKardex();

        Schema::create('consumos_de_servicio', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * El examen (o el servicio) que gasta, y lo que gasta. Los dos
             * son ítems del mismo catálogo (§8.4): el examen se cobra, el
             * kit se guarda.
             */
            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('insumo_id')->constrained('items')->restrictOnDelete();

            /*
             * En la unidad del kit —pruebas—. NUMERIC y no entero: un
             * reactivo que rinde dos exámenes por prueba se escribe 0.5.
             */
            $tabla->decimal('cantidad', 14, 4);

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();

            $tabla->unique(['item_id', 'insumo_id']);
            $tabla->index('insumo_id');
        });

        DB::statement('ALTER TABLE consumos_de_servicio ADD CONSTRAINT consumos_de_servicio_cantidad_positiva CHECK (cantidad > 0)');
        DB::statement('ALTER TABLE consumos_de_servicio ADD CONSTRAINT consumos_de_servicio_no_se_gasta_a_si_mismo CHECK (item_id <> insumo_id)');

        Schema::create('consumos_de_cargo', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            /*
             * El cargo del examen. Sin FK y con su fecha: `cargos` está
             * particionada y su llave es `(id, fecha_operacion)`, igual
             * que en `cargo_claves` y `factura_lineas`.
             */
            $tabla->unsignedBigInteger('cargo_id');
            $tabla->date('fecha_operacion');

            $tabla->foreignId('insumo_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();

            /*
             * El movimiento que lo sacó del estante, y el que lo devolvió
             * si el cargo se anuló. Nulos con stock infinito: ahí no se
             * mueve nada, solo se anota cuánto se usó.
             */
            $tabla->foreignId('movimiento_id')->nullable()->constrained('movimientos_kardex')->restrictOnDelete();
            $tabla->foreignId('devolucion_id')->nullable()->constrained('movimientos_kardex')->restrictOnDelete();

            $tabla->decimal('cantidad', 14, 4);
            $tabla->decimal('costo_unitario', 14, 6)->nullable();
            $tabla->boolean('sin_existencia')->default(false);

            $tabla->timestampTz('ocurrido_en');

            /*
             * Cuándo dejó de contar: el cargo se anuló y lo gastado volvió
             * (o, en infinito, dejó de sumar en el reporte).
             */
            $tabla->timestampTz('revertido_en')->nullable();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();

            $tabla->index(['cargo_id', 'fecha_operacion']);
            $tabla->index(['insumo_id', 'fecha_operacion']);
            $tabla->index(['sede_id', 'fecha_operacion']);
            $tabla->index('almacen_id');
            $tabla->index('lote_id');
            $tabla->index('movimiento_id');
        });

        DB::statement('ALTER TABLE consumos_de_cargo ADD CONSTRAINT consumos_de_cargo_cantidad_positiva CHECK (cantidad > 0)');

        /*
         * Con existencia hay movimiento; en infinito no hay. Lo uno o lo
         * otro: un gasto «con existencia» sin movimiento sería inventario
         * que bajó sin dejar rastro en el kardex.
         */
        DB::statement(
            'ALTER TABLE consumos_de_cargo ADD CONSTRAINT consumos_de_cargo_movimiento_coherente
             CHECK ((sin_existencia AND movimiento_id IS NULL) OR (NOT sin_existencia AND movimiento_id IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_de_cargo');
        Schema::dropIfExists('consumos_de_servicio');

        /*
         * El CHECK del kardex NO se revierte a la lista vieja: si quedara
         * algún gasto asentado, la base lo rechazaría al recrear la
         * restricción y la migración se caería a la mitad.
         */
    }

    /**
     * Reconstruye `kardex_signo_coherente` con TODOS los tipos del enum,
     * como hizo la migración de préstamos.
     */
    private function rehacerElCheckDelKardex(): void
    {
        $entradas = $this->comoLista(TipoMovimiento::entradas());
        $salidas = $this->comoLista(TipoMovimiento::salidas());

        DB::statement('ALTER TABLE movimientos_kardex DROP CONSTRAINT IF EXISTS kardex_signo_coherente');

        DB::statement(
            "ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_signo_coherente
             CHECK (
                 (tipo IN ({$entradas}) AND cantidad > 0)
                 OR
                 (tipo IN ({$salidas}) AND cantidad < 0)
             )"
        );
    }

    /**
     * @param list<string> $valores
     */
    private function comoLista(array $valores): string
    {
        return implode(', ', array_map(
            static fn (string $valor): string => "'{$valor}'",
            $valores,
        ));
    }
};
