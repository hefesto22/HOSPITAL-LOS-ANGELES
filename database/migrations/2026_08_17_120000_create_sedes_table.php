<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sedes — la raíz de la jerarquía del §8.1.
 *
 *   sedes ──< servicios/áreas ──< almacenes
 *                            └── camas
 *        └── puntos_emision (CAI y correlativo propios)
 *        └── cajas
 *
 * ⚠️ NO existe tabla `organizaciones`, y es deliberado.
 *
 * ADR-0002 fijó una instalación y una base por cliente, con un solo dueño.
 * Una tabla `organizaciones` con exactamente una fila es la forma exacta
 * que invita a que alguien agregue la segunda — y eso es el multi-tenant
 * que el ADR prohíbe. Los datos de la organización viven en
 * `config/sihla.php`; la identidad FISCAL vive acá, porque es por
 * ESTABLECIMIENTO: el SAR autoriza CAI y rangos por establecimiento, y
 * SESAL habilita por establecimiento.
 *
 * Vigencia en vez de booleano `activa` (ADR-0003): una sede que cierra
 * debe dejar de ser seleccionable HOY y seguir explicando una factura de
 * hace dos años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table): void {
            $table->id();

            /*
             * Prefijo de los identificadores visibles: expediente, factura,
             * accession number (§8.1). Un contador global sería cuello de
             * botella y confusión operativa; cada sede lleva su secuencia.
             */
            $table->string('codigo', 10)->unique();
            $table->string('nombre');

            // ── Identidad fiscal, por establecimiento ──────────────────
            $table->string('razon_social');
            $table->string('rtn', 14)->nullable();

            /*
             * Los 3 primeros dígitos del correlativo del SAR:
             * NNN-NNN-NN-NNNNNNNN = establecimiento - punto de emisión -
             * tipo de documento - número.
             */
            $table->string('codigo_establecimiento', 3)->nullable();

            // ── Habilitación sanitaria ────────────────────────────────
            $table->string('registro_sesal')->nullable();

            // ── Contacto ──────────────────────────────────────────────
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email')->nullable();

            /*
             * Vigencia, no booleano. `vigencia_hasta` nula = vigente.
             */
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Campos de auditoría (§11) — quién creó, actualizó y borró.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Índice parcial: la consulta real es "sedes vigentes hoy", que
             * es casi siempre una o dos filas. Un índice completo sobre una
             * tabla que va a tener 3 registros no sirve de nada, pero este
             * es el patrón que se repite en tablas de millones de filas
             * (§12: índices parciales para bandejas de trabajo).
             */
            $table->index(['vigencia_desde', 'vigencia_hasta'], 'sedes_vigencia_index');
        });

        /*
         * Índice único PARCIAL sobre el código de establecimiento del SAR.
         *
         * Un UNIQUE normal no sirve acá: en PostgreSQL NULL ≠ NULL, así que
         * permitiría varias sedes sin código (lo que sí queremos) pero
         * también contaría como distintas dos filas borradas con el mismo
         * código, bloqueando reutilizarlo. El índice parcial resuelve las
         * dos cosas: solo aplica a filas con código y no borradas (§12).
         */
        DB::statement(
            'CREATE UNIQUE INDEX sedes_codigo_establecimiento_unique
             ON sedes (codigo_establecimiento)
             WHERE codigo_establecimiento IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
    }
};
