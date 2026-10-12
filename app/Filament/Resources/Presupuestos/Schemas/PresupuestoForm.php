<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Schemas;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\PlantillaPresupuesto;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Formulario de creación del presupuesto.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE
 * (`$get`, `$set`, `$state`, `$record`). Con otro nombre reciben un
 * objeto vacío del contenedor y fallan EN SILENCIO.
 *
 * Lo que se llena acá es el ENCABEZADO. Los renglones los arma
 * `CotizadorDePresupuesto` al guardar, y después se editan uno por uno
 * en el panel de abajo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL RESUMEN ARRIBA, LOS DATOS PLEGADOS, LOS RENGLONES A LA VISTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio, 12-oct-2026: «que se vea más profesional y fácil de usar»,
 * y después: «se desperdicia mucho espacio». Lo que se hace en esta
 * pantalla es trabajar los renglones; los datos del encabezado se llenan
 * una vez al crear. Así que:
 *
 *   · arriba, una franja con lo que importa mientras se cotiza —estado,
 *     total, tope de la plantilla y, ya en la cuenta, lo consumido—;
 *   · los datos en una sección PLEGADA al editar, que en su título dice
 *     pagador, ingreso y plantilla sin abrirla; abierta, en tres columnas
 *     y con la explicación de cada campo en el ⓘ;
 *   · y los renglones quedan a la vista sin bajar.
 */
class PresupuestoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Placeholder::make('resumen_del_presupuesto')
                    ->hiddenLabel()
                    ->visible(fn (mixed $record): bool => self::existe($record))
                    ->content(fn (mixed $record): HtmlString => self::resumen($record)),

                Section::make('Datos del presupuesto')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->description(fn (mixed $record): ?string => self::enUnaLinea($record))
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (mixed $record): bool => self::existe($record))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        Select::make('expediente_id')
                            ->label('Paciente')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Expediente::query()
                                ->with('persona')
                                ->where(function (Builder $sub) use ($search): void {
                                    $sub->where('numero', 'ilike', "%{$search}%")
                                        ->orWhereHas(
                                            'persona',
                                            fn (Builder $p): Builder => $p->where('nombre_busqueda', 'ilike', "%{$search}%")
                                        );
                                })
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Expediente $e): array => [
                                    $e->id => $e->numero.' — '.($e->persona?->nombreCompleto() ?? 'SIN NOMBRE'),
                                ])
                                ->all())
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                $expediente = Expediente::query()->with('persona')->find($value);

                                if (! $expediente instanceof Expediente) {
                                    return null;
                                }

                                return $expediente->numero.' — '.($expediente->persona?->nombreCompleto() ?? 'SIN NOMBRE');
                            })
                            /*
                         * 🔴 SI EL PACIENTE YA TIENE CUENTA ABIERTA, EL
                         * INGRESO Y EL PAGADOR SE TOMAN DE AHÍ.
                         *
                         * No es comodidad: es el caso del §1.5. Si se
                         * cotiza bajo CONTADO mientras la cuenta está
                         * abierta con PALIG, los precios del papel salen
                         * de otro tarifario y nadie lo nota hasta que la
                         * familia compara.
                         */
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('encuentro_id', null);

                                if (! is_numeric($state)) {
                                    return;
                                }

                                $cuenta = Cuenta::query()
                                    ->where('estado', EstadoCuenta::Abierta->value)
                                    ->whereHas(
                                        'encuentro',
                                        fn (Builder $e): Builder => $e->where('expediente_id', (int) $state)
                                    )
                                    ->with('encuentro:id,numero')
                                    ->first();

                                if (! $cuenta instanceof Cuenta) {
                                    return;
                                }

                                $set('encuentro_id', $cuenta->encuentro_id);
                                $set('convenio_id', $cuenta->convenio_id);

                                Notification::make()
                                    ->info()
                                    ->title("Tomado de la cuenta {$cuenta->numero}")
                                    ->body('El ingreso y el pagador se llenaron con los de la cuenta abierta. Podés cambiarlos si este presupuesto es para otra cosa.')
                                    ->send();
                            })
                            ->live()
                            ->placeholder('Expediente o nombre')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, 'Tiene que estar registrado: cotizarle a alguien sin identificar es como se pierde el rastro de a quién se le prometió qué.'),

                        Select::make('convenio_id')
                            ->label('Pagador')
                            ->required()
                            ->relationship('convenio', 'nombre')
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => Convenio::query()->where('codigo', 'CONTADO')->value('id'))
                            ->hintIcon(Heroicon::OutlinedInformationCircle, 'Con qué tarifario se cotiza. Si el caso es PALIG, salen los precios de PALIG.'),

                        /*
                     * Nullable a propósito: mucha gente llega solo a
                     * preguntar cuánto le sale y todavía no ha ingresado.
                     * Se amarra después, al abrir la cuenta.
                     */
                        Select::make('encuentro_id')
                            ->label('Ingreso (opcional)')
                            ->searchable()
                            ->options(function (Get $get): array {
                                $expediente = $get('expediente_id');

                                if (! is_numeric($expediente)) {
                                    return [];
                                }

                                return Encuentro::query()
                                    ->vivos()
                                    ->where('expediente_id', (int) $expediente)
                                    ->pluck('numero', 'id')
                                    ->all();
                            })
                            ->placeholder('Todavía no ingresa')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, 'Solo si el paciente ya ingresó. Sin ingreso el presupuesto igual se cotiza — el medidor empieza a correr cuando se amarre.'),

                        Select::make('plantilla_id')
                            ->label('Plantilla')
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => PlantillaPresupuesto::query()
                                ->vigentesEn(now())
                                ->orderBy('nombre')
                                ->get()
                                ->mapWithKeys(fn (PlantillaPresupuesto $p): array => [$p->id => $p->etiqueta()])
                                ->all())
                            ->live()
                            /*
                         * ⚠️ La plantilla PROPONE el título; no lo impone.
                         * Solo lo escribe si el campo está vacío: pisarlo
                         * siempre no dejaba escribir «APENDICECTOMIA
                         * COMPLICADA» ni «CESAREA + SALPINGECTOMIA», que
                         * es justo cuando el título importa.
                         */
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                if (! is_numeric($state)) {
                                    return;
                                }

                                $titulo = $get('titulo');

                                if (is_string($titulo) && trim($titulo) !== '') {
                                    return;
                                }

                                $plantilla = PlantillaPresupuesto::query()->find((int) $state);

                                if ($plantilla instanceof PlantillaPresupuesto) {
                                    $set('titulo', $plantilla->nombre);
                                }
                            })
                            ->placeholder('Sin plantilla, se arma a mano')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, 'Trae los renglones típicos ya cotizados. Después se ajustan uno por uno. Sin plantilla, el presupuesto nace vacío y se arma a mano.'),

                        CampoMayusculas::make('titulo')
                            ->label('Título')
                            ->required()
                            ->maxLength(150)
                            ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2])
                            ->placeholder('Lo que va a decir el papel: APENDICECTOMIA'),

                        CampoMayusculas::area('notas')
                            ->label('Notas')
                            ->maxLength(500)
                            ->rows(1)
                            ->autosize()
                            ->columnSpanFull()
                            ->placeholder('Qué NO incluye, qué se acordó con la familia'),
                    ]),
            ]);
    }

    /**
     * Lo que dice el título de la sección plegada: pagador, ingreso y
     * plantilla, para no tener que abrirla a ver con qué se cotizó.
     */
    private static function enUnaLinea(mixed $record): ?string
    {
        if (! self::existe($record) || ! $record instanceof Presupuesto) {
            return null;
        }

        $partes = [$record->convenio->nombre];

        $encuentro = $record->encuentro;
        $partes[] = $encuentro instanceof Encuentro ? 'Ingreso '.$encuentro->numero : 'Sin ingreso';

        $plantilla = $record->plantilla;
        $partes[] = $plantilla instanceof PlantillaPresupuesto ? 'Plantilla '.$plantilla->codigo : 'Sin plantilla';

        return implode(' · ', $partes);
    }

    /**
     * ⚠️ En CREAR el schema recibe un modelo VACÍO, no nulo (§9.A2): sin
     * `exists` el resumen se dibujaría con ceros antes de que el
     * presupuesto exista.
     */
    private static function existe(mixed $record): bool
    {
        return $record instanceof Presupuesto && $record->exists;
    }

    /**
     * Lo que se mira mientras se cotiza: el estado, cuánto va, contra qué
     * tope de la plantilla y —ya en la cuenta— cuánto se consumió.
     *
     * Se relee del registro en cada pintada: la página escucha a los
     * renglones (`EditPresupuesto::alCambiarLosRenglones`) y refresca el
     * presupuesto cada vez que se agrega, ajusta o quita uno.
     */
    private static function resumen(mixed $record): HtmlString
    {
        if (! self::existe($record) || ! $record instanceof Presupuesto) {
            return new HtmlString('');
        }

        $total = Decimal::de($record->total);
        $plantilla = $record->plantilla instanceof PlantillaPresupuesto ? $record->plantilla : null;
        $tope = $record->topeDeReferencia();

        $tramoDelTope = null;

        if ($tope instanceof Decimal && $tope->mayorQue('0')) {
            $fraccion = $total->entre($tope);

            $tramoDelTope = [
                'plantilla'  => $plantilla instanceof PlantillaPresupuesto ? $plantilla->nombre : 'la plantilla',
                'tope'       => number_format((float) $tope->redondeado(2), 2),
                'porcentaje' => min(100, (int) round((float) $fraccion->por('100')->redondeado(0))),
                'excede'     => $total->mayorQue($tope),
                'exceso'     => number_format((float) $total->restar($tope)->redondeado(2), 2),
            ];
        }

        $consumo = null;

        if ($record->estado === EstadoPresupuesto::Agregado) {
            $consumido = $record->consumido();
            $fraccion = $total->esCero() ? Decimal::cero() : $consumido->entre($total);
            $cuenta = $record->cuentaViva();

            $consumo = [
                'consumido'  => number_format((float) $consumido->redondeado(2), 2),
                'porcentaje' => min(100, (int) round((float) $fraccion->por('100')->redondeado(0))),
                'color'      => self::colorDe($record->semaforo()),
                'cuenta'     => $cuenta instanceof Cuenta ? $cuenta->numero : null,
            ];
        }

        $acuerdo = self::acuerdo($record);

        return new HtmlString(view('filament.presupuestos.resumen', [
            'estado'       => $record->estado->etiqueta(),
            'colorEstado'  => self::colorDe($record->estado->color()),
            'rotulo'       => $acuerdo !== null && ! $acuerdo['pasa'] && $acuerdo['falta'] === null ? 'Se le cobrará' : 'Total',
            'total'        => number_format((float) $total->redondeado(2), 2),
            'renglones'    => $record->lineas,
            'acuerdo'      => $acuerdo,
            'puedeAcordar' => $record->estado->esEditable() && Gate::allows('update', $record),
            'tope'         => $tramoDelTope,
            'consumo'      => $consumo,
            'vence'        => $record->vence_el?->format('d/m/Y'),
        ])->render());
    }

    /**
     * Lo acordado con la familia y cómo se llega: cuánto ponen los
     * renglones, cuánto la holgura que completa, o cuánto se pasan.
     *
     * @return array{monto: string, renglones: string, nombre: string|null, complemento: string|null, pasa: bool, exceso: string, falta: string|null}|null
     */
    private static function acuerdo(Presupuesto $presupuesto): ?array
    {
        if ($presupuesto->monto_acordado === null) {
            return null;
        }

        $acordado = Decimal::de($presupuesto->monto_acordado);
        $total = Decimal::de($presupuesto->total);
        $complemento = $presupuesto->complemento();
        $completa = $complemento instanceof PresupuestoLinea ? Decimal::de($complemento->total) : Decimal::cero();

        return [
            'monto'       => self::lempiras($acordado),
            'renglones'   => self::lempiras($total->restar($completa)),
            'nombre'      => $complemento instanceof PresupuestoLinea ? $complemento->texto : null,
            'complemento' => $complemento instanceof PresupuestoLinea ? self::lempiras($completa) : null,
            'pasa'        => $total->mayorQue($acordado),
            'exceso'      => self::lempiras($total->restar($acordado)),
            'falta'       => $acordado->mayorQue($total) ? self::lempiras($acordado->restar($total)) : null,
        ];
    }

    private static function lempiras(Decimal $monto): string
    {
        return number_format((float) $monto->redondeado(2), 2);
    }

    /**
     * Los colores de Filament en hexadecimal: el resumen trae su propio
     * CSS, porque el del panel viene precompilado (§9.A7).
     */
    private static function colorDe(string $color): string
    {
        return match ($color) {
            'success' => '#16a34a',
            'warning' => '#d97706',
            'danger'  => '#dc2626',
            'info'    => '#0284c7',
            default   => '#71717a',
        };
    }
}
