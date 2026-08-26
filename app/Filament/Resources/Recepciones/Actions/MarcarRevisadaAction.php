<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Actions;

use App\Domain\Exceptions\RecepcionException;
use App\Models\Recepcion;
use App\Services\RegistradorDeRecepcion;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * «Ya la miré.»
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO NO MUEVE NADA, Y AHÍ ESTÁ SU VALOR
 * ─────────────────────────────────────────────────────────────────────
 *
 * La mercadería entró cuando se guardó la recepción. Marcarla revisada
 * solo la saca de la lista de pendientes — y por eso NO puede hacerlo
 * quien la recibió: firmarse el propio trabajo dejaría a esa lista sin
 * significar nada.
 *
 * Es la contracara de haber sacado el paso de confirmación: se ganó la
 * velocidad de recibir con el teléfono frente al camión, y el control se
 * corrió a un lugar donde no frena a nadie. Lo que lo sostiene es que
 * alguien mire la lista de pendientes todos los días.
 */
final class MarcarRevisadaAction
{
    public static function make(): Action
    {
        return Action::make('marcarRevisada')
            ->label('Ya la revisé')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Marcar como revisada')
            ->modalDescription(
                'Esto no mueve existencias —la mercadería ya está en el kardex— y no se puede '
                .'deshacer. Queda constando que la revisaste vos.'
            )
            ->modalSubmitActionLabel('Sí, la revisé')
            ->visible(fn (Recepcion $record): bool => self::puedeVerse($record))
            /*
             * ─────────────────────────────────────────────────────────
             * EL CUATRO OJOS SE DICE ANTES, NO DESPUÉS DEL CLIC
             * ─────────────────────────────────────────────────────────
             *
             * El servicio ya lo impide —y ahí tiene que seguir, porque un
             * comando o un import no pasan por esta pantalla—. Pero
             * ofrecer un botón que se va a negar enseña a desconfiar de
             * los botones. Deshabilitado y con el motivo en el tooltip,
             * el control se entiende en vez de sorprender.
             *
             * Sigue VISIBLE a propósito: que exista es lo que le recuerda
             * a quien recibió que alguien más tiene que pasar a mirar.
             */
            ->disabled(fn (Recepcion $record): bool => self::laCargoElMismo($record))
            ->tooltip(fn (Recepcion $record): ?string => self::laCargoElMismo($record)
                ? 'La cargaste vos: la tiene que revisar otra persona.'
                : null)
            ->action(function (Recepcion $record): void {
                try {
                    app(RegistradorDeRecepcion::class)->marcarRevisada($record);

                    Notification::make()
                        ->success()
                        ->title('Revisada')
                        ->body("{$record->etiqueta()} salió de la lista de pendientes.")
                        ->send();
                } catch (RecepcionException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo marcar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function puedeVerse(Recepcion $recepcion): bool
    {
        return ! $recepcion->estaRevisada() && Gate::allows('update', $recepcion);
    }

    /**
     * ¿La está por firmar la misma persona que la cargó?
     *
     * Se compara contra `created_by`, que es exactamente lo que mira
     * `RegistradorDeRecepcion::marcarRevisada()`. Si algún día cambia
     * allá, esto tiene que cambiar acá —pero el que manda sigue siendo
     * el servicio: esto es cortesía de pantalla, no el control.
     */
    public static function laCargoElMismo(Recepcion $recepcion): bool
    {
        $usuario = UsuarioAutenticado::id();

        return $usuario !== null && $usuario === $recepcion->created_by;
    }
}
