<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué envase sale el medicamento presupuestado (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UN DETALLE: ES EL PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `ResolutorDePrecio` ya resuelve el precio POR PRESENTACIÓN — el frasco
 * de 60 ML y el de 120 ML costaron distinto el mililitro—, pero el
 * presupuesto lo estaba llamando sin envase y cotizaba siempre contra el
 * precio del producto entero.
 *
 * Guardarlo además deja el presupuesto comparable con lo que después se
 * despacha: si se cotizó el frasco de 120 y se entregó el de 60, eso
 * tiene que poder verse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presupuesto_lineas', function (Blueprint $tabla): void {
            $tabla->foreignId('presentacion_id')->nullable()
                ->constrained('item_presentaciones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('presupuesto_lineas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('presentacion_id');
        });
    }
};
