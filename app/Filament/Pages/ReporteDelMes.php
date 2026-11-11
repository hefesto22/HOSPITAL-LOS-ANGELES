<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\Concerns\ElijePeriodo;
use App\Models\Convenio;
use App\Models\Factura;
use App\Services\ResumenDeFacturacion;
use App\Support\Periodo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * El reporte del mes (Mauricio, 6-nov-2026; 11-nov-2026): de las facturas
 * del período, cuánto se facturó, cómo lo pagaron los pacientes por forma
 * de pago, qué queda después de medicamentos y honorarios de médicos, y
 * cuánto quedó a crédito de cada seguro, con el detalle factura por factura.
 *
 * Los números los arma `ResumenDeFacturacion` —ahí está por qué todo sale
 * de las facturas y no de la caja del día—. Esta página solo elige el
 * período y dibuja.
 *
 * Solo dirección y auditoría, con permiso propio como Honorarios por
 * médico: dice cuánto vende el hospital y cuánto le deben los seguros, y
 * eso no es trabajo del turno de caja.
 */
class ReporteDelMes extends Page implements HasTable
{
    use ElijePeriodo;
    use InteractsWithTable;

    /**
     * Lo crea `MatrizDePermisosSeeder` y se lo da a dirección y auditoría.
     */
    public const PERMISO = 'View:ReporteDelMes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'reporte-del-mes';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.reporte-del-mes';

    public static function getNavigationLabel(): string
    {
        return 'Reporte del mes';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    public function getTitle(): string
    {
        return 'Reporte del mes';
    }

    public function getSubheading(): string
    {
        return 'De las facturas del período: lo facturado, cómo se pagó, lo que queda después de medicamentos y honorarios, y lo que deben los seguros.';
    }

    public static function canAccess(): bool
    {
        return Gate::allows(self::PERMISO);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->leerElPeriodoDeLaUrl();
    }

    /**
     * @return array<string, string>
     */
    protected function atajosDelPeriodo(): array
    {
        return [
            'este_mes'      => 'Este mes',
            'mes_pasado'    => 'Mes pasado',
            'esta_quincena' => 'Esta quincena',
            'hoy'           => 'Hoy',
        ];
    }

    /**
     * Los números van a la vista y no por un método público: un método
     * público de Livewire se puede llamar desde el navegador (§9.M-30).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'resumen' => app(ResumenDeFacturacion::class)->delPeriodo(
                $this->desdeEfectivo(),
                $this->hastaEfectivo(),
                $this->sede(),
            ),
            'lempiras' => fn (string $monto): string => self::lempiras($monto),
        ];
    }

    // ── El papel ──────────────────────────────────────────────────────

    /**
     * «Imprimir», arriba a la derecha (Mauricio, 11-nov-2026: «sobre todo
     * que se pueda imprimir»). Abre la hoja en un modal y la ventana de
     * imprimir sale sola, como los honorarios: el mismo `honorarios.modal`,
     * con «Solo el resumen» o «Con las facturas».
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->modalHeading(fn (): string => 'Reporte del mes · '.$this->periodo())
                ->modalDescription('La ventana de imprimir se abre sola. Si la cerrás, «Imprimir» la vuelve a abrir.')
                ->modalWidth('7xl')
                ->modalContent(fn (): View|HtmlString => $this->sePuedeImprimir()
                    ? view('honorarios.modal', [
                        'enlace'             => $this->enlaceDelReporte(incrustada: true),
                        'enlaceConDetalle'   => $this->enlaceDelReporte(incrustada: true, conFacturas: true),
                        'etiquetaConDetalle' => 'Con las facturas',
                        'titulo'             => 'Reporte del mes · '.$this->periodo(),
                    ])
                    : new HtmlString('<p>El reporte impreso cubre como máximo un año: elegí un período más corto.</p>'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar'),
        ];
    }

    /**
     * La hoja del período que se está viendo. Público para probarlo; solo
     * arma una URL que igual pide el permiso al abrirse.
     */
    public function enlaceDelReporte(bool $incrustada = false, bool $conFacturas = false): string
    {
        return route('reportes.mes', ['desde' => $this->desdeEfectivo(), 'hasta' => $this->hastaEfectivo()]
            + ($conFacturas ? ['facturas' => 1] : [])
            + ($incrustada ? ['incrustada' => 1] : []));
    }

    public function sePuedeImprimir(): bool
    {
        return Periodo::dias($this->desdeEfectivo(), $this->hastaEfectivo()) <= ResumenDeFacturacion::DIAS_MAXIMOS;
    }

    // ── El detalle ────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->facturasDelPeriodo())
            ->defaultSort('emitida_en', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->emptyStateHeading('Sin facturas en el período')
            ->columns([
                TextColumn::make('numero')
                    ->label('Factura')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),

                TextColumn::make('emitida_en')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->searchable(['cliente_nombre', 'paciente_nombre'])
                    ->wrap()
                    ->description(fn (Factura $record): ?string => $record->paciente_nombre !== null
                        && $record->paciente_nombre !== $record->cliente_nombre
                            ? 'Paciente: '.$record->paciente_nombre
                            : null),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->placeholder('Particular')
                    ->wrap(),

                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras((string) $state))
                    ->sortable(),

                TextColumn::make('a_cargo_del_seguro')
                    ->label('Seguro')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => Decimal::de(is_numeric($state) ? (string) $state : '0')->esCero()
                        ? '—'
                        : self::lempiras((string) $state)),

                TextColumn::make('a_cargo_del_paciente')
                    ->label('Paciente')
                    ->alignEnd()
                    ->state(fn (Factura $record): string => self::lempiras(
                        Decimal::de($record->total)->restar($record->a_cargo_del_seguro)->redondeado(2)
                    )),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoFactura $state): string => $state->etiqueta())
                    ->color(fn (EstadoFactura $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->options(fn (): array => Convenio::query()->orderBy('nombre')->pluck('nombre', 'id')->all()),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(collect(EstadoFactura::cases())
                        ->mapWithKeys(fn (EstadoFactura $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all()),
            ]);
    }

    /**
     * @return Builder<Factura>
     */
    private function facturasDelPeriodo(): Builder
    {
        $sede = $this->sede();

        return Factura::query()
            ->with('convenio:id,nombre')
            ->where('tipo', TipoDocumentoDeVenta::Factura->value)
            ->whereBetween('fecha_operacion', [$this->desdeEfectivo(), $this->hastaEfectivo()])
            ->when($sede !== null, fn (Builder $consulta): Builder => $consulta->where('sede_id', $sede));
    }

    /**
     * La sede de quien mira, con la misma regla que el papel.
     */
    private function sede(): ?int
    {
        return ResumenDeFacturacion::sedeDe(auth()->user());
    }

    /**
     * «L 1,250.00». Solo para mostrar: las cuentas se hacen con `Decimal`.
     */
    private static function lempiras(string $monto): string
    {
        $monto = is_numeric($monto) ? $monto : '0';

        return 'L '.number_format((float) Decimal::de($monto)->redondeado(2), 2);
    }
}
