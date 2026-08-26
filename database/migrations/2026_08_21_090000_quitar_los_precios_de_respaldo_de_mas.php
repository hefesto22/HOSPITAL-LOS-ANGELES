<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SE VAN LOS PRECIOS DE RESPALDO QUE NO ERAN DE NADIE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PASÓ
 * ─────────────────────────────────────────────────────────────────────
 *
 * La primera versión del sembrado de precios creaba DOS filas por cada
 * línea recibida: la del envase y una «del producto entero», calculada
 * con el costo promedio del almacén. En acetaminofén eso dejaba CUATRO
 * precios para TRES frascos, y el cuarto no le correspondía a ninguna
 * existencia — era el promedio amontonado que se decidió no usar.
 *
 * 🔴 Y no era cosmético. `Tarifario::scopeResolviendoPara` cae al precio
 * sin envase cuando el del envase no existe, y no avisa. Mientras esa
 * fila esté vigente, cualquier frasco que se quede sin su propio precio
 * se cobra al número del respaldo como si fuera suyo. El mililitro del
 * de 120 ML sale L 45.83 y el del de 80 ML sale L 91.67: el doble. No se
 * ve en la factura, se ve en la utilidad del mes.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SE TOCA Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Solo las filas que cumplen las tres cosas:
 *
 *   · sin envase,
 *   · con el motivo que escribe el sembrado automático —o sea, derivadas,
 *     no decididas por una persona—,
 *   · y de un producto que YA tiene precio por envase.
 *
 * Lo último es lo que separa «sobra» de «es el único que hay»: un
 * producto que llegó a granel necesita su fila sin envase, y esa se
 * queda. Un precio fijado a mano no entra nunca, tenga el envase que
 * tenga: eso es una decisión de dirección con fecha, autor y motivo.
 *
 * ⚠️ Una fila que ya salió cobrada NO se borra: se cierra. `cargos`
 * apunta a `tarifarios` con ON DELETE RESTRICT, así que borrarla haría
 * fallar la migración — pero además, borrar el precio con el que se
 * facturó dejaría una factura que no se puede reimprimir igual. Cerrarla
 * deja de resolverse hacia adelante y conserva lo que ya pasó.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conPrecioPorEnvase = DB::table('tarifarios')
            ->whereNotNull('item_presentacion_id')
            ->whereNull('deleted_at')
            ->select('item_id');

        $deMas = DB::table('tarifarios')
            ->whereNull('item_presentacion_id')
            ->whereNull('convenio_id')
            ->whereNull('deleted_at')
            ->where('motivo', 'like', 'Calculado en el primer ingreso a bodega:%')
            ->whereIn('item_id', $conPrecioPorEnvase)
            ->pluck('id')
            ->all();

        if ($deMas === []) {
            return;
        }

        $yaSeCobraron = DB::table('cargos')
            ->whereIn('tarifario_id', $deMas)
            ->distinct()
            ->pluck('tarifario_id')
            ->all();

        $nuncaSeUsaron = array_values(array_diff($deMas, $yaSeCobraron));

        if ($nuncaSeUsaron !== []) {
            DB::table('tarifarios')->whereIn('id', $nuncaSeUsaron)->delete();
        }

        if ($yaSeCobraron !== []) {
            DB::table('tarifarios')
                ->whereIn('id', $yaSeCobraron)
                ->whereNull('vigencia_hasta')
                ->update(['vigencia_hasta' => now()->toDateString()]);
        }
    }

    /**
     * No hay vuelta atrás y está bien que no la haya: lo que se fue era
     * un número derivado que nadie decidió, y la próxima recepción vuelve
     * a sembrar lo que corresponda. Reponerlo sería reponer el bug.
     */
    public function down(): void {}
};
