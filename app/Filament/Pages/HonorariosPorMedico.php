<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Especialidad;
use App\Models\Medico;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Cuánto cobró cada médico, en qué, y a quién se lo cobró.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PREGUNTA DE FIN DE MES
 * ─────────────────────────────────────────────────────────────────────
 *
 * «¿Cuánto fue del doctor Carlos este mes?» no se podía contestar: los
 * honorarios se cobraban con el nombre del médico escrito a mano en un
 * campo de texto, y «Dr. Carlos», «Dr Carlos Pineda» y «CARLOS PINEDA»
 * son tres doctores para un GROUP BY y uno solo para quien firma el
 * cheque.
 *
 * Desde que el cargo guarda el médico, la pregunta es una consulta. Esta
 * pantalla es esa consulta, con el desglose que hace falta para
 * revisarla: qué honorario fue, de qué paciente, y si lo pagó el
 * paciente de su bolsillo o un seguro —y cuál—.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ES LO QUE SE COBRÓ, NO LO QUE SE LE PAGA AL MÉDICO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El total de esta pantalla es lo que el hospital le cobró AL PACIENTE
 * por el honorario de ese doctor. Si algún día el hospital retiene una
 * parte —una comisión por uso de quirófano, un porcentaje—, ese reparto
 * no está acá y hay que agregarlo: sería una columna más, calculada
 * sobre este total.
 *
 * Decirlo importa porque un número que dice «L 42,000» junto al nombre
 * de un doctor se lee como «esto se le paga», y hoy no es eso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LAS ANULACIONES YA ESTÁN RESTADAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Anular un cargo crea su reversa en negativo, y la reversa hereda el
 * médico. Así que el par suma cero solo: un honorario cobrado por error
 * y anulado no infla el mes de nadie. Por eso la tabla NO filtra los
 * anulados —filtrarlos dejaría la anulación afuera y el cargo original
 * adentro, que es el peor de los dos mundos—.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL HISTÓRICO ANTERIOR NO APARECE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los honorarios cobrados antes de que existiera el registro de médicos
 * tienen `medico_id` nulo y no salen acá. No es una falla del reporte:
 * el dato no existe y no se puede inventar sin adivinar a qué doctor
 * pertenecía cada renglón.
 */
class HonorariosPorMedico extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'honorarios-por-medico';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.honorarios-por-medico';

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
        return 'Lo que se cobró por el honorario de cada doctor en el período, con el paciente y '
            .'el pagador de cada renglón. Las anulaciones ya vienen restadas.';
    }

    /**
     * ⚠️ Hacen falta LOS DOS permisos.
     *
     * Ver médicos lo tiene también el rol `medico`, y esta pantalla dice
     * cuánto cobró cada uno de sus colegas. Pedir además el permiso de
     * cargos la deja donde corresponde: dirección, auditoría y caja
     * —que es quien lo cobra y ya lo ve renglón por renglón—.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Medico::class)
            && Gate::allows('viewAny', Cargo::class);
    }

    // ── La cinta de arriba ────────────────────────────────────────────

    /**
     * El total de cada médico en el período, para la cinta.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ NO ALCANZA CON LOS SUBTOTALES DE LA TABLA
     * ─────────────────────────────────────────────────────────────────
     *
     * La tabla ya suma por grupo, pero esos subtotales quedan repartidos
     * entre cien renglones y solo se ven bajando. La pregunta con la que
     * alguien entra —cuánto fue de cada doctor— tiene que estar arriba,
     * completa y sin desplazarse.
     *
     * Cada tarjeta es además un filtro: apretarla deja la tabla en ese
     * médico.
     *
     * @return array<int, array{id: int, nombre: string, especialidad: string, renglones: int, total: string, activo: bool}>
     */
    public function medicos(): array
    {
        $totales = $this->cargosDelPeriodo()
            ->selectRaw('medico_id, count(*) as cuantos, sum(total) as importe')
            ->groupBy('medico_id')
            ->get()
            ->keyBy('medico_id');

        if ($totales->isEmpty()) {
            return [];
        }

        $elegido = $this->medicoFiltrado();

        /*
         * ⚠️ `array_values()` aunque la colección ya venga en orden:
         * `Collection::all()` devuelve `array<int, …>`, que no es una
         * `list` para el analizador.
         */
        return array_values(Medico::query()
            ->with('especialidad')
            ->whereIn('id', $totales->keys())
            ->orderBy('nombre')
            ->get()
            ->map(function (Medico $medico) use ($totales, $elegido): array {
                $fila = $totales->get($medico->id);

                return [
                    'id'           => (int) $medico->getKey(),
                    'nombre'       => $medico->nombre,
                    'especialidad' => $medico->especialidad instanceof Especialidad
                        ? $medico->especialidad->nombre
                        : '',
                    'renglones' => (int) ($fila->cuantos ?? 0),
                    'total'     => self::comoMonto($fila->importe ?? null)->formateado(),
                    'activo'    => $elegido === (int) $medico->getKey(),
                ];
            })
            ->all());
    }

    public function verSoloEste(int $medicoId): void
    {
        $this->tableFilters['medico_id']['value'] = $this->medicoFiltrado() === $medicoId
            ? null
            : $medicoId;

        /*
         * ⚠️ `fill($this->tableFilters)` y NO `resetTableFiltersForm()`:
         * ese último vuelve a los valores por defecto, o sea borra el
         * filtro que se acaba de poner.
         */
        $this->getTableFiltersForm()->fill($this->tableFilters);

        $this->updatedTableFilters();
    }

    /**
     * El total del período completo, sin importar el médico filtrado.
     */
    public function totalDelPeriodo(): string
    {
        return self::comoMonto($this->cargosDelPeriodo()->sum('total'))->formateado();
    }

    /**
     * Cómo se lee el período elegido: «agosto 2026» o «01/08 — 15/08».
     */
    public function periodo(): string
    {
        $desde = $this->fechaDelFiltro('desde') ?? now()->startOfMonth()->toDateString();
        $hasta = $this->fechaDelFiltro('hasta') ?? now()->endOfMonth()->toDateString();

        return CarbonImmutable::parse($desde)->format('d/m/Y')
            .' — '.CarbonImmutable::parse($hasta)->format('d/m/Y');
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consultaBase())
            ->defaultGroup(
                Group::make('medico.nombre')
                    ->label('Médico')
                    ->collapsible()
            )
            ->defaultSort('fecha_operacion', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    ->state(fn (Cargo $record): string => $record->cuenta->encuentro->persona->nombreCompleto())
                    ->description(fn (Cargo $record): string => $record->cuenta->numero)
                    /*
                     * Se busca contra `nombre_busqueda`, la columna
                     * generada de `personas`: es el nombre ya
                     * normalizado —sin tildes, en una sola pieza— y es
                     * la única forma de que «peña» encuentre a PENA.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'cuenta.encuentro.persona',
                        fn (Builder $persona): Builder => $persona->where('nombre_busqueda', 'ilike', '%'.$search.'%'),
                    ))
                    ->wrap(),

                /*
                 * El TEXTO del cargo y no el nombre del ítem: el texto es
                 * el que quedó congelado el día del cobro, y es lo que el
                 * paciente tiene impreso en su factura. Si el catálogo
                 * renombró el honorario después, la factura vieja no
                 * cambió.
                 */
                TextColumn::make('texto')
                    ->label('Honorario')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->wrap(),

                /*
                 * Particular o seguro, de un vistazo. Es la pregunta que
                 * se hace junto con el total: no es lo mismo para el
                 * doctor lo que entró de contado que lo que hay que
                 * esperar a que reembolse una aseguradora.
                 */
                TextColumn::make('como_se_pago')
                    ->label('Cómo se pagó')
                    ->badge()
                    /*
                     * Se arma desde el CARGO y no desde el estado de la
                     * relación: leer `convenio.tipo` obliga a confiar en
                     * que el casteo del enum sobrevivió al camino de la
                     * relación, y si un día viene como string crudo el
                     * `formatStateUsing` revienta con un TypeError en
                     * medio de la tabla.
                     */
                    ->state(fn (Cargo $record): string => $record->convenio->tipo->pagaUnTercero()
                        ? $record->convenio->tipo->etiqueta()
                        : 'Particular')
                    ->color(fn (Cargo $record): string => $record->convenio->tipo->color()),

                TextColumn::make('cantidad')
                    ->label('Cant.')
                    ->alignEnd()
                    ->formatStateUsing(fn (Cargo $record): string => rtrim(rtrim($record->cantidad, '0'), '.'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Importe')
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->weight('medium')
                    ->formatStateUsing(fn (Cargo $record): string => $record->totalParaMostrar())
                    ->color(fn (Cargo $record): ?string => $record->esNegativo() ? 'danger' : null)
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn (mixed $state): string => self::comoMonto($state)->formateado())
                    ),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filters([
                /*
                 * 🔴 El período arranca en el MES EN CURSO y no vacío.
                 *
                 * Sin fechas por defecto, la pantalla abre sumando todo lo
                 * que existe desde el primer día del hospital, y ese
                 * número junto al nombre de un doctor se lee como «esto
                 * es de este mes». La pregunta con la que se entra es
                 * mensual; que el default la conteste.
                 */
                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->startOfMonth()),

                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->endOfMonth())
                            ->afterOrEqual('desde'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['desde'] ?? null),
                            fn (Builder $consulta): Builder => $consulta->whereDate('fecha_operacion', '>=', $data['desde']),
                        )
                        ->when(
                            filled($data['hasta'] ?? null),
                            fn (Builder $consulta): Builder => $consulta->whereDate('fecha_operacion', '<=', $data['hasta']),
                        ))
                    ->indicateUsing(fn (): array => ['Período: '.$this->periodo()]),

                SelectFilter::make('medico_id')
                    ->label('Médico')
                    ->native(false)
                    ->options(fn (): array => Medico::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),

                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->native(false)
                    ->options(fn (): array => Convenio::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),
            ])
            ->emptyStateHeading('Sin honorarios en el período')
            ->emptyStateDescription(
                'Puede ser que no se haya cobrado ninguno, o que se cobraran sin elegir médico. '
                .'Los honorarios anteriores al registro de médicos no aparecen acá: no tienen a '
                .'quién atribuirse.'
            )
            ->recordActions([])
            ->toolbarActions([]);
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * Los honorarios con médico, ya con lo que la tabla va a leer.
     *
     * ⚠️ Sin enumerar columnas en el `with`. Una columna que falte en la
     * lista no da error: devuelve NULL, y la pantalla empieza a mentir en
     * silencio. Ya costó tres veces en la pantalla de existencias.
     *
     * @return Builder<Cargo>
     */
    private function consultaBase(): Builder
    {
        return Cargo::query()
            ->queSuman()
            ->whereNotNull('medico_id')
            ->with([
                'medico.especialidad',
                'convenio',
                'cuenta.encuentro.persona',
            ]);
    }

    /**
     * La misma consulta que la tabla, con el período aplicado pero sin
     * el médico ni el pagador: es lo que la cinta necesita para poder
     * mostrar a TODOS los médicos aunque haya uno filtrado.
     *
     * @return Builder<Cargo>
     */
    private function cargosDelPeriodo(): Builder
    {
        $desde = $this->fechaDelFiltro('desde');
        $hasta = $this->fechaDelFiltro('hasta');

        return Cargo::query()
            ->queSuman()
            ->whereNotNull('medico_id')
            ->whereDate('fecha_operacion', '>=', $desde ?? now()->startOfMonth()->toDateString())
            ->whereDate('fecha_operacion', '<=', $hasta ?? now()->endOfMonth()->toDateString());
    }

    private function medicoFiltrado(): ?int
    {
        $valor = $this->tableFilters['medico_id']['value'] ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * Una de las dos fechas del filtro, o nulo si todavía no se pintó el
     * formulario —que es el primer render—.
     */
    private function fechaDelFiltro(string $cual): ?string
    {
        $valor = $this->tableFilters['periodo'][$cual] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * `sum()` devuelve lo que le da el driver —int, float o string— y
     * ninguna de las tres se le puede pasar directo a `Monto`.
     */
    private static function comoMonto(mixed $suma): Monto
    {
        if (is_string($suma) && $suma !== '') {
            return Monto::de($suma);
        }

        if (is_int($suma)) {
            return Monto::de((string) $suma);
        }

        if (is_float($suma)) {
            return Monto::de(number_format($suma, 2, '.', ''));
        }

        return Monto::de('0');
    }
}
