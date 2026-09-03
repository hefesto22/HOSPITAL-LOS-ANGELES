<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos\Actions;

use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Prestamo;
use App\Services\RegistradorDePrestamo;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Se le pagó a quien prestó.
 *
 * No mueve inventario: lo prestado entró y se queda. Y no admite pagos
 * parciales — el monto se pactó completo al registrar, y llevar mitades
 * de plata acá sería inventar un módulo de cuentas por pagar adentro del
 * de inventario.
 *
 * ⚠️ Pendiente conocido: esta plata es costo de verdad y todavía NO entra
 * al costo promedio del producto. Se cierra cuando compras aprenda a
 * recibir una factura contra un préstamo.
 */
final class MarcarPagadoAction
{
    public static function make(): Action
    {
        return Action::make('marcarPagado')
            ->label('Marcar pagado')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Prestamo $record): bool => $record->estado->sigueAbierto()
                && $record->forma_de_saldo === FormaDeSaldo::Pagar)
            /*
             * Filament NO autoriza las acciones de fila por su cuenta:
             * sin esto, cualquiera que llegue a la pantalla salda una
             * deuda. `update` es el permiso de saldar (ver la policy).
             */
            ->authorize(fn (Prestamo $record): bool => Gate::allows('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Prestamo $record): string => "Pagarle a {$record->presta_nombre}")
            ->modalDescription(fn (Prestamo $record): string => 'Se le pagan L '
                .Decimal::de($record->monto_acordado ?? '0')->redondeado(2)
                .'. El inventario no se mueve: lo prestado entró y se queda.')
            ->modalSubmitActionLabel('Marcar pagado')
            ->modalWidth('md')
            ->schema([
                TextInput::make('referencia')
                    ->label('Referencia del pago')
                    ->maxLength(255)
                    ->placeholder('Número de recibo, cheque, transferencia…')
                    ->helperText('Opcional, pero es lo único que va a permitir cruzar este pago contra la caja.'),
            ])
            ->action(function (Prestamo $record, array $data): void {
                $referencia = is_string($data['referencia'] ?? null) && trim($data['referencia']) !== ''
                    ? trim($data['referencia'])
                    : null;

                try {
                    $saldado = app(RegistradorDePrestamo::class)->marcarPagado($record, $referencia);
                } catch (PrestamoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo marcar como pagado')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Préstamo saldado')
                    ->body("Ya no se le debe nada a {$saldado->presta_nombre}.")
                    ->send();
            });
    }
}
