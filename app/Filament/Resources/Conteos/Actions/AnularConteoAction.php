<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Actions;

use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ConteoException;
use App\Models\Conteo;
use App\Services\CerradorDeConteo;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Anular un conteo que se abrió por error o se abandonó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ANULAR NO ES BORRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * El conteo queda, con todo lo que se alcanzó a contar y con la
 * explicación de por qué no siguió. Borrarlo dejaría sin rastro la tarde
 * que dos personas pasaron frente al estante — y, peor, permitiría hacer
 * desaparecer un conteo que estaba mostrando un faltante incómodo.
 *
 * Por eso el motivo es obligatorio y con largo mínimo: «error» no
 * explica nada dentro de seis meses.
 */
final class AnularConteoAction
{
    public static function make(): Action
    {
        return Action::make('anularConteo')
            ->label('Anular conteo')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Anular el conteo')
            ->modalDescription(
                'No mueve inventario y no borra nada: el conteo queda visible, anulado y con tu '
                .'explicación. Lo que se haya contado se conserva.'
            )
            ->modalSubmitActionLabel('Anular')
            ->visible(fn (Conteo $record): bool => self::puedeVerse($record))
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué se anula?')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->placeholder('Se abrió en el almacén equivocado')
                    ->helperText(
                        'Al menos diez caracteres. Es lo único que va a quedar para entenderlo '
                        .'dentro de un año.'
                    ),
            ])
            ->action(function (Conteo $record, array $data): void {
                $motivo = $data['motivo'] ?? '';

                try {
                    app(CerradorDeConteo::class)->anular(
                        $record,
                        is_string($motivo) ? $motivo : '',
                    );

                    Notification::make()
                        ->success()
                        ->title('Conteo anulado')
                        ->body('Queda registrado, con lo contado y con tu explicación.')
                        ->send();
                } catch (ConteoException|AjusteException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo anular')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function puedeVerse(Conteo $conteo): bool
    {
        return $conteo->estaAbierto() && Gate::allows('update', $conteo);
    }
}
