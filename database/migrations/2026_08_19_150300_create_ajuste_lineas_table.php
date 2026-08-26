<?php

declare(strict_types=1);

use App\Domain\Enums\MotivoDeAjuste;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada producto que se ajustó, con su motivo y lo que costó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL PUENTE ENTRE EL DOCUMENTO Y EL KARDEX
 * ─────────────────────────────────────────────────────────────────────
 *
 * `movimiento_id` apunta a la línea exacta del kardex que esta línea
 * generó, y es único: un movimiento pertenece a un solo ajuste. Sin esa
 * columna, «¿de dónde salió este −5 del 14 de agosto?» se contesta
 * cruzando fechas a ojo, que es como no contestarla.
 *
 * `conteo_linea_id` cierra la otra mitad del rastro cuando el ajuste
 * viene de un conteo: quién contó, cuánto vio, contra qué saldo, y en qué
 * terminó. Nulo en una merma o en una corrección puntual.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL SIGNO ES EL DEL KARDEX
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cantidad` va firmada: positiva si entra, negativa si sale — la misma
 * convención que `movimientos_kardex`, para que las dos tablas se puedan
 * sumar juntas sin traducir nada. El motivo decide qué signos admite:
 * una rotura no puede aparecer como entrada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL COSTO SE CONGELA, Y NO ES EL MISMO NÚMERO QUE EL PROMEDIO DE HOY
 * ─────────────────────────────────────────────────────────────────────
 *
 * `costo_unitario` es el promedio ponderado vigente **en el momento de
 * asentar**, y `valor` es |cantidad| × ese costo. Los dos se guardan
 * porque el promedio se mueve con cada compra: recalcular el año que
 * viene cuánto costó la merma de agosto daría otro número, y entonces el
 * reporte de pérdidas no cuadraría nunca con el kardex valorizado.
 *
 * Es exactamente el mismo argumento que el snapshot de precio en cada
 * cargo (§8.5-5) y que `saldo_despues` en el kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajuste_lineas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('ajuste_id')->constrained('ajustes')->cascadeOnDelete();

            $tabla->foreignId('conteo_linea_id')
                ->nullable()
                ->constrained('conteo_lineas')
                ->nullOnDelete();

            /*
             * La línea del kardex que esta línea produjo. `restrict`
             * porque el kardex no se borra nunca; si algún día alguien lo
             * intentara, esta FK es una razón más para que no pueda.
             */
            $tabla->foreignId('movimiento_id')
                ->nullable()
                ->constrained('movimientos_kardex')
                ->restrictOnDelete();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();

            $tabla->string('motivo', 40);

            /*
             * Firmada, en unidades de dispensación. Cuatro decimales
             * porque hay ítems fraccionables. Nunca float (§8.6.2).
             */
            $tabla->decimal('cantidad', 14, 4);

            /*
             * Seis decimales, igual que en `costos_promedio` y en la
             * línea de recepción. Redondear acá a dos y multiplicar
             * después es de donde salen los centavos que no cuadran.
             */
            $tabla->decimal('costo_unitario', 14, 6)->default(0);

            /*
             * En lempiras y a dos decimales, porque es plata que va a un
             * reporte y a un tope de autorización.
             */
            $tabla->decimal('valor', 14, 2)->default(0);

            $tabla->string('texto', 200)->nullable();

            $tabla->timestamps();
        });

        $motivos = self::comoLista(MotivoDeAjuste::valores());

        // ── Índices ───────────────────────────────────────────────────

        DB::statement(
            'CREATE INDEX ajuste_lineas_por_ajuste
             ON ajuste_lineas (ajuste_id)'
        );

        /*
         * «¿Qué le pasó a este producto durante el año?» — la pregunta de
         * la auditoría de inventario y la del comité de farmacia.
         */
        DB::statement(
            'CREATE INDEX ajuste_lineas_por_item
             ON ajuste_lineas (item_id, created_at DESC)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX ajuste_lineas_un_movimiento
             ON ajuste_lineas (movimiento_id)
             WHERE movimiento_id IS NOT NULL'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE ajuste_lineas
             ADD CONSTRAINT ajuste_lineas_cantidad_no_cero
             CHECK (cantidad <> 0)'
        );

        DB::statement(
            'ALTER TABLE ajuste_lineas
             ADD CONSTRAINT ajuste_lineas_valores_no_negativos
             CHECK (costo_unitario >= 0 AND valor >= 0)'
        );

        DB::statement(
            "ALTER TABLE ajuste_lineas
             ADD CONSTRAINT ajuste_lineas_motivo_valido
             CHECK (motivo IN ({$motivos}))"
        );

        // ── Append-only, igual que el ajuste ──────────────────────────

        DB::unprepared(
            'CREATE TRIGGER ajuste_lineas_inmutable
             BEFORE UPDATE OR DELETE ON ajuste_lineas
             FOR EACH ROW EXECUTE FUNCTION sihla_rechazar_modificacion()'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ajuste_lineas_inmutable ON ajuste_lineas');

        Schema::dropIfExists('ajuste_lineas');
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
