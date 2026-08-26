<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La garantía dura de idempotencia — deliberadamente SIN particionar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE UNA TABLA APARTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * En PostgreSQL, **todo índice único de una tabla particionada tiene que
 * incluir la llave de partición**. Es decir que el único de `cargos`
 * termina siendo `(clave_idempotencia, fecha_operacion)` y garantiza «la
 * misma clave, el mismo día».
 *
 * El hueco es el que se abre a las 23:59:59: el usuario aprieta, la
 * conexión se corta, el navegador reintenta, y el reintento cae el día
 * siguiente. Misma clave, fecha distinta: el índice deja pasar los dos y
 * el paciente termina pagando dos veces la misma ampolla.
 *
 * Acá la clave es la llave primaria a secas, sin fecha de por medio, así
 * que no hay medianoche que valga.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO SE USA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `RegistradorDeCargo` inserta acá con `insertOrIgnore` ANTES de crear
 * el cargo. Si el INSERT no afecta filas, el cargo ya existía: se lee y
 * se devuelve. Nunca un `try/catch` — en PostgreSQL un INSERT fallido
 * aborta la transacción entera (25P02), así que el catch de toda la vida
 * no alcanza (lección del bloque 5c).
 *
 * Es la misma tabla en la que el bloque 6 va a apoyarse cuando la
 * dispensación real llegue por HL7 o por el lector: toda entrada externa
 * necesita clave de idempotencia (§13.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargo_claves', function (Blueprint $tabla): void {
            $tabla->uuid('clave')->primary();

            /*
             * El par `(cargo_id, fecha_operacion)` porque así es la
             * llave primaria de `cargos`. Sin FK: la tabla referenciada
             * está particionada y el ON DELETE no aporta nada —de
             * `cargos` no se borra nunca.
             */
            $tabla->unsignedBigInteger('cargo_id');
            $tabla->date('fecha_operacion');

            $tabla->timestampTz('created_at');
        });

        DB::statement(
            'CREATE INDEX cargo_claves_por_cargo ON cargo_claves (cargo_id, fecha_operacion)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_claves');
    }
};
