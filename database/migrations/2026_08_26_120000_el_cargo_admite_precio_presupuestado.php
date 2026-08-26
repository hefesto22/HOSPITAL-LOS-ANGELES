<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cargo del paquete lleva un precio que no salió de ningún tarifario
 * (ADR-0009).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA BASE TIENE SU PROPIA LISTA, Y ESO ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `OrigenDelPrecio` ganó el caso `presupuestado` en PHP, pero
 * `cargos_origen_conocido` no lo conocía y PostgreSQL rechazó la fila.
 * No es una duplicación molesta: es la defensa en profundidad del §12.
 * El día que un import, un seeder o un `tinker` escriba un origen que no
 * existe, quien lo para es este CHECK y no el modelo.
 *
 * La lección para el resto del proyecto: **agregar un caso a un enum que
 * viaja a una columna con CHECK son DOS cambios**, y el segundo no lo
 * recuerda nadie hasta que la pantalla revienta.
 *
 * ⚠️ `cargos` está particionada: el CHECK vive en la tabla padre y
 * PostgreSQL lo propaga a las once particiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_origen_conocido');

        DB::statement(
            "ALTER TABLE cargos ADD CONSTRAINT cargos_origen_conocido
             CHECK (origen_precio IN (
                'precio_de_lista',
                'precio_negociado',
                'porcentaje_pactado',
                'precio_manual',
                'presupuestado'
             ))"
        );

        /*
         * ⚠️ NO se agrega un CHECK de «solo el paquete puede venir sin
         * tarifario», aunque suene bien.
         *
         * Se intentó y rompió ocho tests de políticas de acceso: las
         * factories crean cargos de prueba sin `tarifario_id`, y eso es
         * legítimo —la columna nació nullable por diseño en el bloque 4—.
         * Endurecerla obligaría a cambiar tests que hoy prueban seguridad
         * para acomodar una restricción que nadie pidió.
         */
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cargos DROP CONSTRAINT IF EXISTS cargos_origen_conocido');

        DB::statement(
            "ALTER TABLE cargos ADD CONSTRAINT cargos_origen_conocido
             CHECK (origen_precio IN (
                'precio_de_lista',
                'precio_negociado',
                'porcentaje_pactado',
                'precio_manual'
             ))"
        );
    }
};
