<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Support\ContextoSede;
use App\Support\NombresDeTurno;
use App\Support\NumeroDeFormulario;
use App\Support\TextoCanonico;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
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
 * ─────────────────────────────────────────────────────────────────────
 * EL BOTÓN DICE EN QUÉ ESTÁ LA GAVETA (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * El turno es de la gaveta, no de quien lo abrió (ver
 * `AbridorDeTurnoDeCaja`). El mismo lugar del encabezado muestra una de
 * tres cosas:
 *
 *   · ABRIR TURNO — la gaveta está cerrada. El turno que sigue ya viene
 *     marcado.
 *   · CERRAR TURNO · TURNO A — está abierta y quien mira puede trabajar
 *     en ella (la abrió, es de ese turno o es de la administración).
 *   · GAVETA EN TURNO A — está abierta en un turno que no es de quien
 *     mira. No cobra ni cierra; el botón explica quién lo hace.
 */
trait OperaElTurnoDeCaja
{
    /**
     * Lo que ya se preguntó en este request. Privado a propósito:
     * Livewire no lo guarda entre requests, así que cada pintada empieza
     * de cero; y después de abrir o cerrar se olvida a mano, porque el
     * encabezado se vuelve a pintar en el mismo request.
     *
     * @var array{turno?: TurnoDeCaja|null, gaveta?: TurnoDeCaja|null, sede?: Sede|null}
     */
    private array $memoDeCaja = [];

    /**
     * El turno abierto en el que trabaja quien está mirando. `null` = no
     * hay uno que pueda usar (la gaveta está cerrada, o está en el turno
     * de otra persona).
     */
    public function turno(): ?TurnoDeCaja
    {
        if (array_key_exists('turno', $this->memoDeCaja)) {
            return $this->memoDeCaja['turno'];
        }

        $usuario = $this->usuarioDeCaja();

        return $this->memoDeCaja['turno'] = $usuario instanceof User
            ? app(AbridorDeTurnoDeCaja::class)->elQueOpera($usuario, $this->sedeDeCaja())
            : null;
    }

    /**
     * El turno abierto de la gaveta de la sede, sea de quien sea.
     */
    public function turnoDeLaGaveta(): ?TurnoDeCaja
    {
        if (array_key_exists('gaveta', $this->memoDeCaja)) {
            return $this->memoDeCaja['gaveta'];
        }

        $sede = $this->sedeDeCaja();

        return $this->memoDeCaja['gaveta'] = $sede instanceof Sede
            ? app(AbridorDeTurnoDeCaja::class)->abiertoEn($sede)
            : null;
    }

    /**
     * Abierta en un turno en el que quien mira no trabaja.
     */
    public function gavetaDeOtroTurno(): ?TurnoDeCaja
    {
        $gaveta = $this->turnoDeLaGaveta();

        return $gaveta instanceof TurnoDeCaja && ! $this->turno() instanceof TurnoDeCaja ? $gaveta : null;
    }

    public function abrirTurnoAction(): Action
    {
        return Action::make('abrirTurno')
            ->label('Abrir turno')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->modalIcon(Heroicon::OutlinedLockOpen)
            ->modalIconColor('primary')
            ->modalHeading('Abrir la caja')
            ->modalDescription('Elegí el turno y escribí con cuánto efectivo arranca la gaveta.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Abrir el turno')
            ->visible(fn (): bool => ! $this->turnoDeLaGaveta() instanceof TurnoDeCaja
                && ! $this->turno() instanceof TurnoDeCaja
                && Gate::allows('create', TurnoDeCaja::class))
            ->schema([
                /*
                 * El turno que sigue ya viene marcado: al cerrar el A,
                 * la caja propone el B. Se puede elegir otro si hoy se
                 * salta uno.
                 */
                ViewField::make('nombre')
                    ->hiddenLabel()
                    ->view('filament.forms.abrir-turno')
                    ->default(fn (): ?string => $this->turnoQueSigue())
                    ->viewData(fn (): array => $this->datosParaAbrir()),

                TextInput::make('fondo_inicial')
                    ->label('Fondo con el que arranca la gaveta')
                    ->required()
                    ->default('0')
                    ->prefix('L')
                    ->inputMode('decimal')
                    ->extraInputAttributes(['class' => 'tc-monto-grande'])
                    ->helperText('El efectivo que ya está en la gaveta antes de cobrarle a nadie. Es la base del arqueo.'),
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

                /*
                 * Lo que se preguntó antes de abrir ya no vale: el
                 * encabezado se vuelve a pintar en este mismo request.
                 */
                $this->olvidarElTurno();

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
                    ->title("{$turno->etiquetaCorta()} abierto")
                    ->body("{$turno->numero}. Ya se puede cobrar.")
                    ->send();
            });
    }

    public function cerrarTurnoAction(): Action
    {
        return Action::make('cerrarTurno')
            /*
             * El rótulo lleva el nombre del turno cuando lo tiene:
             * «Cerrar turno · TURNO A».
             */
            ->label(function (): string {
                $nombre = $this->turno()?->nombre;

                return 'Cerrar turno'.($nombre === null || trim($nombre) === '' ? '' : ' · '.$nombre);
            })
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->modalIcon(Heroicon::OutlinedBanknotes)
            ->modalIconColor('warning')
            ->modalHeading(fn (): string => 'Cerrar el '.($this->turno()?->etiquetaCorta() ?? 'turno'))
            ->modalDescription('Contá los billetes de la gaveta y escribí el total. El sistema te dice si cuadra.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Contar y cerrar')
            ->visible(function (): bool {
                $turno = $this->turno();

                return $turno instanceof TurnoDeCaja && Gate::allows('update', $turno);
            })
            ->schema([
                ViewField::make('efectivo_contado')
                    ->hiddenLabel()
                    ->required()
                    ->validationMessages(['required' => 'Escribí cuánto efectivo contaste en la gaveta.'])
                    ->view('filament.forms.cerrar-turno')
                    ->viewData(fn (): array => $this->datosParaCerrar()),

                CampoMayusculas::area('notas_cierre')
                    ->label('¿Pasó algo con el efectivo?')
                    ->rows(2)
                    ->maxLength(300)
                    ->helperText('Obligatorio si sobra o falta: diez caracteres mínimo.'),
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

                $this->olvidarElTurno();

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
                $sigue = app(AbridorDeTurnoDeCaja::class)->siguienteEn($cerrado->sede_id);

                Notification::make()
                    ->success()
                    ->title($diferencia->esCero() ? "{$cerrado->etiquetaCorta()} cerrado y cuadrado" : "{$cerrado->etiquetaCorta()} cerrado con diferencia")
                    ->body(($diferencia->esCero()
                        ? 'La gaveta cuadró exacto.'
                        : "Diferencia de L {$diferencia->redondeado(2)}. Quedó escrita con la explicación.")
                        .($sigue === null ? '' : " Lo que sigue es el {$sigue}."))
                    ->send();
            });
    }

    /**
     * La gaveta está abierta en un turno que no es de quien mira. No es
     * un botón que haga algo: dice en qué está la gaveta y quién la
     * cierra, para que la cajera del turno B no se quede preguntando por
     * qué no le aparece «Abrir turno».
     */
    public function gavetaOcupadaAction(): Action
    {
        return Action::make('gavetaOcupada')
            ->label(fn (): string => 'Gaveta en '.($this->gavetaDeOtroTurno()?->etiquetaCorta() ?? 'otro turno'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->modalIcon(Heroicon::OutlinedLockClosed)
            ->modalIconColor('gray')
            ->modalHeading(fn (): string => 'La gaveta está en el '.($this->gavetaDeOtroTurno()?->etiquetaCorta() ?? 'otro turno'))
            ->modalWidth(Width::Large)
            ->modalContent(fn (): View => view('filament.forms.gaveta-ocupada', $this->datosDeLaGavetaOcupada()))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Entendido')
            ->visible(fn (): bool => $this->gavetaDeOtroTurno() instanceof TurnoDeCaja);
    }

    // ── Lo que pintan las ventanas ────────────────────────────────────

    /**
     * @return array{
     *     turnos: list<array{nombre: string, sigue: bool, mio: bool, asignados: list<string>}>,
     *     sigue: string|null,
     *     ultimo: array{nombre: string, cerro: string|null, cuando: string, contado: string|null, resultado: string|null, color: string}|null,
     * }
     */
    private function datosParaAbrir(): array
    {
        $sede = $this->sedeDeCaja();
        $abridor = app(AbridorDeTurnoDeCaja::class);
        $sigue = $this->turnoQueSigue();
        $mio = TextoCanonico::mayusculas($this->usuarioDeCaja()?->turno);
        $asignados = $sede instanceof Sede ? $abridor->asignadosEn($sede) : [];

        $turnos = [];

        foreach (NombresDeTurno::enOrden() as $nombre) {
            $turnos[] = [
                'nombre'    => $nombre,
                'sigue'     => $nombre === $sigue,
                'mio'       => $nombre === $mio,
                'asignados' => $asignados[$nombre] ?? [],
            ];
        }

        $ultimo = $sede instanceof Sede ? $abridor->ultimoEn($sede) : null;

        return [
            'turnos' => $turnos,
            'sigue'  => $sigue,
            'ultimo' => $ultimo instanceof TurnoDeCaja ? self::comoCerro($ultimo) : null,
        ];
    }

    /**
     * @return array{
     *     nombre: string,
     *     numero: string,
     *     abrio: string,
     *     desde: string,
     *     hace: string,
     *     loAbrioOtro: bool,
     *     quienCierra: string,
     *     fondo: string,
     *     efectivo: string,
     *     esperado: string,
     *     fuera: list<array{etiqueta: string, monto: string, nota: string}>,
     *     recibos: int,
     * }|array{}
     */
    private function datosParaCerrar(): array
    {
        $turno = $this->turno();

        if (! $turno instanceof TurnoDeCaja) {
            return [];
        }

        $porForma = $turno->porFormaDePago();
        $efectivo = Decimal::cero();
        $fuera = [];

        foreach (FormaDePago::cases() as $forma) {
            $monto = $porForma[$forma->value] ?? Decimal::cero();

            if ($forma->seCuentaEnElArqueo()) {
                $efectivo = $efectivo->sumar($monto);

                continue;
            }

            $fuera[] = ['etiqueta' => $forma->etiqueta(), 'monto' => $monto->redondeado(2), 'nota' => $forma->dondeSeCuadra()];
        }

        $quien = $this->usuarioDeCaja();

        return [
            'nombre'      => $turno->etiquetaCorta(),
            'numero'      => $turno->numero,
            'abrio'       => $turno->usuario->name ?? 'otra persona',
            'desde'       => self::cuando($turno->abierto_en),
            'hace'        => haceCuanto($turno->abierto_en),
            'loAbrioOtro' => $quien instanceof User && $turno->usuario_id !== $quien->id,
            'quienCierra' => $quien->name ?? 'vos',
            'fondo'       => Decimal::de($turno->fondo_inicial)->redondeado(2),
            'efectivo'    => $efectivo->redondeado(2),
            'esperado'    => $turno->efectivoEsperado()->redondeado(2),
            'fuera'       => $fuera,
            'recibos'     => $turno->abonos()->where('estado', EstadoAbono::Aplicado->value)->count(),
        ];
    }

    /**
     * @return array{nombre: string, abrio: string, desde: string, hace: string, asignados: list<string>, mio: string|null}
     */
    private function datosDeLaGavetaOcupada(): array
    {
        $gaveta = $this->gavetaDeOtroTurno();

        if (! $gaveta instanceof TurnoDeCaja) {
            return ['nombre' => 'otro turno', 'abrio' => 'otra persona', 'desde' => '', 'hace' => '', 'asignados' => [], 'mio' => null];
        }

        $nombre = TextoCanonico::mayusculas($gaveta->nombre);
        $asignados = $nombre === null ? [] : (app(AbridorDeTurnoDeCaja::class)->asignadosEn($gaveta->sede_id)[$nombre] ?? []);

        return [
            'nombre'    => $gaveta->etiquetaCorta(),
            'abrio'     => $gaveta->usuario->name ?? 'otra persona',
            'desde'     => self::cuando($gaveta->abierto_en),
            'hace'      => haceCuanto($gaveta->abierto_en),
            'asignados' => $asignados,
            'mio'       => TextoCanonico::mayusculas($this->usuarioDeCaja()?->turno),
        ];
    }

    /**
     * @return array{nombre: string, cerro: string|null, cuando: string, contado: string|null, resultado: string|null, color: string}
     */
    private static function comoCerro(TurnoDeCaja $turno): array
    {
        $cerradoEn = $turno->cerrado_en;

        if ($turno->estaAbierto() || $cerradoEn === null) {
            return [
                'nombre'    => $turno->etiquetaCorta(),
                'cerro'     => null,
                'cuando'    => 'abierto '.self::cuando($turno->abierto_en),
                'contado'   => null,
                'resultado' => null,
                'color'     => 'gris',
            ];
        }

        $diferencia = Decimal::de($turno->diferencia ?? '0');
        $monto = $diferencia->esNegativo() ? Decimal::cero()->restar($diferencia) : $diferencia;

        return [
            'nombre'    => $turno->etiquetaCorta(),
            'cerro'     => $turno->cerradoPor->name ?? null,
            'cuando'    => self::cuando($cerradoEn),
            'contado'   => self::lempiras($turno->efectivo_contado ?? '0'),
            'resultado' => match (true) {
                $diferencia->esCero()     => 'cuadró',
                $diferencia->esNegativo() => 'faltaron '.self::lempiras($monto->redondeado(2)),
                default                   => 'sobraron '.self::lempiras($monto->redondeado(2)),
            },
            'color' => match (true) {
                $diferencia->esCero()     => 'bien',
                $diferencia->esNegativo() => 'mal',
                default                   => 'ojo',
            },
        ];
    }

    /**
     * «hoy a las 13:17», «ayer a las 22:05» o «el 10/10 a las 07:12».
     */
    private static function cuando(CarbonInterface $momento): string
    {
        $hora = $momento->format('H:i');

        return match (true) {
            $momento->isToday()     => "hoy a las {$hora}",
            $momento->isYesterday() => "ayer a las {$hora}",
            default                 => 'el '.$momento->format('d/m').' a las '.$hora,
        };
    }

    private static function lempiras(string $monto): string
    {
        return 'L '.number_format((float) Decimal::de($monto)->redondeado(2), 2);
    }

    private function turnoQueSigue(): ?string
    {
        $sede = $this->sedeDeCaja();

        return $sede instanceof Sede ? app(AbridorDeTurnoDeCaja::class)->siguienteEn($sede) : null;
    }

    private function olvidarElTurno(): void
    {
        unset($this->memoDeCaja['turno'], $this->memoDeCaja['gaveta']);
    }

    private function usuarioDeCaja(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }

    private function sedeDeCaja(): ?Sede
    {
        if (array_key_exists('sede', $this->memoDeCaja)) {
            return $this->memoDeCaja['sede'];
        }

        $id = ContextoSede::actualId();

        return $this->memoDeCaja['sede'] = $id === null ? null : Sede::query()->find($id);
    }
}
