<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\TipoDocumentoFiscal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras — el registro fiscal de lo que se gastó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO NO MUEVE INVENTARIO. NI UNA UNIDAD.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá se guarda el PAPEL: qué factura o recibo dio el proveedor, en qué
 * se gastó y cuánto, para el control del gasto y el Libro de Compras del
 * SAR. La mercadería que entra al estante se registra en `recepciones`,
 * que es otra tabla, otra pantalla y otra velocidad.
 *
 * Son dos hechos distintos y por eso son dos tablas:
 *
 *   · se compra papelería y combustible, que nunca entran a un kardex;
 *   · llega una donación al estante, que no tiene factura;
 *   · llega la mercadería el lunes y la factura el viernes.
 *
 * Forzarlos a una sola tabla obliga a inventar el dato que falta —una
 * factura falsa para la donación, un almacén falso para el combustible—
 * y a partir de ahí los dos reportes mienten.
 *
 * ─────────────────────────────────────────────────────────────────────
 * FACTURA Y RECIBO NO SON LO MISMO ANTE EL SAR
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · **Factura**: se desglosa gravado, ISV y exento. Ese ISV se
 *     descuenta del ISV de las ventas y entra al Libro de Compras.
 *   · **Recibo de compra**: solo el total. Queda como gasto y **no**
 *     acredita impuesto.
 *
 * Los CHECKs de abajo impiden la confusión en la dirección peligrosa:
 * un recibo con ISV cargado inflaría el crédito fiscal, y eso es un
 * hallazgo con multa, no un error de captura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();

            $tabla->string('tipo_documento', 20);

            /*
             * Obligatorio en factura —es lo que la identifica ante el
             * SAR— y opcional en recibo, porque hay recibos que no traen
             * número.
             */
            $tabla->string('numero_documento', 40)->nullable();

            $tabla->date('fecha_compra');

            $tabla->string('categoria_gasto', 30);

            // ── Los montos, tal como los dice el papel ────────────────
            $tabla->decimal('gravado_quince', 14, 2)->default(0);
            $tabla->decimal('isv', 14, 2)->default(0);
            $tabla->decimal('exento', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2)->default(0);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * La misma factura del mismo proveedor no se carga dos veces. El
         * índice es PARCIAL sobre el número no nulo, porque los recibos
         * sin número sí pueden repetirse: son varios y ninguno se
         * identifica.
         */
        DB::statement(
            'CREATE UNIQUE INDEX compras_documento_unico
             ON compras (proveedor_id, tipo_documento, numero_documento)
             WHERE deleted_at IS NULL AND numero_documento IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX compras_por_fecha
             ON compras (fecha_compra DESC)'
        );

        DB::statement(
            'CREATE INDEX compras_por_categoria
             ON compras (categoria_gasto, fecha_compra DESC)'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        $tipos = self::comoLista(TipoDocumentoFiscal::valores());
        $categorias = self::comoLista(CategoriaDeGasto::valores());

        DB::statement(
            "ALTER TABLE compras
             ADD CONSTRAINT compras_tipo_valido
             CHECK (tipo_documento IN ({$tipos}))"
        );

        DB::statement(
            "ALTER TABLE compras
             ADD CONSTRAINT compras_categoria_valida
             CHECK (categoria_gasto IN ({$categorias}))"
        );

        DB::statement(
            'ALTER TABLE compras
             ADD CONSTRAINT compras_montos_no_negativos
             CHECK (gravado_quince >= 0 AND isv >= 0 AND exento >= 0 AND total > 0)'
        );

        /*
         * En una FACTURA el total es la suma exacta de sus partes. Acá sí
         * se exige —al revés que en el borrador anterior— porque el
         * formulario la calcula solo: si no cuadra, alguien tecleó mal
         * una casilla, y ese descuadre viaja después a la declaración
         * mensual.
         */
        DB::statement(
            "ALTER TABLE compras
             ADD CONSTRAINT compras_factura_cuadra
             CHECK (
                 tipo_documento <> 'factura'
                 OR total = gravado_quince + isv + exento
             )"
        );

        /*
         * Y una FACTURA necesita número: es lo que la identifica ante el
         * SAR y lo que impide cargarla dos veces.
         */
        DB::statement(
            "ALTER TABLE compras
             ADD CONSTRAINT compras_factura_tiene_numero
             CHECK (
                 tipo_documento <> 'factura'
                 OR (numero_documento IS NOT NULL AND length(btrim(numero_documento)) > 0)
             )"
        );

        /*
         * ⚠️ EL CANDADO QUE MÁS IMPORTA DE ESTA TABLA.
         *
         * Un RECIBO no acredita impuesto. Cargarle ISV es reclamar un
         * crédito fiscal que no existe: infla la declaración mensual y
         * eso ante el SAR no es un error de captura, es una multa.
         *
         * Por eso en un recibo las tres casillas del desglose van en
         * cero y solo hay total. El formulario ni siquiera las muestra.
         */
        DB::statement(
            "ALTER TABLE compras
             ADD CONSTRAINT compras_recibo_no_acredita_isv
             CHECK (
                 tipo_documento <> 'recibo_de_compra'
                 OR (gravado_quince = 0 AND isv = 0 AND exento = 0)
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
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
