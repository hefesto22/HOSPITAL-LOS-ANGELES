<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\CambiadorDeStockInfinito;
use App\Services\UbicadorDeProducto;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deja en regla a los productos que ya estaban en stock infinito.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ EXISTE (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La primera versión del stock infinito dejaba la existencia viva debajo
 * del producto, y no sabía dónde estaba. La regla de Mauricio ahora es
 * otra: un infinito no tiene existencia —se da de baja al marcarlo— pero
 * sí tiene lugares.
 *
 * Este comando pasa por todos los infinitos y hace lo mismo que volver a
 * marcarlos: da de baja lo que les quedó, con su línea en el kardex, y
 * los deja en los almacenes donde tenían algo —o en farmacia si no
 * tenían en ninguno ni tenían lugar anotado—.
 *
 * Se puede correr las veces que haga falta: a un infinito ya en regla no
 * le cambia nada.
 */
class OrdenarStockInfinito extends Command
{
    protected $signature = 'sihla:ordenar-stock-infinito';

    protected $description = 'Da de baja la existencia vieja de los productos con stock infinito y les anota dónde están.';

    public function handle(CambiadorDeStockInfinito $cambiador, UbicadorDeProducto $ubicaciones): int
    {
        $revisados = 0;
        $conExistencia = 0;
        $sinLugar = 0;

        Item::query()
            ->where('stock_infinito', true)
            ->orderBy('id')
            ->chunkById(100, function (Collection $items) use ($cambiador, $ubicaciones, &$revisados, &$conExistencia, &$sinLugar): void {
                /** @var Collection<int, Item> $items */
                $resultado = $cambiador->cambiar($items, true);

                $revisados += $items->count();
                $conExistencia += $resultado['cambiados'];

                foreach ($items as $item) {
                    if ($ubicaciones->dondeEsta($item) === []) {
                        $sinLugar++;
                    }
                }
            });

        $this->info("{$revisados} producto(s) con stock infinito revisados.");
        $this->line("{$conExistencia} tenían existencia vieja: quedó en cero, con su línea en el kardex.");

        if ($sinLugar > 0) {
            $this->warn(
                "{$sinLugar} quedaron sin almacén porque no hay ninguna farmacia vigente. "
                .'Anótelos en Existencias con «Mover».'
            );
        }

        return self::SUCCESS;
    }
}
