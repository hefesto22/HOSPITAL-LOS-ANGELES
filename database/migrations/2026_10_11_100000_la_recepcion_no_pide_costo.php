<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA RECEPCIÓN REGISTRA LO QUE ENTRÓ, NO LO QUE COSTÓ (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: en la recepción «solo coloquen el registro de lo que
 * entró, sin costos ni nada de eso». Quien recibe en bodega no maneja las
 * facturas —la matriz de permisos ya le niega Compras— y el precio de
 * farmacia deja de salir del costo: se escribe como precio mínimo del
 * envase y de ahí se calcula el resto.
 *
 * Las dos columnas de costo pasan a aceptar NULL, que significa «se
 * recibió sin costo». NO es lo mismo que cero: cero es una donación que
 * SÍ entra al promedio y lo baja; NULL no lo toca.
 *
 * Las columnas no se borran. Las recepciones viejas siguen explicando
 * con qué costo entraron, y el servicio todavía acepta una línea con
 * costo —un import del sistema viejo, o el día que alguien quiera volver
 * a costear—.
 *
 * ⚠️ El CHECK de «no negativo» se queda tal cual: en PostgreSQL un CHECK
 * que evalúa a NULL pasa, así que una línea sin costo no lo viola.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recepcion_lineas ALTER COLUMN costo_por_presentacion DROP NOT NULL');
        DB::statement('ALTER TABLE recepcion_lineas ALTER COLUMN costo_unitario DROP NOT NULL');

        /*
         * Las dos juntas o ninguna. Un costo por caja sin costo por
         * unidad —o al revés— es una línea a medio escribir, y el
         * recálculo del promedio la sumaría a medias.
         */
        DB::statement(
            'ALTER TABLE recepcion_lineas ADD CONSTRAINT recepcion_lineas_costo_completo '
            .'CHECK ((costo_por_presentacion IS NULL) = (costo_unitario IS NULL))'
        );
    }

    /**
     * ⚠️ Vuelve a exigir el costo, y por eso FALLA si ya hay recepciones
     * sin costo: PostgreSQL no deja poner NOT NULL sobre filas nulas. Es
     * a propósito. Inventarles un costo para poder bajar la migración
     * sería escribir un número que nadie pagó.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE recepcion_lineas DROP CONSTRAINT IF EXISTS recepcion_lineas_costo_completo');
        DB::statement('ALTER TABLE recepcion_lineas ALTER COLUMN costo_unitario SET NOT NULL');
        DB::statement('ALTER TABLE recepcion_lineas ALTER COLUMN costo_por_presentacion SET NOT NULL');
    }
};
