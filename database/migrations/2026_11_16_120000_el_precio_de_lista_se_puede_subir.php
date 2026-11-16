<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL PRECIO DE LISTA QUE SE PUEDE SUBIR AL CARGAR (16-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «en base de precios que aparezca un check, precio
 * modificable al agregar a cuenta… esto solo en precios de lista, en lo
 * de los seguros no, ya que hay unos como RECARGO EXAMEN DE LABORATORIO
 * que su precio base es de 250 pero puede cambiarse a más».
 *
 * Es del ítem y no de la fila del tarifario: el precio de lista cambia
 * de fila cada vez que se corrige, y la marca tiene que sobrevivir a eso.
 * Falso para todo el catálogo: un precio libre en la pantalla de cobro
 * es por donde se va la plata, así que se abre ítem por ítem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->boolean('precio_modificable')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('precio_modificable');
        });
    }
};
