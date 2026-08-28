<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Concerns\OperaElTurnoDeCaja;
use App\Models\Abono;
use App\Models\TurnoDeCaja;
use App\Services\ReceptorDeAbono;
use App\Support\UsuarioAutenticado;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
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
    use OperaElTurnoDeCaja;

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
}
