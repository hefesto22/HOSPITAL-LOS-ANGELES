<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Abono;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Services\ReceptorDeAbono;
use App\Support\ContextoSede;
use App\Support\NumeroDeFormulario;
use App\Support\UsuarioAutenticado;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * La gaveta: abrir el turno, ver lo que entró, cerrarlo contando.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES LA PANTALLA DE LA CAJERA, NO LA DE DIRECCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Muestra UN turno —el suyo— y lo que lleva recibido. Los turnos de
 * todos, con sus diferencias, se ven en «Turnos de caja», que es otra
 * pregunta y otra persona.
 *
 * Los abonos NO se reciben acá: se reciben en la cuenta del paciente,
 * que es donde está el saldo. Acá se ven los del turno para poder
 * anular el que salió mal antes de cerrar.
 *
 * ⚠️ El motivo de anulación vive en el estado de Livewire y no en un
 * `<details>` ni en un modal con argumentos: la lección del desglose del
 * paquete —si Livewire re-renderiza el bloque, el estado del bloque vive
 * en Livewire—.
 */
class Caja extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'caja';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.caja';

    /**
     * El recibo que se está por anular, y por qué.
     */
    public ?int $abonoAAnular = null;

    public string $motivoDeAnular = '';

    public static function getNavigationLabel(): string
    {
        return 'Caja';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    public function getTitle(): string
    {
        return 'Caja';
    }

    public function getSubheading(): string
    {
        return 'Tu turno: con cuánto abriste, qué entró y cuánto tiene que haber en la gaveta al cerrar.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', TurnoDeCaja::class);
    }

    // ── Lo que la vista pregunta ──────────────────────────────────────

    /**
     * El turno abierto de quien está mirando. `null` = todavía no abrió.
     */
    public function turno(): ?TurnoDeCaja
    {
        $usuario = $this->usuario();

        if (! $usuario instanceof User) {
            return null;
        }

        return app(AbridorDeTurnoDeCaja::class)->abiertoDe($usuario);
    }

    /**
     * Los recibos del turno, el último primero.
     *
     * @return ColeccionDeModelos<int, Abono>
     */
    public function abonosDelTurno(): ColeccionDeModelos
    {
        $turno = $this->turno();

        if (! $turno instanceof TurnoDeCaja) {
            /** @var ColeccionDeModelos<int, Abono> $vacia */
            $vacia = new ColeccionDeModelos;

            return $vacia;
        }

        /** @var ColeccionDeModelos<int, Abono> $abonos */
        $abonos = $turno->abonos()
            ->with(['medios', 'cuenta:id,numero'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $abonos;
    }

    /**
     * Cuánto entró por cada forma, para el pie del turno.
     *
     * @return array<string, Decimal>
     */
    public function porFormaDePago(): array
    {
        $turno = $this->turno();

        if (! $turno instanceof TurnoDeCaja) {
            $vacio = [];

            foreach (FormaDePago::cases() as $forma) {
                $vacio[$forma->value] = Decimal::cero();
            }

            return $vacio;
        }

        return $turno->porFormaDePago();
    }

    // ── Abrir y cerrar ────────────────────────────────────────────────

    public function abrirTurnoAction(): Action
    {
        return Action::make('abrirTurno')
            ->label('Abrir mi turno')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('primary')
            ->modalHeading('Abrir el turno de caja')
            ->modalDescription(
                'Declará con cuánto efectivo arrancás. Ese fondo es la base del arqueo: al cerrar, '
                .'lo contado se compara contra el fondo más lo que recibiste en billetes.'
            )
            ->modalSubmitActionLabel('Abrir el turno')
            ->visible(fn (): bool => ! $this->turno() instanceof TurnoDeCaja
                && Gate::allows('create', TurnoDeCaja::class))
            ->schema([
                TextInput::make('nombre')
                    ->label('¿Cómo se llama este turno?')
                    ->placeholder('Turno A')
                    ->maxLength(40)
                    ->helperText('Para reconocerlo después en el reporte. Podés dejarlo vacío.'),

                TextInput::make('fondo_inicial')
                    ->label('Fondo con el que abrís')
                    ->required()
                    ->default('0')
                    ->prefix('L')
                    ->inputMode('decimal')
                    ->helperText('El efectivo que ya está en la gaveta antes de cobrarle a nadie.'),
            ])
            ->action(function (array $data): void {
                $usuario = $this->usuario();
                $sede = $this->sede();

                if (! $usuario instanceof User || ! $sede instanceof Sede) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo abrir el turno')
                        ->body('No hay una sede activa para tu usuario. Avisale a dirección.')
                        ->persistent()
                        ->send();

                    return;
                }

                $fondo = NumeroDeFormulario::aDecimalO($data['fondo_inicial'] ?? null, Decimal::cero());

                try {
                    $turno = app(AbridorDeTurnoDeCaja::class)->abrir(
                        usuario: $usuario,
                        sede: $sede,
                        fondoInicial: $fondo,
                        nombre: is_string($data['nombre'] ?? null) ? $data['nombre'] : null,
                    );
                } catch (SihlaException $e) {
                    Notification::make()->danger()->title('No se pudo abrir el turno')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Turno abierto')
                    ->body("{$turno->etiqueta()}. Ya podés recibir abonos desde la cuenta del paciente.")
                    ->send();
            });
    }

    public function cerrarTurnoAction(): Action
    {
        return Action::make('cerrarTurno')
            ->label('Cerrar mi turno')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->modalHeading('Cerrar el turno y cuadrar la gaveta')
            ->modalDescription(
                'Contá el efectivo y escribí cuánto hay. Si no cuadra con lo esperado, el sistema pide '
                .'que expliqués por qué — hoy, no dentro de tres días.'
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

    // ── Anular un recibo ──────────────────────────────────────────────

    public function pedirElMotivo(int $abono): void
    {
        $this->abonoAAnular = $abono;
        $this->motivoDeAnular = '';
    }

    public function cancelarAnulacion(): void
    {
        $this->abonoAAnular = null;
        $this->motivoDeAnular = '';
    }

    public function anularElAbono(): void
    {
        if ($this->abonoAAnular === null) {
            return;
        }

        $abono = Abono::query()->with('turno')->find($this->abonoAAnular);

        if (! $abono instanceof Abono) {
            $this->cancelarAnulacion();

            return;
        }

        abort_unless(Gate::allows('update', $abono), 403);

        try {
            app(ReceptorDeAbono::class)->anular($abono, $this->motivoDeAnular, UsuarioAutenticado::id());
        } catch (SihlaException $e) {
            Notification::make()->danger()->title('No se pudo anular')->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->cancelarAnulacion();

        Notification::make()
            ->success()
            ->title('Recibo anulado')
            ->body("{$abono->numero} quedó anulado y ya no cuenta en el saldo de la cuenta ni en tu arqueo.")
            ->send();
    }

    // ── Utilidades ────────────────────────────────────────────────────

    private function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }

    private function sede(): ?Sede
    {
        $id = ContextoSede::actualId();

        return $id === null ? null : Sede::query()->find($id);
    }
}
