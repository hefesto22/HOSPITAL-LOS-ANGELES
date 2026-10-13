<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ ENVASES HAY EN CADA LUGAR DEL STOCK INFINITO (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «puede ser que en ese lugar solo haya blíster, o cajas, o
 * unidades, o de todo. Si marca caja, por ende hay unidades y blíster.
 * Aunque sean infinitos, hay que tenerlo en cuenta».
 *
 * Un lugar del stock infinito dice ahora también QUÉ hay ahí:
 *
 *   · `de_todo` (lo que trae cada lugar al nacer): todos los envases del
 *     producto, también los que se le agreguen después a la ficha.
 *   · Si no, los envases marcados están en `ubicacion_envases`. Una caja
 *     marcada trae lo que lleva adentro —el blíster—, y eso se deduce al
 *     leer (`FormasDeCobro::envasesQueLoTraen`), no se guarda dos veces.
 *     Sin ningún envase marcado, en ese lugar solo hay unidades sueltas.
 *   · Las unidades sueltas están siempre donde está el producto: de una
 *     caja se saca una tableta.
 *
 * 🔴 Una fila de lugar NO cambia lo que dice: si cambia lo que hay, se
 * cierra y se abre otra. Así «¿qué había en el carrito el sábado?» tiene
 * respuesta, igual que «¿estaba en el carrito?».
 *
 * Las filas abiertas de hoy quedan `de_todo`, que es lo que querían decir
 * cuando se anotaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ubicaciones_de_producto', function (Blueprint $tabla): void {
            $tabla->boolean('de_todo')->default(true);
        });

        Schema::create('ubicacion_envases', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('ubicacion_id')->constrained('ubicaciones_de_producto')->restrictOnDelete();
            $tabla->foreignId('item_presentacion_id')->constrained('item_presentaciones')->restrictOnDelete();

            $tabla->timestamps();

            $tabla->unique(['ubicacion_id', 'item_presentacion_id']);
            $tabla->index('item_presentacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_envases');

        Schema::table('ubicaciones_de_producto', function (Blueprint $tabla): void {
            $tabla->dropColumn('de_todo');
        });
    }
};
