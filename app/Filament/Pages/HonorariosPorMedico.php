<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\EstadoFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\Concerns\ElijePeriodo;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Especialidad;
use App\Models\Medico;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Cuánto facturó cada médico en el período, en qué y a quién.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CUENTA LO FACTURADO EN EL MES, Y NADA MÁS (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «solo debería guardarse lo que sí se facturó en ese mes, no
 * mezclarse mes a mes. Si hizo 10 honorarios de 1000 se guardan esos 10;
 * si se anula una factura en la que estaba un honorario, se le restan
 * 1000».
 *
 * Antes la pantalla sumaba CARGOS por su fecha. Anular un cargo deja su
 * reversa en negativo con la fecha del día en que se anuló, así que un
 * honorario de septiembre quitado en octubre salía como −L 630 en
 * octubre: mezclaba un mes con otro, y no se entendía.
 *
 * Ahora la fuente son las FACTURAS EMITIDAS del período —por su fecha
 * de operación— y, de cada una, sus renglones de honorario con médico:
 *
 *   · Un honorario cargado pero sin facturar todavía no cuenta.
 *   · Cuenta en el mes en que se facturó, no en el que se cargó.
 *   · Si la factura se anula, sale entera del mes al que pertenecía —esos
 *     L 1,000 se restan de ese mes— y nunca aparece un negativo en otro.
 *   · Si se vuelve a facturar, cuenta en el mes de la factura nueva.
 *
 * Un cargo anulado o su reversa nunca entran a una factura (el emisor
 * solo toma lo pendiente y cobrable), así que acá no hay negativos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES LO QUE SE FACTURÓ, NO LO QUE SE LE PAGA AL MÉDICO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si algún día el hospital retiene una parte —una comisión por uso de
 * quirófano, un porcentaje—, ese reparto no está acá y hay que
 * agregarlo: sería una columna más, calculada sobre este total.
 *
 * Los honorarios cargados antes de que existiera el registro de médicos
 * tienen `medico_id` nulo y no salen: el dato no existe y no se inventa.
 */
class HonorariosPorMedico extends Page implements HasTable
{
    use ElijePeriodo;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'honorarios-por-medico';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.honorarios-por-medico';

    /**
     * `#[Locked]`: solo lo cambian `verSoloEste()` y `verTodos()`.
     */
    #[Locked]
    public ?int $medico = null;

    /**
     * Las cifras por médico, una sola consulta por pintada. Privadas:
     * Livewire no las guarda entre pedidos y nunca quedan viejas.
     *
     * @var array<int, array{cuantos: int, importe: Decimal}>|null
     */
    private ?array $totales = null;

    private ?string $totalesDe = null;

    public static function getNavigationLabel(): string
    {
        return 'Honorarios por médico';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    public function getTitle(): string
    {
        return 'Honorarios por médico';
    }

    public function getSubheading(): string
    {
        return 'Los honorarios de las facturas emitidas en el período. Si una factura se anula, '
            .'sus honorarios salen del mes.';
    }

    /**
     * ⚠️ Hacen falta LOS DOS permisos.
     *
     * Ver médicos lo tiene también el rol `medico`, y esta pantalla dice
     * cuánto facturó cada uno de sus colegas. Pedir además el permiso de
     * cargos la deja donde corresponde: dirección, auditoría y caja.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Medico::class)
            && Gate::allows('viewAny', Cargo::class);
    }

    /**
     * `?medico=` se lee a mano y no con `#[Url]`, por la misma razón que
     * el período (ver `leerElPeriodoDeLaUrl()`).
     */
    public function mount(): void
    {
        $this->leerElPeriodoDeLaUrl();

        $medico = request()->query('medico');
        $this->medico = is_string($medico) && ctype_digit($medico) ? (int) $medico : null;
    }

    // ── El período ────────────────────────────────────────────────────

    /**
     * Los honorarios se liquidan por quincena o por mes.
     *
     * @return array<string, string>
     */
    protected function atajosDelPeriodo(): array
    {
        return [
            'esta_quincena'   => 'Esta quincena',
            'quincena_pasada' => 'Quincena pasada',
            'este_mes'        => 'Este mes',
            'mes_pasado'      => 'Mes pasado',
        ];
    }

    // ── El médico ─────────────────────────────────────────────────────

    public function verSoloEste(int $medicoId): void
    {
        $this->medico = $this->medico === $medicoId ? null : $medicoId;

        $this->resetPage();
    }

    public function verTodos(): void
    {
        $this->medico = null;

        $this->resetPage();
    }

    // ── El total y los médicos ────────────────────────────────────────

    /**
     * El total del período, de todos los médicos.
     *
     * @return array{total: string, renglones: int, medicos: int}
     */
    public function resumen(): array
    {
        $total = Decimal::cero();
        $renglones = 0;

        foreach ($this->totalesPorMedico() as $suyo) {
            $total = $total->sumar($suyo['importe']);
            $renglones += $suyo['cuantos'];
        }

        return [
            'total'     => self::monto($total),
            'renglones' => $renglones,
            'medicos'   => count($this->totalesPorMedico()),
        ];
    }

    public function totalDelPeriodo(): string
    {
        return $this->resumen()['total'];
    }

    /**
     * Una fila por médico con honorarios facturados en el período.
     *
     * Cada fila es además un filtro: tocarla deja el detalle en ese
     * médico, y volver a tocarla lo suelta.
     *
     * @return array<int, array{id: int, nombre: string, especialidad: string, renglones: int, total: string, activo: bool}>
     */
    public function medicos(): array
    {
        $totales = $this->totalesPorMedico();

        if ($totales === []) {
            return [];
        }

        /*
         * ⚠️ `array_values()` aunque la colección ya venga en orden:
         * `Collection::all()` devuelve `array<int, …>`, que no es una
         * `list` para el analizador.
         */
        return array_values(Medico::query()
            ->with('especialidad')
            ->whereIn('id', array_keys($totales))
            ->orderBy('nombre')
            ->get()
            ->map(function (Medico $medico) use ($totales): array {
                $id = (int) $medico->getKey();
                $suyo = $totales[$id] ?? ['cuantos' => 0, 'importe' => Decimal::cero()];

                return [
                    'id'           => $id,
                    'nombre'       => $medico->nombre,
                    'especialidad' => $medico->especialidad instanceof Especialidad
                        ? $medico->especialidad->nombre
                        : '',
                    'renglones' => $suyo['cuantos'],
                    'total'     => self::monto($suyo['importe']),
                    'activo'    => $this->medicoElegido() === $id,
                ];
            })
            ->all());
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consultaDeLaTabla())
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('facturas.fecha_operacion')
                ->orderByDesc('cargos.id'))
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Paciente u honorario')
            ->columns([
                /*
                 * La fecha de la FACTURA, que es la que decide el mes, y
                 * debajo su número para buscarla en caja.
                 */
                TextColumn::make('facturada_el')
                    ->label('Facturado')
                    ->state(fn (Cargo $record): string => self::fechaCorta($record->getAttribute('facturada_el')))
                    ->description(fn (Cargo $record): string => (string) $record->getAttribute('factura_numero'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('facturas.fecha_operacion', $direction === 'desc' ? 'desc' : 'asc'))
                    ->grow(false),

                /*
                 * Solo cuando se ven todos: con un médico elegido, la
                 * columna repetiría su nombre en cada renglón.
                 */
                TextColumn::make('medico.nombre')
                    ->label('Médico')
                    ->wrap()
                    ->visible(fn (): bool => $this->medicoElegido() === null),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    ->state(fn (Cargo $record): string => $record->cuenta->encuentro->persona->nombreCompleto())
                    ->description(fn (Cargo $record): string => $record->cuenta->numero)
                    /*
                     * Se busca contra `nombre_busqueda`, la columna
                     * generada de `personas`: el nombre ya normalizado
                     * —sin tildes, en una sola pieza—, la única forma de
                     * que «peña» encuentre a PENA.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'cuenta.encuentro.persona',
                        fn (Builder $persona): Builder => $persona->where('nombre_busqueda', 'ilike', '%'.$search.'%'),
                    ))
                    ->wrap(),

                /*
                 * El TEXTO del cargo y no el nombre del ítem: es el que
                 * quedó congelado el día del cobro y el que salió impreso
                 * en la factura.
                 */
                TextColumn::make('texto')
                    ->label('Honorario')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('cargos.texto', 'ilike', '%'.$search.'%')),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->wrap(),

                TextColumn::make('cantidad')
                    ->label('Cant.')
                    ->alignEnd()
                    ->formatStateUsing(fn (Cargo $record): string => rtrim(rtrim($record->cantidad, '0'), '.'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Importe')
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->weight('semibold')
                    ->formatStateUsing(fn (Cargo $record): string => $record->totalParaMostrar())
                    ->grow(false)
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn (mixed $state): string => self::monto($state))
                    ),
            ])
            /*
             * Un solo filtro, en el botón de al lado del buscador:
             * período y médico ya están arriba.
             *
             * ⚠️ Con su propia consulta y la columna calificada: la tabla
             * cruza `cargos` con `facturas`, y las dos tienen
             * `convenio_id`.
             */
            ->filters([
                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->native(false)
                    ->options(fn (): array => Convenio::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): void {
                        $valor = $data['value'] ?? null;

                        if (is_numeric($valor)) {
                            $query->where('cargos.convenio_id', (int) $valor);
                        }
                    }),
            ])
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Pagador'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('Sin honorarios facturados en el período')
            ->emptyStateDescription(
                'Solo cuentan los honorarios de facturas emitidas en estas fechas. Un honorario cobrado '
                .'sin elegir médico no aparece: no tiene a quién atribuirse.'
            )
            ->recordActions([])
            ->toolbarActions([]);
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * Los renglones de honorario con médico de las facturas EMITIDAS en
     * el período. Es la única fuente de la pantalla: el total, los
     * médicos y el detalle salen de acá.
     *
     * ⚠️ El cruce con la factura va también por `cuenta_id`: `cargos`
     * está particionada y `factura_lineas.cargo_id` no tiene FK, así que
     * un id repetido de otra partición podría colarse (la misma guarda
     * que usa el emisor al anular).
     *
     * ⚠️ `encabezado = false`: el renglón que nombra una cirugía
     * desglosada no cobra nada y apunta a un cargo sin médico, pero se
     * excluye explícito.
     *
     * @return Builder<Cargo>
     */
    private function honorariosFacturados(): Builder
    {
        return Cargo::query()
            ->join('factura_lineas', 'factura_lineas.cargo_id', '=', 'cargos.id')
            ->join('facturas', function (JoinClause $union): void {
                $union->on('facturas.id', '=', 'factura_lineas.factura_id')
                    ->on('facturas.cuenta_id', '=', 'cargos.cuenta_id');
            })
            ->where('facturas.estado', EstadoFactura::Emitida->value)
            ->where('factura_lineas.encabezado', false)
            ->whereNotNull('cargos.medico_id')
            ->whereBetween('facturas.fecha_operacion', [$this->desdeEfectivo(), $this->hastaEfectivo()]);
    }

    /**
     * Lo que lee la tabla: el cargo con la fecha y el número de su
     * factura.
     *
     * ⚠️ El `select` es explícito: sin él el cruce trae `id`, `total` y
     * `estado` repetidos de tres tablas, y el resumen del pie —que mete
     * esta consulta en una subconsulta— reventaría por columnas
     * duplicadas.
     *
     * ⚠️ Sin enumerar columnas en el `with`: una columna que falte no da
     * error, devuelve NULL y la pantalla miente en silencio.
     *
     * @return Builder<Cargo>
     */
    private function consultaDeLaTabla(): Builder
    {
        $consulta = $this->honorariosFacturados()
            ->select([
                'cargos.*',
                'facturas.numero as factura_numero',
                'facturas.fecha_operacion as facturada_el',
            ])
            ->with(['medico', 'convenio', 'cuenta.encuentro.persona']);

        $elegido = $this->medicoElegido();

        if ($elegido !== null) {
            $consulta->where('cargos.medico_id', $elegido);
        }

        return $consulta;
    }

    /**
     * Por médico: cuántos honorarios facturados y cuánto suman.
     *
     * @return array<int, array{cuantos: int, importe: Decimal}>
     */
    private function totalesPorMedico(): array
    {
        $clave = $this->desdeEfectivo().'|'.$this->hastaEfectivo();

        if ($this->totales !== null && $this->totalesDe === $clave) {
            return $this->totales;
        }

        $filas = $this->honorariosFacturados()
            ->selectRaw('cargos.medico_id, count(*) as cuantos, sum(cargos.total) as importe')
            ->groupBy('cargos.medico_id')
            ->toBase()
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $totales[(int) $fila->medico_id] = [
                'cuantos' => (int) $fila->cuantos,
                'importe' => self::comoDecimal($fila->importe),
            ];
        }

        $this->totalesDe = $clave;

        return $this->totales = $totales;
    }

    /**
     * El médico elegido, si tiene honorarios en el período. Si no tiene
     * —se eligió y después se cambió de mes—, se ven todos: si no, el
     * detalle quedaría vacío sin nada arriba que explique por qué.
     */
    private function medicoElegido(): ?int
    {
        return $this->medico !== null && array_key_exists($this->medico, $this->totalesPorMedico())
            ? $this->medico
            : null;
    }

    // ── Fechas ────────────────────────────────────────────────────────

    private static function fechaCorta(mixed $fecha): string
    {
        $limpia = is_string($fecha) ? self::fechaValida(mb_substr($fecha, 0, 10)) : null;

        return $limpia === null ? '' : CarbonImmutable::parse($limpia)->format('d/m/Y');
    }

    // ── Montos ────────────────────────────────────────────────────────

    /**
     * Una suma lista para mostrar.
     *
     * Ya no puede dar negativa —ni un cargo anulado ni su reversa entran
     * a una factura—, pero si algún día diera, sale con signo en vez de
     * reventar: `Monto` no admite negativos.
     */
    private static function monto(mixed $suma): string
    {
        $valor = self::comoDecimal($suma);

        if ($valor->esNegativo()) {
            return '− '.Monto::de(Decimal::cero()->restar($valor)->redondeado(2))->formateado();
        }

        return Monto::de($valor->redondeado(2))->formateado();
    }

    /**
     * `sum()` devuelve lo que le da el driver —int, float o string—.
     */
    private static function comoDecimal(mixed $suma): Decimal
    {
        if ($suma instanceof Decimal) {
            return $suma;
        }

        if (is_string($suma) && is_numeric($suma)) {
            return Decimal::de($suma);
        }

        if (is_int($suma)) {
            return Decimal::de($suma);
        }

        if (is_float($suma)) {
            return Decimal::deFloat($suma);
        }

        return Decimal::cero();
    }
}
