<?php

declare(strict_types=1);

use App\Domain\Enums\TipoMovimiento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El kardex — todo lo que entró y salió, para siempre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * APPEND-ONLY, Y NO POR CONVENCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un trigger de PostgreSQL rechaza cualquier `UPDATE` y cualquier
 * `DELETE` sobre esta tabla. No es una regla del modelo que alguien pueda
 * saltarse con `DB::table(...)->update()`: la base lo impide.
 *
 * **Un movimiento equivocado se corrige con otro movimiento**, no
 * borrando el original. Esa es la diferencia entre un inventario que se
 * puede auditar y uno donde el faltante se hace desaparecer editando una
 * fila. Si se pudiera borrar, la pregunta «¿dónde se fueron las 40
 * ampollas?» no tendría respuesta posible.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL SIGNO Y EL SALDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cantidad` va con signo: positiva si entra, negativa si sale. Así la
 * existencia es literalmente `SUM(cantidad)`, y el test que verifica el
 * saldo contra el kardex es una sola consulta. Un CHECK ata el signo al
 * tipo para que una dispensación no pueda sumar.
 *
 * `saldo_despues` es la foto del saldo justo después de este movimiento.
 * Es lo que convierte la tabla en un kardex imprimible de verdad —cada
 * línea con su saldo, como el que pide una auditoría— y de paso permite
 * detectar el día exacto en que un saldo empezó a divergir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_kardex', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            $tabla->string('tipo', 40);

            $tabla->decimal('cantidad', 14, 4);
            $tabla->decimal('saldo_despues', 14, 4);

            /*
             * Obligatorio en ajustes y mermas, y la base lo exige. Un
             * ajuste sin explicación es la forma más limpia de tapar un
             * faltante: el número cuadra y nadie sabe qué pasó.
             */
            $tabla->text('motivo')->nullable();

            /*
             * El documento que lo originó: número de factura del
             * proveedor, de dispensación, de traslado. Todavía sin FK
             * porque esos módulos no existen; cuando existan, se agrega.
             */
            $tabla->string('referencia', 80)->nullable();

            /*
             * CUÁNDO PASÓ, que no es cuándo se digitó. La entrada de una
             * compra que llegó el viernes se carga el lunes, y el kardex
             * tiene que decir viernes.
             */
            $tabla->timestampTz('ocurrido_en');

            $tabla->timestamps();

            /*
             * Sin `updated_by` ni `deleted_by`: acá nada se actualiza ni
             * se borra. Quién lo asentó es lo único que hay que saber.
             */
            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $entradas = self::comoLista(TipoMovimiento::entradas());
        $salidas = self::comoLista(TipoMovimiento::salidas());
        $exigenMotivo = self::comoLista(['ajuste_positivo', 'ajuste_negativo', 'salida_por_merma']);

        // ── El kardex no se toca ──────────────────────────────────────

        /*
         * Acá va SOLO el trigger. La función que lo respalda ya existe:
         * es `sihla_rechazar_modificacion()`, la misma que protege
         * `persona_versiones`, creada por su migración —que corre antes
         * que esta— y escrita para servir a cualquier tabla append-only:
         * saca el nombre de la tabla de `TG_TABLE_NAME` y la operación de
         * `TG_OP`, y levanta SQLSTATE 23001 (`restrict_violation`) en vez
         * del genérico P0001.
         *
         * ⚠️ NO agregar acá un `CREATE FUNCTION` propio, y si se agrega
         * en otra migración, que sea `CREATE OR REPLACE`. `migrate:fresh`
         * dropea TABLAS, vistas y tipos —`PostgresBuilder` no tiene
         * `dropAllFunctions()`—, así que una función se queda viva
         * después del wipe: la primera corrida pasa y la SEGUNDA se cae
         * entera con «function already exists with same argument types».
         * En la suite en paralelo eso son catorce bases fallando a la vez
         * por algo que en la primera corrida se veía verde.
         *
         * El mensaje específico del kardex —«un movimiento equivocado se
         * corrige con otro movimiento»— vive en `MovimientoKardex`, que
         * es lo que topa cualquier código de la aplicación. Este trigger
         * es el candado de abajo, el que vale aunque la escritura venga
         * de tinker o de SQL crudo.
         */
        DB::unprepared(
            'CREATE TRIGGER movimientos_kardex_inmutable
             BEFORE UPDATE OR DELETE ON movimientos_kardex
             FOR EACH ROW EXECUTE FUNCTION sihla_rechazar_modificacion()'
        );

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_cantidad_no_cero
             CHECK (cantidad <> 0)'
        );

        DB::statement(
            'ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_saldo_no_negativo
             CHECK (saldo_despues >= 0)'
        );

        DB::statement(
            "ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_signo_coherente
             CHECK (
                 (tipo IN ({$entradas}) AND cantidad > 0)
                 OR
                 (tipo IN ({$salidas}) AND cantidad < 0)
             )"
        );

        DB::statement(
            "ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_ajuste_explicado
             CHECK (
                 tipo NOT IN ({$exigenMotivo})
                 OR length(btrim(COALESCE(motivo, ''))) >= 10
             )"
        );

        // ── Índices de consulta ───────────────────────────────────────

        DB::statement(
            'CREATE INDEX kardex_por_item
             ON movimientos_kardex (item_id, almacen_id, ocurrido_en DESC)'
        );

        DB::statement(
            'CREATE INDEX kardex_por_lote
             ON movimientos_kardex (lote_id, ocurrido_en DESC)'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS movimientos_kardex_inmutable ON movimientos_kardex');

        Schema::dropIfExists('movimientos_kardex');

        /*
         * `sihla_rechazar_modificacion()` NO se dropea acá: no es de esta
         * migración, es de `persona_versiones`, y su propia migración la
         * borra cuando le toca. Dropearla desde acá dejaría a
         * `persona_versiones` sin candado y sin que nadie se enterara.
         */
    }

    /**
     * Los valores del enum, entrecomillados para el CHECK.
     *
     * Salen del enum y no de una lista escrita a mano: agregar un tipo
     * nuevo sin actualizar el CHECK produciría un movimiento que la base
     * rechaza con un mensaje que no dice nada.
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
