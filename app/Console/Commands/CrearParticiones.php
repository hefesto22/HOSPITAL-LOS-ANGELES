<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea por adelantado las particiones mensuales de las tablas grandes.
 *
 * ⚠️ Va SIEMPRE por delante del calendario, nunca al día.
 *
 * Sin particiones futuras, todo cae en la partición DEFAULT. Eso no rompe
 * nada de inmediato —por eso existe la default— pero la convierte en un
 * montón sin índice útil, y adjuntar particiones nuevas después obliga a
 * escanearla entera. En una tabla de decenas de millones de filas eso es
 * un lock largo, y **en un hospital no hay ventana de mantenimiento a la
 * que apelar** (§12).
 *
 * Se programa mensualmente. Correrlo de más es inofensivo: es idempotente.
 */
class CrearParticiones extends Command
{
    protected $signature = 'sihla:crear-particiones
                            {--meses=6 : Cuántos meses hacia adelante asegurar}
                            {--pretend : Muestra el SQL sin ejecutarlo}';

    protected $description = 'Crea las particiones mensuales futuras de las tablas particionadas por fecha.';

    /**
     * Tabla => columna de particionado.
     *
     * Esta lista crece con cada tabla particionada nueva: cargos,
     * resultados, movimientos_inventario, signos_vitales,
     * asignaciones_cama (§12).
     *
     * @var array<string, string>
     */
    private const TABLAS = [
        'accesos_expediente' => 'ocurrido_en',
    ];

    public function handle(): int
    {
        $meses = max(1, (int) $this->option('meses'));
        $simular = (bool) $this->option('pretend');

        $creadas = 0;

        foreach (array_keys(self::TABLAS) as $tabla) {
            if (! $this->esParticionada($tabla)) {
                $this->warn("  ⚠ {$tabla} no está particionada; se omite.");

                continue;
            }

            for ($i = 0; $i <= $meses; $i++) {
                $desde = now()->startOfMonth()->addMonths($i);
                $hasta = $desde->copy()->addMonth();
                $nombre = "{$tabla}_".$desde->format('Y_m');

                if ($this->existe($nombre)) {
                    continue;
                }

                $sql = sprintf(
                    "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                    $nombre,
                    $tabla,
                    $desde->toDateString(),
                    $hasta->toDateString(),
                );

                if ($simular) {
                    $this->line("  {$sql};");

                    continue;
                }

                DB::statement($sql);
                $this->info("  ✓ {$nombre}");
                $creadas++;
            }
        }

        $this->newLine();
        $this->info($simular
            ? 'Simulación terminada. Nada se ejecutó.'
            : "Listo. Particiones creadas: {$creadas}.");

        return self::SUCCESS;
    }

    private function esParticionada(string $tabla): bool
    {
        /** @var object{existe: bool}|null $fila */
        $fila = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = ? AND c.relkind = 'p' AND n.nspname = current_schema()
            ) AS existe",
            [$tabla]
        );

        return (bool) ($fila->existe ?? false);
    }

    private function existe(string $tabla): bool
    {
        /** @var object{existe: bool}|null $fila */
        $fila = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1 FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = ? AND n.nspname = current_schema()
            ) AS existe',
            [$tabla]
        );

        return (bool) ($fila->existe ?? false);
    }
}
