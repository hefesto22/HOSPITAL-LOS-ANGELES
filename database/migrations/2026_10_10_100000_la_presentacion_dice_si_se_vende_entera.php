<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CADA ENVASE DICE SI TAMBIÉN SE VENDE ENTERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio (10-oct-2026): «cuando son tabletas debería poderse
 * poner si se dispensa en tableta o caja … se puede dispensar en envase,
 * caja o tableta». Y se decide en cada presentación, no en el producto:
 * la caja de 100 se vende entera, el blíster de 10 del mismo producto
 * capaz que no.
 *
 * Con la marca, al cobrar aparece «CAJA X 100 TABLETAS» al lado de
 * «TABLETA», y elegirla descuenta las cien del estante y deja el renglón
 * diciendo «1 CAJA». Sin la marca, el envase sigue siendo lo que era
 * desde el 3-sep-2026: de dónde se sacó, sin multiplicar nada.
 *
 * ⚠️ `false` por defecto y para todo lo que ya existe. Prender la marca
 * en masa le pondría a cada producto un botón que multiplica por cien en
 * la pantalla de cobro, y eso lo decide quien conoce el producto, una
 * presentación a la vez.
 *
 * Agregar una columna NOT NULL con default constante en PostgreSQL 11+
 * no reescribe la tabla: es un cambio de catálogo, sin lock largo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_presentaciones', function (Blueprint $tabla): void {
            $tabla->boolean('se_vende_entera')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('item_presentaciones', function (Blueprint $tabla): void {
            $tabla->dropColumn('se_vende_entera');
        });
    }
};
