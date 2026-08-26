<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Este pagador NO cubre este ítem» — un dato del tarifario, no un `if`.
 *
 * §8.5-6 lo dice con todas las letras: la elegibilidad por convenio es
 * un dato del tarifario. Y tiene que serlo, porque la lista de lo que
 * una póliza excluye cambia con cada renegociación y es distinta para
 * cada aseguradora. Si viviera en código, firmar con la siguiente sería
 * un despliegue.
 *
 * Ojo con lo que significa: **excluido no es gratis y no es prohibido.**
 * El ítem se cobra igual —el paciente se lo llevó— pero al 100 % de su
 * bolsillo. Bloquear el cargo sería negar la atención por un asunto
 * administrativo, que es justo lo que §8.6.3 prohíbe.
 *
 * Va acá y no en `items` porque no es una propiedad del producto: la
 * misma vitamina es elegible con un pagador y excluida con otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifarios', function (Blueprint $tabla): void {
            /*
             * `true` por defecto: si alguien negoció un precio con un
             * pagador para un ítem, lo esperable es que lo cubra. La
             * exclusión es la excepción y se declara.
             *
             * En las filas de precio de LISTA (`convenio_id IS NULL`)
             * esta columna no se lee: no hay pagador que pueda excluir.
             */
            $tabla->boolean('elegible')->default(true)->after('precio');
        });

        DB::statement(
            'ALTER TABLE tarifarios ADD CONSTRAINT tarifarios_lista_siempre_elegible
             CHECK (convenio_id IS NOT NULL OR elegible = true)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tarifarios DROP CONSTRAINT IF EXISTS tarifarios_lista_siempre_elegible');

        Schema::table('tarifarios', function (Blueprint $tabla): void {
            $tabla->dropColumn('elegible');
        });
    }
};
