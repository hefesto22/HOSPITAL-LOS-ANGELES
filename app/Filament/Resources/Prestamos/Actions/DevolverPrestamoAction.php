<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos\Actions;

use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Prestamo;
use App\Services\RegistradorDePrestamo;
use App\Support\NumeroDeFormulario;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Se le devolvió producto a quien prestó.
 *
 * Acepta devoluciones PARCIALES porque así llegan: entraron 100 de las
 * 200 que se debían y se devolvieron 60. Obligar a devolver todo de una
 * vez hace que nadie registre nada hasta el final, y el final no llega.
 *
 * ⚠️ La devolución SALE del kardex. Si no hay existencia suficiente en el
 * almacén donde entró el préstamo, `RegistradorDeMovimiento` la rechaza y
 * la transacción se cae sin dejar el documento marcado sobre una
 * devolución que no ocurrió.
 */
final class DevolverPrestamoAction
{
    public static function make(): Action
    {
        return Action::make('devolverPrestamo')
            ->label('Devolver')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Prestamo $record): bool => $record->estado->sigueAbierto()
                && $record->forma_de_saldo === FormaDeSaldo::DevolverProducto)
            /*
             * Filament NO autoriza las acciones de fila por su cuenta:
             * sin esto, cualquiera que llegue a la pantalla salda una
             * deuda. `update` es el permiso de saldar (ver la policy).
             */
            ->authorize(fn (Prestamo $record): bool => Gate::allows('update', $record))
            ->modalHeading(fn (Prestamo $record): string => "Devolverle a {$record->presta_nombre}")
            ->modalDescription(
                'Sale del almacén donde entró el préstamo. Si no hay existencia suficiente, la '
                .'devolución se rechaza y el préstamo queda como estaba.'
            )
            ->modalSubmitActionLabel('Devolver')
            ->modalWidth('md')
            ->schema([
                TextInput::make('cantidad')
                    ->label('Cuánto se le devuelve')
                    ->required()
                    ->rule('regex:/^\d{1,10}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta cuatro decimales.',
                    ])
                    ->default(fn (Prestamo $record): string => $record->saldoPendiente()->redondeado(4))
                    ->helperText(fn (Prestamo $record): string => 'Quedan '
                        .$record->saldoPendiente()->redondeado(2).' pendientes de '
                        .Decimal::de($record->cantidad)->redondeado(2).'.'),

                TextInput::make('referencia')
                    ->label('Referencia')
                    ->maxLength(255)
                    ->placeholder('Quién la llevó, número de remisión…')
                    ->helperText('Opcional. Es lo que va a quedar para entender esta devolución dentro de un año.'),
            ])
            ->action(function (Prestamo $record, array $data): void {
                $cantidad = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

                if (! $cantidad instanceof Decimal) {
                    Notification::make()
                        ->danger()
                        ->title('Falta la cantidad')
                        ->send();

                    return;
                }

                $referencia = is_string($data['referencia'] ?? null) && trim($data['referencia']) !== ''
                    ? trim($data['referencia'])
                    : null;

                try {
                    $saldado = app(RegistradorDePrestamo::class)->devolver($record, $cantidad, $referencia);
                } catch (PrestamoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo registrar la devolución')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($saldado->estado->sigueAbierto()
                        ? 'Devolución registrada; todavía queda saldo'
                        : 'Préstamo saldado')
                    ->body($saldado->estado->sigueAbierto()
                        ? "Quedan {$saldado->saldoPendiente()->redondeado(2)} por devolverle a {$saldado->presta_nombre}."
                        : "Ya no se le debe nada a {$saldado->presta_nombre}.")
                    ->send();
            });
    }
}
