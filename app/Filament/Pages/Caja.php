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

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.caja';

    /**
     * El recibo que se está por anular, y por qué.
     */
    public ?int $abonoAAnular = null;

    public string $motivoDeAnular = '';

    /**
     * Los anulados se esconden por defecto: son historia, no plata en la
     * gaveta, y con cinco tachados entre dos vigentes la lista no se lee.
     * Siguen a un clic, con su motivo.
     */
    public bool $verAnulados = false;

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
        return 'Lo que entró en el turno y cuánto efectivo tiene que haber en la gaveta.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', TurnoDeCaja::class);
    }

    // ── Lo que la vista pregunta ──────────────────────────────────────

    /**
     * Todo lo que la pantalla pinta, resuelto una sola vez por pintada.
     *
     * Antes la vista le preguntaba el turno a tres métodos distintos y
     * cada uno lo volvía a buscar; las sumas por forma de pago también
     * salían dos veces (una para los cuadros, otra para «debería haber»).
     * Ahora es una consulta para el turno, una para las sumas y la de los
     * recibos con lo suyo.
     *
     * La cuenta de la gaveta se arma con las formas que se cuentan en el
     * arqueo (hoy, solo efectivo): si el hospital agrega una forma nueva
     * que vaya a la gaveta, aparece sola en la suma.
     *
     * @return array{
     *     turno: TurnoDeCaja,
     *     fondo: Decimal,
     *     enLaGaveta: list<array{etiqueta: string, monto: Decimal}>,
     *     gaveta: Decimal,
     *     fueraDeLaGaveta: list<array{etiqueta: string, monto: Decimal, nota: string}>,
     *     cobrado: Decimal,
     *     vigentes: list<Abono>,
     *     anulados: list<Abono>,
     *     abiertoHaceMucho: bool,
     * }|null
     */
    public function resumen(): ?array
    {
        $turno = $this->turno();

        if (! $turno instanceof TurnoDeCaja) {
            return null;
        }

        $porForma = $turno->porFormaDePago();
        $fondo = Decimal::de($turno->fondo_inicial);

        $gaveta = $fondo;
        $cobrado = Decimal::cero();
        $enLaGaveta = [];
        $fueraDeLaGaveta = [];

        foreach (FormaDePago::cases() as $forma) {
            $monto = $porForma[$forma->value] ?? Decimal::cero();
            $cobrado = $cobrado->sumar($monto);

            if ($forma->seCuentaEnElArqueo()) {
                $gaveta = $gaveta->sumar($monto);
                $enLaGaveta[] = ['etiqueta' => $forma->etiqueta().' recibido', 'monto' => $monto];

                continue;
            }

            $fueraDeLaGaveta[] = ['etiqueta' => $forma->etiqueta(), 'monto' => $monto, 'nota' => $forma->dondeSeCuadra()];
        }

        $vigentes = [];
        $anulados = [];

        foreach ($this->abonosDe($turno) as $abono) {
            if ($abono->estado->bajaElSaldo()) {
                $vigentes[] = $abono;
            } else {
                $anulados[] = $abono;
            }
        }

        $horas = (int) config('sihla.caja.horas_para_avisar_turno_largo', 14);

        return [
            'turno'            => $turno,
            'fondo'            => $fondo,
            'enLaGaveta'       => $enLaGaveta,
            'gaveta'           => $gaveta,
            'fueraDeLaGaveta'  => $fueraDeLaGaveta,
            'cobrado'          => $cobrado,
            'vigentes'         => $vigentes,
            'anulados'         => $anulados,
            'abiertoHaceMucho' => $horas > 0 && $turno->abierto_en->lessThan(now()->subHours($horas)),
        ];
    }

    public function mostrarAnulados(bool $ver = true): void
    {
        $this->verAnulados = $ver;
    }

    /**
     * Los recibos del turno, el último primero, con el nombre del
     * paciente: «CTA-HLA-2026-000006» no le dice nada a la cajera; el
     * nombre de quien pagó, sí.
     *
     * @return ColeccionDeModelos<int, Abono>
     */
    private function abonosDe(TurnoDeCaja $turno): ColeccionDeModelos
    {
        /** @var ColeccionDeModelos<int, Abono> $abonos */
        $abonos = $turno->abonos()
            ->with([
                'medios',
                'cuenta:id,numero,encuentro_id',
                'cuenta.encuentro:id,persona_id',
                'cuenta.encuentro.persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido',
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $abonos;
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
