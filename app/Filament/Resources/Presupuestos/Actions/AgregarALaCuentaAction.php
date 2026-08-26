<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Actions;

use App\Domain\Enums\EstadoPresupuesto;
use App\Models\Presupuesto;
use App\Services\AgregadorDePresupuestoALaCuenta;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Pone el paquete en la cuenta del paciente (ADR-0009).
 *
 * A partir de acá la cuenta muestra «APENDICECTOMIA · L 40,000» y ese
 * monto sigue al presupuesto: cada vez que se toca un renglón, el cargo
 * se vuelve a asentar con el total nuevo.
 */
class AgregarALaCuentaAction
{
    public static function make(): Action
    {
        return Action::make('agregar_a_la_cuenta')
            ->label(fn (Presupuesto $record): string => $record->estado === EstadoPresupuesto::Borrador
                ? 'Agregar a la cuenta'
                : 'Reponer en la cuenta')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            /*
             * 🔴 LA VERDAD ES EL CARGO, NO EL ESTADO.
             *
             * Preguntar por `estado === Borrador` esconde el botón en dos
             * casos donde SÍ hace falta: un presupuesto que quedó marcado
             * como agregado sin que exista el cargo (dato heredado del
             * rename de «emitido»), y uno cuyo cargo alguien anuló.
             *
             * En los dos, la cuenta no tiene el paquete y no habría forma
             * de ponerlo. Se pregunta por lo que se puede verificar: si
             * hay un cargo vivo de este presupuesto.
             */
            ->visible(fn (Presupuesto $record): bool => $record->estado->esEditable()
                && app(AgregadorDePresupuestoALaCuenta::class)->cargoVigente($record) === null)
            ->modalHeading('Agregar el paquete a la cuenta del paciente')
            ->modalDescription(
                'La cuenta va a mostrar un solo renglón con el nombre y el total. Lo que se consuma y esté presupuestado no se le vuelve a cobrar; lo que no estaba, se cobra aparte y avisa.'
            )
            ->modalSubmitActionLabel('Agregar')
            ->requiresConfirmation()
            ->action(function (Presupuesto $record): void {
                abort_unless(Gate::allows('update', $record), 403);

                try {
                    $cargo = app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($record);
                } catch (Throwable $e) {
                    /*
                     * El mensaje del Service ya dice qué hacer —abrir la
                     * cuenta, elegir el ingreso—. Mostrarlo tal cual es
                     * más útil que un «ocurrió un error».
                     */
                    Notification::make()
                        ->danger()
                        ->title('No se pudo agregar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                if ($cargo === null) {
                    Notification::make()
                        ->warning()
                        ->title('El presupuesto no tiene nada que cobrar')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Agregado a la cuenta')
                    ->body('Desde ahora el monto de la cuenta sigue a este presupuesto: si cambiás un renglón, se actualiza solo.')
                    ->send();
            });
    }
}
