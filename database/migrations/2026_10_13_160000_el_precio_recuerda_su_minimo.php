<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRECIO RECUERDA DE QUÉ MÍNIMO SALIÓ (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si cambio el blíster a 12 tabletas tiene que actualizar el
 * precio para no vender a pérdida, y eso debe ser automático; lo mismo
 * si el descuento cambia, a más o a menos. Si baja al 30, bajan los
 * precios para seguir vendiendo con ganancia».
 *
 * Para recalcular solo hay que saber de dónde salió cada lista: el
 * mínimo que se escribió y los recargos. Hasta hoy eso vivía únicamente
 * en el texto del motivo del tarifario («— Mínimo L. 120.00 por CAJA X
 * 100…»), que sirve para leer pero no para calcular: no dice cuántas
 * tabletas traía esa caja.
 *
 * `precios_minimos` es esa política, por producto y sede, con vigencia:
 *
 *   · `minimo` y `contenido` — lo mínimo por ESE envase y lo que traía.
 *     Lo que se conserva al recalcular es el cociente, el mínimo POR
 *     UNIDAD: si la caja pasa de 100 a 120 tabletas, el mínimo de la caja
 *     pasa de L 120 a L 144, y ninguna tableta sale más barata que antes.
 *   · los recargos con que se fijó (pueden ser propios del producto).
 *
 * La escribe una persona al «Cambiar el precio». Los ajustes automáticos
 * NO la tocan: recalculan desde ella.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE YA ESTABA FIJADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se recupera del motivo de la lista que rige hoy, una fila por producto
 * y sede. Lo que no se entiende no se inventa: ese producto se queda sin
 * política hasta que alguien vuelva a cambiarle el precio, y mientras
 * tanto no se ajusta solo (igual que hasta ayer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_minimos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('sede_id')->nullable()->constrained('sedes')->restrictOnDelete();

            /*
             * El envase en que se pensó el mínimo, solo para mostrarlo.
             * Lo que cuenta para calcular es `contenido`: el envase puede
             * retirarse después y el número tiene que seguir sirviendo.
             */
            $tabla->foreignId('item_presentacion_id')->nullable()->constrained('item_presentaciones')->restrictOnDelete();

            $tabla->decimal('minimo', 14, 4);
            $tabla->decimal('contenido', 14, 4);
            $tabla->decimal('recargo_envase', 6, 4);
            $tabla->decimal('recargo_suelta', 6, 4);

            $tabla->string('motivo');

            $tabla->date('vigencia_desde');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->index('item_id');
            $tabla->index('sede_id');
            $tabla->index('item_presentacion_id');
        });

        DB::statement(
            "ALTER TABLE precios_minimos
             ADD COLUMN vigencia daterange
             GENERATED ALWAYS AS (daterange(vigencia_desde, vigencia_hasta, '[]')) STORED"
        );

        /*
         * Un mínimo por producto y sede cada día: con dos, el ajuste
         * automático dependería del ORDER BY.
         */
        DB::statement(
            'ALTER TABLE precios_minimos
             ADD CONSTRAINT precios_minimos_sin_traslape
             EXCLUDE USING gist (
                 item_id WITH =,
                 (COALESCE(sede_id, 0)) WITH =,
                 vigencia WITH &&
             )
             WHERE (deleted_at IS NULL)'
        );

        DB::statement(
            'ALTER TABLE precios_minimos
             ADD CONSTRAINT precios_minimos_numeros_validos
             CHECK (minimo > 0 AND contenido > 0 AND recargo_envase >= 0 AND recargo_suelta >= 0)'
        );

        DB::statement(
            'ALTER TABLE precios_minimos
             ADD CONSTRAINT precios_minimos_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        DB::statement(
            'ALTER TABLE precios_minimos
             ADD CONSTRAINT precios_minimos_motivo_explicado
             CHECK (length(btrim(motivo)) >= 10)'
        );

        $this->recuperarLoQueYaEstabaFijado();
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_minimos');
    }

    /**
     * Una política por producto y sede, sacada del motivo de la lista que
     * rige hoy. El formato es el que escribe
     * `FijadorDePreciosPorForma::motivoCompleto()`; se copia acá y no se
     * llama a la clase, porque una migración tiene que seguir corriendo
     * igual aunque la clase cambie mañana.
     */
    private function recuperarLoQueYaEstabaFijado(): void
    {
        /* La fecha la pone PHP, no el servidor de la base (§7.5-1). */
        $hoy = now()->toDateString();

        $filas = DB::table('tarifarios as t')
            ->leftJoin('item_presentaciones as p', 'p.id', '=', 't.item_presentacion_id')
            ->whereNull('t.deleted_at')
            ->whereNull('t.convenio_id')
            ->whereDate('t.vigencia_desde', '<=', $hoy)
            ->where(fn ($sub) => $sub->whereNull('t.vigencia_hasta')->orWhereDate('t.vigencia_hasta', '>=', $hoy))
            ->where('t.motivo', 'ilike', '%— Mínimo%')
            ->orderBy('t.item_id')
            ->orderBy('t.sede_id')
            ->orderByDesc('t.vigencia_desde')
            ->orderByDesc('t.id')
            ->get(['t.item_id', 't.sede_id', 't.item_presentacion_id', 't.motivo', 't.vigencia_desde', 'p.unidades_por_presentacion']);

        $patron = '/—\s*Mínimo\s*L\.?\s*([\d,]+(?:\.\d+)?)\s+por\s+(.+?);\s*envase más chico \+(\d+(?:\.\d+)?)\s*%,\s*suelta \+(\d+(?:\.\d+)?)\s*%/iu';

        $vistas = [];

        foreach ($filas as $fila) {
            $llave = $fila->item_id.'|'.($fila->sede_id ?? 0);

            /*
             * Vienen de la más nueva a la más vieja: la primera que se
             * entiende es la que rige.
             */
            if (isset($vistas[$llave]) || ! is_string($fila->motivo) || preg_match($patron, $fila->motivo, $partes) !== 1) {
                continue;
            }

            $minimo = str_replace(',', '', $partes[1]);
            $rotulo = trim($partes[2]);
            $envase = $partes[3];
            $suelta = $partes[4];

            /*
             * «CAJA X 100 TAB» trae 100; la suelta —«TABLETA»— trae una.
             */
            $contenido = preg_match('/\bX\s+(\d+(?:\.\d+)?)\b/u', $rotulo, $cuanto) === 1 ? $cuanto[1] : '1';

            if (! is_numeric($minimo) || ! is_numeric($contenido) || ! is_numeric($envase) || ! is_numeric($suelta)
                || bccomp($minimo, '0', 4) <= 0 || bccomp($contenido, '0', 4) <= 0) {
                continue;
            }

            $vistas[$llave] = true;

            $presentacion = DB::table('tarifarios as t')
                ->join('item_presentaciones as p', 'p.id', '=', 't.item_presentacion_id')
                ->whereNull('t.deleted_at')
                ->where('t.item_id', $fila->item_id)
                ->where('t.motivo', $fila->motivo)
                ->whereDate('t.vigencia_desde', $fila->vigencia_desde)
                ->where('p.unidades_por_presentacion', $contenido)
                ->value('p.id');

            DB::table('precios_minimos')->insert([
                'item_id'              => $fila->item_id,
                'sede_id'              => $fila->sede_id,
                'item_presentacion_id' => $presentacion,
                'minimo'               => $minimo,
                'contenido'            => $contenido,
                'recargo_envase'       => bcdiv($envase, '100', 4),
                'recargo_suelta'       => bcdiv($suelta, '100', 4),
                'motivo'               => 'RECUPERADO DEL PRECIO QUE RIGE DESDE EL '.date('d/m/Y', (int) strtotime((string) $fila->vigencia_desde)),
                'vigencia_desde'       => $fila->vigencia_desde,
                'vigencia_hasta'       => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }
};
