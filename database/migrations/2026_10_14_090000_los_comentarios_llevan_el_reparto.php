<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOS COMENTARIOS DE LA FACTURA LLEVAN EL REPARTO (Mauricio, 14-oct-2026).
 *
 * «En comentarios debería salir copago de X lempiras, reembolso de X
 * lempiras y el nombre del seguro.» `EmisorDeFactura` lo escribe primero
 * y debajo va la nota que se tecleó al emitir:
 *
 *   COPAGO: L 30.02 · REEMBOLSO: L 200.00 · PAN-AMERICAN LIFE INSURANCE GROUP
 *   PAGÓ CON TARJETA DE LA EMPRESA
 *
 * La nota sola ya podía llegar a 300 caracteres, que era todo el ancho de
 * la columna. Con el reparto adelante —unos 60 más el nombre del seguro,
 * que llega a 120— no cabía: el INSERT habría reventado justo en la
 * factura con la nota más larga. 600 alcanza para las dos cosas.
 *
 * En PostgreSQL agrandar un `varchar` no reescribe la tabla ni toca filas:
 * no hay trigger de inmutabilidad que se entere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE facturas ALTER COLUMN comentarios TYPE varchar(600)');
    }

    /**
     * ⚠️ Volver atrás corta a 300 los comentarios más largos. Es un
     * cambio de tipo con `USING`: reescribe la tabla sin disparar el
     * trigger por fila, así que no choca con la inmutabilidad.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE facturas ALTER COLUMN comentarios TYPE varchar(300) USING left(comentarios, 300)');
    }
};
