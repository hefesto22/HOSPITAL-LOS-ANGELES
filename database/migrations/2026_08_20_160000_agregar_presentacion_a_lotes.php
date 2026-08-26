<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL LOTE RECUERDA EN QUÉ ENVASE LLEGÓ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ «600 ML» NO ALCANZA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El kardex se lleva en unidad de dispensación —mililitros— y eso está
 * bien: es la única forma de que media dosis se pueda cobrar. Pero en el
 * estante no hay 600 mililitros sueltos: hay DIEZ FRASCOS DE 60.
 *
 * Y no es lo mismo. Si un paciente necesita 100 ml, «600 ML» dice que
 * alcanza; diez frascos de 60 dicen que hay que abrir dos. Con esta
 * columna, Existencias puede mostrar «10 frascos de 60 ML» — y cuando
 * alguien consuma 20, «9 frascos y 40 ML», que es la forma de ver que
 * hay un frasco abierto.
 *
 * ⚠️ LÍMITE CONOCIDO: el lote se queda con la presentación con la que
 * nació. Si dos envases distintos compartieran el mismo número de lote
 * —raro, porque el lote del fabricante es por presentación— el conteo de
 * frascos de ese lote sería aproximado. Se prefirió eso antes que cambiar
 * la llave única de `lotes`, que es de lo que cuelga el FEFO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $tabla): void {
            /*
             * Nullable a propósito y para siempre: hay ítems que se
             * almacenan sin envase declarado, y los lotes que ya existen
             * no tienen cómo saber de dónde vinieron. Se rellena solo, en
             * la próxima recepción de ese lote.
             */
            $tabla->foreignId('item_presentacion_id')
                ->nullable()
                ->after('item_id')
                ->constrained('item_presentaciones')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('item_presentacion_id');
        });
    }
};
