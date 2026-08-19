<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto hay, de qué lote, en qué almacén.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO ES UN SALDO, NO LA VERDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * La verdad es el kardex: la suma de sus movimientos. Esta tabla es esa
 * suma ya calculada, y existe por una razón práctica — preguntar «cuánto
 * hay» en el mostrador no puede recorrer dos años de movimientos.
 *
 * Se actualiza **en la misma transacción** que asienta el movimiento, y
 * hay un test que verifica que el saldo coincida con la suma del kardex.
 * Si algún día no coincide, el kardex gana: esta tabla se recalcula.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL `UPDATE` CONDICIONAL Y NO UN `if`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dos dispensaciones simultáneas del último frasco leerían las dos «hay
 * 1», las dos dirían que sí, y las dos descontarían. El saldo quedaría en
 * −1 y en el estante no habría nada.
 *
 * Por eso el descuento se hace con un solo `UPDATE ... WHERE cantidad >=
 * :cantidad`: si la fila ya no alcanza, afecta cero filas y el servicio
 * lo rechaza. Nunca se lee para después decidir.
 *
 * El CHECK `cantidad >= 0` es el segundo cinturón, por si la escritura no
 * vino del servicio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS DOS NULOS Y EL `item_id` REPETIDO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `lote_id` es nulo para lo que no lleva lote —gasas, jeringas— y por eso
 * el índice único va sobre `COALESCE(lote_id, 0)`: en SQL `NULL = NULL`
 * no es verdadero y sin el `COALESCE` podrían convivir dos saldos del
 * mismo insumo en el mismo almacén.
 *
 * `item_id` está aunque se pueda deducir del lote, y no es redundancia
 * decorativa: para lo que NO lleva lote no hay de dónde deducirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            /*
             * Cuatro decimales porque hay ítems fraccionables: medio
             * mililitro es una dosis. Nunca float (§8.6.2).
             *
             * Siempre en la UNIDAD DE DISPENSACIÓN del ítem. Las cajas se
             * convierten al entrar; el kardex no sabe de cajas.
             */
            $tabla->decimal('cantidad', 14, 4)->default(0);

            $tabla->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX existencias_unica
             ON existencias (item_id, (COALESCE(lote_id, 0)), almacen_id)'
        );

        DB::statement(
            'CREATE INDEX existencias_con_saldo
             ON existencias (item_id, almacen_id)
             WHERE cantidad > 0'
        );

        DB::statement(
            'ALTER TABLE existencias
             ADD CONSTRAINT existencias_no_negativa
             CHECK (cantidad >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('existencias');
    }
};
