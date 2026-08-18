<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Models\Sede;
use App\Support\ContextoSede;
use Filament\Forms\Components\Select;

/**
 * Selector de sede, reutilizable por todo Resource que use BelongsToSede.
 *
 * Se comporta distinto según quién mira, y esa es toda su razón de ser:
 *
 *  - **Dirección y soporte** ven el selector con las sedes vigentes y
 *    eligen. Son los únicos roles que cruzan sedes (§9.L5).
 *  - **Todos los demás** no ven nada: el campo va oculto y se rellena solo
 *    con su sede. Mostrarle un selector de una sola opción a una cajera es
 *    ruido, y mostrarle dos es un error esperando a pasar.
 *
 * El trait BelongsToSede ya rellena `sede_id` al crear. Este campo existe
 * para que dirección pueda elegir OTRA sede a propósito, no para suplirlo.
 */
final class SedeField
{
    public static function make(string $name = 'sede_id'): Select
    {
        $puedeElegir = ContextoSede::idsVisibles() === null;

        return Select::make($name)
            ->label('Sede')
            ->relationship(
                name: 'sede',
                titleAttribute: 'nombre',
                modifyQueryUsing: fn ($query) => $query->vigentesEn(now()),
            )
            ->getOptionLabelFromRecordUsing(fn (Sede $record): string => $record->etiqueta())
            ->searchable()
            ->preload()
            ->required()
            ->default(ContextoSede::actualId())
            ->visible($puedeElegir)
            ->dehydrated()
            ->helperText($puedeElegir ? 'Solo dirección y soporte pueden mover registros entre sedes.' : null);
    }
}
