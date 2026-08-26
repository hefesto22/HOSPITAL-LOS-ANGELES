<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Actions;

use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ConteoException;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Models\Conteo;
use App\Models\User;
use App\Services\CerradorDeConteo;
use App\Services\RegistradorDeAjuste;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

/**
 * Cerrar el conteo — el único botón de este módulo que mueve el kardex.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA CONFIRMACIÓN DICE NÚMEROS, NO «¿ESTÁ SEGURO?»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Antes de apretar, la persona ve cuántas líneas no cuadraron. Un modal
 * que solo pregunta si está seguro se contesta que sí sin leerlo, y a la
 * tercera vez ya nadie lo mira. Uno que dice «se van a asentar 7
 * diferencias» hace que alguien note que esperaba 2.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO CIERRA QUIEN ABRIÓ
 * ─────────────────────────────────────────────────────────────────────
 *
 * El botón ni se muestra para quien abrió el conteo. La base lo vuelve a
 * exigir con el CHECK `conteos_cuatro_ojos`, porque un botón escondido no
 * es un candado — es una sugerencia.
 */
final class CerrarConteoAction
{
    public static function make(): Action
    {
        return Action::make('cerrarConteo')
            ->label('Cerrar y asentar diferencias')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading('Cerrar el conteo')
            /*
             * El modal dice NÚMEROS. Uno que solo pregunta «¿está
             * seguro?» se contesta que sí sin leerlo, y a la tercera vez
             * ya nadie lo mira. Uno que dice «se van a asentar 7
             * diferencias» hace que alguien note que esperaba 2.
             */
            ->modalDescription(fn (Conteo $record): string => self::loQueVaAPasar($record))
            ->modalSubmitActionLabel('Cerrar y asentar')
            ->visible(fn (Conteo $record): bool => self::puedeVerse($record))
            ->schema([
                Select::make('autorizador_id')
                    ->label('Autoriza (dirección)')
                    ->options(fn (): array => self::posiblesAutorizadores())
                    ->searchable()
                    ->native(false)
                    ->helperText(
                        'Solo hace falta si las diferencias suman más de L '
                        .RegistradorDeAjuste::tope()->redondeado(2)
                        .' al costo. Si no llega a ese monto, dejalo vacío.'
                    ),
            ])
            ->action(function (Conteo $record, array $data): void {
                $autorizador = self::autorizador($data);

                try {
                    $resultado = app(CerradorDeConteo::class)->cerrar($record, $autorizador);

                    $notificacion = Notification::make()
                        ->title('Conteo cerrado')
                        ->body($resultado->resumen());

                    /*
                     * Cuando quedaron controlados sin ajustar, la
                     * notificación es de advertencia y persistente: es un
                     * hallazgo que alguien tiene que resolver hoy en el
                     * libro, no un aviso que se desvanece en cuatro
                     * segundos.
                     */
                    $resultado->hayPendientes()
                        ? $notificacion->warning()->persistent()
                        : $notificacion->success();

                    $notificacion->send();
                } catch (ConteoException|AjusteException|ExistenciaInsuficienteException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo cerrar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                } catch (QueryException) {
                    /*
                     * Dos personas apretaron «Cerrar» a la vez y la base
                     * paró a la segunda. No queda nada a medias —la
                     * transacción revirtió entera—, pero el mensaje que
                     * saldría es SQL crudo, y quien lo lee está en la
                     * bodega, no en un IDE.
                     */
                    Notification::make()
                        ->warning()
                        ->title('Alguien más lo estaba cerrando')
                        ->body(
                            'El conteo lo cerró otra persona en este mismo momento. No se asentó '
                            .'nada dos veces: recargá la página para ver cómo quedó.'
                        )
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function puedeVerse(Conteo $conteo): bool
    {
        return $conteo->estaAbierto() && Gate::allows('update', $conteo);
    }

    /**
     * La frase del modal, con los números del conteo adentro.
     */
    private static function loQueVaAPasar(Conteo $conteo): string
    {
        $diferencias = $conteo->cuantasNoCuadraron();

        $cuantas = match (true) {
            $diferencias === 0 => 'No hay ninguna diferencia: el estante y el sistema dicen lo '
                .'mismo, así que cerrar no va a mover nada.',
            $diferencias === 1 => 'Hay 1 diferencia y se va a asentar en el kardex como un ajuste '
                .'con su motivo.',
            default => "Hay {$diferencias} diferencias y se van a asentar en el kardex como un "
                .'ajuste con su motivo.',
        };

        return $cuantas.' No se puede deshacer: un ajuste equivocado se corrige con otro ajuste. '
            .'Las diferencias de medicamentos controlados NO se asientan — van por el libro.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function autorizador(array $data): ?User
    {
        $id = $data['autorizador_id'] ?? null;

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    /**
     * Los usuarios que pueden autorizar por encima del tope.
     *
     * @return array<int, string>
     */
    private static function posiblesAutorizadores(): array
    {
        $roles = RegistradorDeAjuste::rolesQueAutorizan();

        /** @var array<int, string> $opciones */
        $opciones = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $sub): Builder => $sub->whereIn('name', $roles))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return $opciones;
    }
}
