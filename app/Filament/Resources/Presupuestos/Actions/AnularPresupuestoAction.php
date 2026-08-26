<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Actions;

use App\Domain\Enums\EstadoPresupuesto;
use App\Models\Presupuesto;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Anular, no borrar.
 *
 * ⚠️ El motivo tiene un mínimo de 10 caracteres y lo exige un CHECK de
 * la base, no solo el formulario: «error» no explica nada dentro de ocho
 * meses, que es cuando alguien pregunta.
 */
class AnularPresupuestoAction
{
    public static function make(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Presupuesto $record): bool => in_array(
                $record->estado,
                [EstadoPresupuesto::Borrador, EstadoPresupuesto::Agregado],
                true
            ))
            ->modalHeading('Anular el presupuesto')
            ->modalSubmitActionLabel('Anular')
            ->schema([
                Textarea::make('motivo_anulacion')
                    ->label('¿Por qué se anula?')
                    ->required()
                    ->minLength(10)
                    ->maxLength(200)
                    ->rows(2)
                    ->helperText('Mínimo diez caracteres, y lo exige la base de datos. «Se cotizó al paciente equivocado», «la familia desistió de la cirugía».'),
            ])
            ->action(function (Presupuesto $record, array $data): void {
                abort_unless(Gate::allows('update', $record), 403);

                $motivo = is_string($data['motivo_anulacion'] ?? null) ? $data['motivo_anulacion'] : '';

                $record->update([
                    'estado'           => EstadoPresupuesto::Anulado,
                    'anulado_en'       => now(),
                    'motivo_anulacion' => $motivo,
                ]);

                Notification::make()
                    ->success()
                    ->title('Presupuesto anulado')
                    ->body('Queda en el historial con el motivo. No se borra.')
                    ->send();
            });
    }
}
