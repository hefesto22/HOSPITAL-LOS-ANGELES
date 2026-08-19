<?php

declare(strict_types=1);

use App\Domain\ValueObjects\RTN;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores — a quién se le compra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE REUSA `personas`
 * ─────────────────────────────────────────────────────────────────────
 *
 * `personas` es el MPI: el índice maestro de PACIENTES. Meter ahí a las
 * droguerías rompería tres cosas a la vez —el detector de duplicados
 * empezaría a comparar laboratorios contra pacientes, el expediente
 * tendría titulares que no son gente, y la búsqueda de admisión
 * devolvería proveedores— a cambio de ahorrarse una tabla de seis
 * columnas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL RTN NO ES OBLIGATORIO, PERO SI ESTÁ ES ÚNICO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Obligarlo dejaría afuera al proveedor informal y a la ONG que dona, y
 * lo que pasaría es que alguien teclea catorce ceros para poder guardar.
 * Nulo es un dato que falta; catorce ceros es un dato falso que además
 * choca con el siguiente que haga lo mismo.
 *
 * El índice único es PARCIAL —`WHERE rtn IS NOT NULL`— justamente para
 * que varios nulos puedan convivir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Corto y en mayúsculas, como el resto de los códigos del
             * sistema: es lo que se teclea en bodega al recibir.
             */
            $tabla->string('codigo', 20);
            $tabla->string('nombre', 160);

            $tabla->string('rtn', 20)->nullable();

            $tabla->string('contacto', 120)->nullable();
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('correo', 120)->nullable();

            $tabla->text('notas')->nullable();

            /*
             * Un proveedor con el que se dejó de trabajar NO se borra: se
             * desactiva. Sus entradas de compra siguen apuntando a él, y
             * una entrada cuyo proveedor desapareció es un kardex que no
             * se puede explicar.
             */
            $tabla->boolean('activo')->default(true);

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX proveedores_codigo_unico
             ON proveedores (codigo)
             WHERE deleted_at IS NULL'
        );

        /*
         * Dos proveedores no pueden compartir RTN: sería la misma empresa
         * cargada dos veces, y las compras quedarían repartidas entre las
         * dos fichas sin que ningún reporte lo note.
         */
        DB::statement(
            'CREATE UNIQUE INDEX proveedores_rtn_unico
             ON proveedores (rtn)
             WHERE deleted_at IS NULL AND rtn IS NOT NULL'
        );

        DB::statement(
            'ALTER TABLE proveedores
             ADD CONSTRAINT proveedores_codigo_no_vacio
             CHECK (length(btrim(codigo)) > 0)'
        );

        DB::statement(
            'ALTER TABLE proveedores
             ADD CONSTRAINT proveedores_nombre_no_vacio
             CHECK (length(btrim(nombre)) > 0)'
        );

        /*
         * El RTN hondureño son catorce dígitos, sin guiones ni espacios
         * (§8.4.3). El CHECK repite lo que ya valida el value object
         * `RTN` porque un import del sistema anterior no pasa por él.
         */
        $longitud = RTN::LONGITUD;

        DB::statement(
            "ALTER TABLE proveedores
             ADD CONSTRAINT proveedores_rtn_bien_formado
             CHECK (rtn IS NULL OR rtn ~ '^[0-9]{{$longitud}}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
