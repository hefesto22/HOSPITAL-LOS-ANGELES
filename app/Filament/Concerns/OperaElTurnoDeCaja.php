<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Support\ContextoSede;
use App\Support\NumeroDeFormulario;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Abrir y cerrar el turno, desde cualquier pantalla donde entre plata.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO VIVE SOLO EN LA PANTALLA DE CAJA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque el turno se abre donde se cobra. La cajera llega, abre
 * «Cuentas abiertas» —que es donde pasa el día— y el primer paciente ya
 * está enfrente: mandarla a otra pantalla a abrir su turno antes de
 * poder recibir el primer abono es una vuelta que en el mostrador se
 * traduce en «el sistema no me deja cobrar».
 *
 * El mismo botón cambia solo: sin turno dice ABRIR, con turno dice
 * CERRAR. No hay forma de tener los dos, porque no hay forma de tener
 * dos turnos.
 */
trait OperaElTurnoDeCaja
{
    /**
     * El turno abierto de quien está mirando. `null` = todavía no abrió.
     */
    public function turno(): ?TurnoDeCaja
    {
        $usuario = $this->usuarioDeCaja();

        if (! $usuario instanceof User) {
            return null;
        }

        return app(AbridorDeTurnoDeCaja::class)->abiertoDe($usuario);
    }

    public function abrirTurnoAction(): Action
    {
        return Action::make('abrirTurno')
            ->label('Abrir turno')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->modalHeading('Abrir el turno de caja')
            ->modalDescription(
                'Declará con cuánto efectivo arrancás. Ese fondo es la base del arqueo: al cerrar, lo contado '
                .'se compara contra el fondo más lo que recibiste en billetes.'
            )
            ->modalSubmitActionLabel('Abrir el turno')
            ->visible(fn (): bool => ! $this->turno() instanceof TurnoDeCaja
                && Gate::allows('create', TurnoDeCaja::class))
            ->schema([
                /*
                 * Viene puesto con el turno asignado a la persona en su
                 * ficha de usuario: un campo menos que teclear con el
                 * primer paciente ya enfrente. Se puede cambiar si hoy
                 * cubre otro turno.
                 */
                TextInput::make('nombre')
                    ->label('¿Cómo se llama este turno?')
                    ->placeholder('Turno A')
                    ->maxLength(40)
                    ->default(fn (): ?string => $this->usuarioDeCaja()?->turno)
                    ->helperText('Viene del turno asignado en tu usuario. Para reconocerlo después en el reporte.'),

                TextInput::make('fondo_inicial')
                    ->label('Fondo con el que abrís')
                    ->required()
                    ->default('0')
                    ->prefix('L')
                    ->inputMode('decimal')
                    ->helperText('El efectivo que ya está en la gaveta antes de cobrarle a nadie.'),
            ])
            ->action(function (array $data): void {
                $usuario = $this->usuarioDeCaja();
                $sede = $this->sedeDeCaja();

                if (! $usuario instanceof User || ! $sede instanceof Sede) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo abrir el turno')
                        ->body('No hay una sede activa para tu usuario. Avisale a dirección.')
                        ->persistent()
                        ->send();

                    return;
                }

                try {
                    $turno = app(AbridorDeTurnoDeCaja::class)->abrir(
                        usuario: $usuario,
                        sede: $sede,
                        fondoInicial: NumeroDeFormulario::aDecimalO($data['fondo_inicial'] ?? null, Decimal::cero()),
                        nombre: is_string($data['nombre'] ?? null) ? $data['nombre'] : null,
                    );
                } catch (SihlaException $e) {
                    Notification::make()->danger()->title('No se pudo abrir el turno')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Turno abierto')
                    ->body("{$turno->etiqueta()}. Ya podés recibir abonos.")
                    ->send();
            });
    }

    public function cerrarTurnoAction(): Action
    {
        return Action::make('cerrarTurno')
            /*
             * El rótulo lleva el nombre del turno cuando lo tiene:
             * «Cerrar turno · Turno A». Con la variable local, además,
             * `turno()` se consulta una sola vez.
             */
            ->label(function (): string {
                $nombre = $this->turno()?->nombre;

                return 'Cerrar turno'.($nombre === null || trim($nombre) === '' ? '' : ' · '.$nombre);
            })
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->modalHeading('Cerrar el turno y cuadrar la gaveta')
            ->modalDescription(
                'Contá el efectivo y escribí cuánto hay. Si no cuadra con lo esperado, el sistema pide que '
                .'expliqués por qué — hoy, no dentro de tres días.'
            )
            ->modalSubmitActionLabel('Contar y cerrar')
            ->visible(function (): bool {
                $turno = $this->turno();

                return $turno instanceof TurnoDeCaja && Gate::allows('update', $turno);
            })
            ->schema([
                TextInput::make('efectivo_contado')
                    ->label('Efectivo contado en la gaveta')
                    ->required()
                    ->prefix('L')
                    ->inputMode('decimal')
                    ->helperText(fn (): string => 'Según el sistema tendría que haber L '
                        .number_format((float) ($this->turno()?->efectivoEsperado()->redondeado(2) ?? '0'), 2)
                        .'. Contá primero y después mirá este número.'),

                Textarea::make('notas_cierre')
                    ->label('¿Pasó algo con el efectivo?')
                    ->rows(2)
                    ->maxLength(300)
                    ->helperText('Obligatorio si sobra o falta. Diez caracteres mínimo.'),
            ])
            ->action(function (array $data): void {
                $turno = $this->turno();

                if (! $turno instanceof TurnoDeCaja) {
                    return;
                }

                $contado = NumeroDeFormulario::aDecimal($data['efectivo_contado'] ?? null);

                if (! $contado instanceof Decimal) {
                    Notification::make()->warning()->title('Escribí cuánto efectivo contaste')->send();

                    return;
                }

                try {
                    $cerrado = app(AbridorDeTurnoDeCaja::class)->cerrar(
                        turno: $turno,
                        efectivoContado: $contado,
                        notas: is_string($data['notas_cierre'] ?? null) ? $data['notas_cierre'] : null,
                        cerradoPor: UsuarioAutenticado::id(),
                    );
                } catch (SihlaException $e) {
                    Notification::make()->danger()->title('No se pudo cerrar')->body($e->getMessage())->persistent()->send();

                    return;
                }

                $diferencia = Decimal::de($cerrado->diferencia ?? '0');

                Notification::make()
                    ->success()
                    ->title($diferencia->esCero() ? 'Turno cerrado y cuadrado' : 'Turno cerrado con diferencia')
                    ->body($diferencia->esCero()
                        ? "{$cerrado->numero}: la gaveta cuadró exacto."
                        : "{$cerrado->numero}: diferencia de L {$diferencia->redondeado(2)}. Quedó escrita con tu explicación.")
                    ->send();
            });
    }

    private function usuarioDeCaja(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }

    private function sedeDeCaja(): ?Sede
    {
        $id = ContextoSede::actualId();

        return $id === null ? null : Sede::query()->find($id);
    }
}
