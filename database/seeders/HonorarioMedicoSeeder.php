<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\Unidad;
use Illuminate\Database\Seeder;

/**
 * El ítem «HONORARIO MÉDICO» — al que va el honorario cuando en la cuenta
 * se busca al doctor por su nombre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNO SOLO PARA TODOS LOS MÉDICOS (Mauricio, 6-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Se busca por el nombre del doctor, ahí se le coloca cuánto es del
 * honorario, y en la factura sale honorario, nombre del doctor y
 * especialidad.» El médico y su especialidad viajan en el renglón —ver
 * `RegistradorDeCargo::nombreDelRenglon()`—, así que el ítem no necesita
 * una copia por doctor ni por especialidad.
 *
 * ⚠️ Lo que se aceptó a cambio: el descuento de ley de tercera y cuarta
 * edad sale como CONSULTA ESPECIALIZADA, también en una cirugía. Los
 * honorarios que necesitan otra categoría siguen siendo sus propios
 * ítems y se buscan por su nombre.
 *
 * Sin precio de lista a propósito: al particular el número lo pone quien
 * cobra, y a un seguro se le cobra lo que el doctor tenga pactado con él.
 *
 *     php artisan db:seed --class=HonorarioMedicoSeeder
 *
 * Idempotente por código: si el hospital le cambió el nombre o la
 * categoría desde el catálogo, no se pisa.
 */
class HonorarioMedicoSeeder extends Seeder
{
    /**
     * Desde antes del arranque: un cargo tardío con fecha de servicio
     * anterior a hoy tiene que encontrar el ítem vigente.
     */
    private const VIGENCIA_DESDE = '2026-01-01';

    public function run(): void
    {
        $codigo = (string) config('sihla.facturacion.item_del_honorario_medico', 'HON-MED');

        $unidad = Unidad::query()->where('codigo', 'UND')->first();
        $consultas = CategoriaItem::query()->where('codigo', 'CON')->first();

        Item::query()->firstOrCreate(
            ['codigo' => $codigo],
            [
                'nombre'                    => 'HONORARIO MÉDICO',
                'tipo'                      => TipoItem::Honorario,
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => CategoriaLegalDeDescuento::ConsultaEspecializada,
                'unidad_dispensacion_id'    => $unidad instanceof Unidad ? $unidad->id : null,
                'categoria_id'              => $consultas instanceof CategoriaItem ? $consultas->id : null,
                'requiere_lote'             => false,
                'requiere_receta'           => false,
                'es_controlado'             => false,
                'vigencia_desde'            => self::VIGENCIA_DESDE,
            ],
        );

        $this->command?->info("✓ Ítem {$codigo} · HONORARIO MÉDICO listo.");
    }
}
