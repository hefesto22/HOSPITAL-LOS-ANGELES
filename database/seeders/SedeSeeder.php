<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sede;
use Illuminate\Database\Seeder;

/**
 * Siembra la sede principal.
 *
 * Idempotente: `updateOrCreate` por código, así que correrlo dos veces no
 * duplica ni pisa datos que el hospital ya haya corregido en el panel.
 *
 * ⚠️ Los valores fiscales van vacíos a propósito. RTN, código de
 * establecimiento del SAR y registro de SESAL son datos reales del
 * hospital: inventarlos acá haría que alguien los diera por buenos y
 * termináramos emitiendo una factura con un RTN falso. Se llenan desde el
 * panel, y la facturación va a exigirlos antes de emitir.
 */
class SedeSeeder extends Seeder
{
    public function run(): void
    {
        $sede = Sede::withTrashed()->updateOrCreate(
            ['codigo' => 'HLA'],
            [
                'nombre'         => (string) config('sihla.organizacion.nombre', 'Hospital Los Ángeles'),
                'razon_social'   => (string) config('sihla.organizacion.nombre', 'Hospital Los Ángeles'),
                'vigencia_desde' => now()->startOfYear()->toDateString(),
                'vigencia_hasta' => null,
                'deleted_at'     => null,
            ]
        );

        $this->command?->info("✓ Sede principal lista: {$sede->etiqueta()}");
        $this->command?->warn('  Falta cargar RTN, código de establecimiento del SAR y registro SESAL desde el panel.');
    }
}
