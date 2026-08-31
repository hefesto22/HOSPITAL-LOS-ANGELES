<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cargo guarda de qué médico es el honorario.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UNA FK Y NO EL TEXTO QUE YA HABÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `referencia_acordada` ya venía guardando «Dr. Fulano · cirujano»
 * escrito a mano, y sigue: es lo que el paciente lee en el renglón de la
 * cuenta y en la factura.
 *
 * Pero la pregunta que el hospital hace a fin de mes —cuánto hay que
 * liquidarle al doctor Carlos— no se contesta sobre texto libre: «Dr.
 * Carlos», «Dr Carlos Pineda» y «CARLOS PINEDA» son tres doctores para
 * un GROUP BY y uno solo para quien firma el cheque.
 *
 * Nulo para todo lo que no es honorario, y también para el honorario que
 * se cobró antes de que existiera esta tabla: el histórico no se
 * inventa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $tabla): void {
            $tabla->foreignId('medico_id')
                ->nullable()
                ->after('servicio_id')
                ->constrained('medicos')
                ->restrictOnDelete();

            /*
             * El índice es la razón de ser de la columna: la liquidación
             * mensual filtra por médico y rango de fechas.
             */
            $tabla->index(['medico_id', 'fecha_operacion'], 'cargos_medico_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $tabla): void {
            $tabla->dropIndex('cargos_medico_fecha_index');
            $tabla->dropConstrainedForeignId('medico_id');
        });
    }
};
