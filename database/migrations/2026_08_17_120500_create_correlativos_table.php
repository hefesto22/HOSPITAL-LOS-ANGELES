<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secuencias por sede (§8.1, §10.3).
 *
 * Una fila por (sede, tipo, año). Esa fila es el punto de serialización:
 * quien quiere el siguiente número la bloquea con `lockForUpdate` dentro
 * de una transacción, la incrementa y suelta.
 *
 * ⚠️ POR QUÉ EL CONTADOR ES POR SEDE Y NO GLOBAL (§8.1)
 *
 * Un contador global obliga a que TODAS las sedes se serialicen contra la
 * misma fila. En admisión de emergencia, con dos sedes registrando
 * pacientes al mismo tiempo, eso es contención pura: una sede espera a la
 * otra para poder abrir un expediente. Y además produce números sin
 * significado operativo — nadie sabe de qué establecimiento salió el
 * expediente 45.821.
 *
 * ⚠️ POR QUÉ UNA TABLA Y NO `MAX(numero) + 1`
 *
 * `MAX() + 1` es la forma clásica de generar duplicados: dos transacciones
 * concurrentes leen el mismo máximo. Y si el registro que consumió el
 * número se borra o se anula, el número se REUTILIZA — que en un
 * expediente clínico significa dos pacientes distintos con el mismo
 * número en el histórico.
 *
 * Tampoco sirve una secuencia de PostgreSQL: no se puede tener una por
 * (sede, tipo, año) sin crearlas dinámicamente, y el `nextval` no
 * participa del rollback, así que un fallo de la transacción dejaría
 * huecos que en un correlativo hay que poder explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correlativos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')
                ->constrained('sedes')
                ->restrictOnDelete();

            $tabla->string('tipo', 40);

            /*
             * Año de la secuencia. NULL para las que no reinician nunca
             * —expediente y accession— porque ahí el año no forma parte de
             * la identidad del contador.
             */
            $tabla->smallInteger('anio')->nullable();

            $tabla->unsignedBigInteger('ultimo_numero')->default(0);

            $tabla->timestamps();
        });

        /*
         * Índice único con columna NULLABLE: en PostgreSQL NULL ≠ NULL, así
         * que un UNIQUE normal permitiría VARIAS filas de expediente para
         * la misma sede — y cada una llevaría su propio contador. Ese es el
         * bug que produce dos pacientes con el mismo número.
         *
         * Se resuelve con COALESCE sobre un centinela (§12).
         */
        DB::statement(
            'CREATE UNIQUE INDEX correlativos_sede_tipo_anio_unique
             ON correlativos (sede_id, tipo, COALESCE(anio, 0))'
        );

        /*
         * Defensa profunda: el contador solo puede crecer. Un UPDATE que
         * lo baje —un script de "corrección", un import mal hecho— haría
         * que se repitan números ya emitidos.
         */
        DB::statement(
            'ALTER TABLE correlativos
             ADD CONSTRAINT correlativos_ultimo_numero_check
             CHECK (ultimo_numero >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('correlativos');
    }
};
