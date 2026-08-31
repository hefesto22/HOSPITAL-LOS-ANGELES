<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Domain\ValueObjects\Decimal;
use App\Models\Tarifario;
use App\Services\FijadorDePrecio;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Los precios de un ítem — el tarifario, visto desde el producto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ NO SE EDITA: SE FIJA
 * ─────────────────────────────────────────────────────────────────────
 *
 * No hay botón de editar ni de borrar, igual que en márgenes objetivo.
 * Cambiar un precio es cerrar el vigente y abrir uno nuevo con fecha: un
 * `UPDATE` sobre la fila vigente borraría la respuesta a «¿por qué esta
 * factura de marzo dice L 29.33?», y una factura que no se puede explicar
 * es un problema ante el SAR.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE MUESTRAN TAMBIÉN LOS VENCIDOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Esconder los precios viejos convertiría la tabla en un campo de
 * configuración con pasos de más. Son el historial, y es lo único que
 * contesta por las facturas de ayer.
 */
class PreciosRelationManager extends RelationManager
{
    protected static string $relationship = 'precios';

    protected static ?string $title = 'Precios';

    protected static function getModelLabel(): ?string
    {
        return 'precio';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'precios';
    }

    /**
     * Lo que cuesta el envase entero.
     *
     * El tarifario guarda el precio por unidad de dispensación; si la
     * fila declara un envase, se multiplica por su contenido. Sin envase
     * —la fila de respaldo— el precio ya es el que se cobra.
     */
    private static function porEnvase(Tarifario $tarifario): Monto
    {
        $presentacion = $tarifario->presentacion;

        if (! $presentacion instanceof ItemPresentacion || ! is_numeric($presentacion->unidades_por_presentacion)) {
            return $tarifario->monto();
        }

        return Monto::de(
            Decimal::de($tarifario->precio)
                ->por(Decimal::de($presentacion->unidades_por_presentacion))
                ->redondeado(2)
        );
    }

    /**
     * De dónde sale la cifra de arriba: «antes del ISV · L 61.11 el ML».
     *
     * El precio unitario no se esconde. Es el que se cobra cuando el
     * producto se fracciona, y es el que hay que mirar para comparar dos
     * envases del mismo jarabe.
     */
    private static function comoSeCalcula(Tarifario $tarifario): string
    {
        $presentacion = $tarifario->presentacion;

        if (! $presentacion instanceof ItemPresentacion || ! is_numeric($presentacion->unidades_por_presentacion)) {
            return 'antes del ISV';
        }

        $unidad = $tarifario->item?->unidadDispensacion?->codigo;

        return 'antes del ISV · '.$tarifario->monto()->formateado()
            .($unidad === null ? ' por unidad' : ' el '.$unidad);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->badge()
                    ->color(fn (Tarifario $record): string => $record->esPrecioDeLista() ? 'gray' : 'info')
                    ->placeholder('Precio de lista')
                    ->description(fn (Tarifario $record): ?string => $record->esPrecioDeLista()
                        ? 'Vale para todo pagador sin precio propio'
                        : null),

                /*
                 * ─────────────────────────────────────────────────────
                 * DE QUÉ ENVASE ES ESTE PRECIO
                 * ─────────────────────────────────────────────────────
                 *
                 * El frasco de 60 ML costó L 16.67 el mililitro y el de
                 * 80 ML costó 18.75: con un solo precio para los dos, el
                 * margen del hospital dependería de cuál estaba abierto y
                 * nadie lo sabría. Acá se ve separado igual que en la
                 * existencia.
                 *
                 * «Todo el producto» es el respaldo: se usa cuando se
                 * dispensa de un lote que no declaró envase.
                 */
                TextColumn::make('presentacion.nombre')
                    ->label('Presentación')
                    /*
                     * «Respaldo» y no «Todo el producto»: la fila sin
                     * envase NO compite con las otras tres, se usa cuando
                     * ninguna aplica. Llamarla como al producto entero
                     * hacía parecer que había cuatro precios para elegir.
                     */
                    ->placeholder('Respaldo · sin envase')
                    /*
                     * Un solo `?->`, en `presentacion`: el nullsafe corta
                     * toda la cadena, así que si no hay presentación esto
                     * ya devuelve null sin tocar `unidad`. Y `unidad_id`
                     * es NOT NULL, o sea que una presentación SIEMPRE
                     * tiene unidad — poner `?->` ahí decía que puede no
                     * tenerla, que es una mentira sobre el esquema.
                     */
                    ->description(fn (Tarifario $record): string => $record->presentacion?->unidad->codigo
                        ?? 'Se usa si el lote no declaró envase'),

                TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->placeholder('Todas')
                    ->toggleable(),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL PRECIO DEL ENVASE, NO EL DEL MILILITRO
                 * ─────────────────────────────────────────────────────
                 *
                 * El tarifario se guarda por unidad de dispensación —L
                 * 61.11 el mililitro— y así tiene que seguir: es la
                 * unidad en la que se cobra un jarabe fraccionado y la
                 * que cuadra con el kardex.
                 *
                 * Pero al lado de una fila que dice «FRASCO 60 ML», ese
                 * 61.11 se lee como el precio del frasco. Y el frasco
                 * cuesta L 3,666.67: sesenta veces más. Alguien que
                 * revisa precios y ve 61.11 concluye que están mal
                 * cargados, o peor, que el hospital vende regalado.
                 *
                 * Arriba va lo que se cobra por envase; debajo, en chico,
                 * el precio unitario del que sale.
                 */
                TextColumn::make('precio')
                    ->label('Precio')
                    ->weight('bold')
                    ->alignEnd()
                    ->formatStateUsing(fn (Tarifario $record): string => self::porEnvase($record)->formateado())
                    ->description(fn (Tarifario $record): string => self::comoSeCalcula($record)),

                TextColumn::make('vigencia_desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('vigencia_hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn (Tarifario $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),

                TextColumn::make('motivo')
                    ->label('Por qué')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('vigencia_desde', 'desc')
            ->paginated([10, 25])
            ->headerActions([
                $this->accionDeFijar(),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('Este ítem todavía no se puede cobrar')
            ->emptyStateDescription(
                'Sin precio de lista, el resolutor se niega a inventar uno: ni el costo ni el '
                .'último precio conocido sirven de reemplazo.'
            );
    }

    private function accionDeFijar(): Action
    {
        return Action::make('fijarPrecio')
            ->label('Fijar un precio')
            ->icon(Heroicon::OutlinedPlus)
            /*
             * Ver el precio es una cosa —el paciente lo ve en el
             * mostrador— y ponerlo es otra. Se pide `update` sobre el
             * ítem, que la matriz solo le concede a dirección: sin esto,
             * cualquiera que pudiera abrir la ficha podía fijar el precio
             * de venta del hospital.
             */
            ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading('Fijar un precio nuevo')
            ->modalDescription(
                'El precio vigente para ese mismo pagador y sede se cierra el día anterior, y este '
                .'arranca en la fecha que elijas. Las facturas ya emitidas no cambian.'
            )
            ->modalSubmitActionLabel('Fijar')
            ->modalWidth('lg')
            ->schema([
                Select::make('convenio_id')
                    ->label('Para quién')
                    ->options(fn (): array => Convenio::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->placeholder('Precio de lista (todos los pagadores)')
                    ->native(false)
                    ->searchable()
                    ->helperText(
                        'Vacío = el precio que ve cualquiera. Elegí un pagador solo si con él se '
                        .'negoció un precio distinto para este ítem.'
                    ),

                Select::make('item_presentacion_id')
                    ->label('De qué envase')
                    ->options(function (): array {
                        $item = $this->getOwnerRecord();

                        if (! $item instanceof Item) {
                            return [];
                        }

                        return ItemPresentacion::query()
                            ->where('item_id', $item->id)
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (ItemPresentacion $p): array => [$p->id => $p->nombre])
                            ->all();
                    })
                    ->placeholder('Todo el producto')
                    ->native(false)
                    ->helperText(
                        'Vacío = el precio de respaldo, el que se usa si el lote no declaró envase. '
                        .'Elegí uno cuando ese frasco tenga su propio costo por unidad.'
                    ),

                Select::make('sede_id')
                    ->label('En qué sede')
                    ->options(fn (): array => Sede::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->placeholder('Todas las sedes')
                    ->native(false),

                TextInput::make('precio')
                    ->label('Precio antes del ISV')
                    ->prefix('L')
                    ->required()
                    /*
                     * `regex` y no `numeric`: `numeric` acepta "1e3", que
                     * entra a bcmath como cero y dejaría el producto
                     * gratis con cara de precio cargado.
                     */
                    ->rule('regex:/^\d{1,9}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con punto decimal: 29.33.',
                    ])
                    ->helperText('El impuesto lo calcula la factura según el régimen del ítem.'),

                DatePicker::make('vigencia_desde')
                    ->label('Vigente desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Tiene que ser posterior a todos los precios que ya existen para ese pagador.'),

                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText(
                        'Es lo que se lee cuando alguien pregunte, dentro de dos años, por qué '
                        .'este producto se vendía a este precio.'
                    ),
            ])
            ->action(function (array $data, Action $action, FijadorDePrecio $fijador): void {
                $item = $this->getOwnerRecord();

                if (! $item instanceof Item) {
                    return;
                }

                /** @var string $precio */
                $precio = $data['precio'];

                /** @var string $motivo */
                $motivo = $data['motivo'];

                /** @var string $desde */
                $desde = $data['vigencia_desde'];

                try {
                    $fila = $fijador->fijar(
                        item: $item,
                        convenio: self::convenioDe($data['convenio_id'] ?? null),
                        sede: self::sedeDe($data['sede_id'] ?? null),
                        precio: Monto::de($precio),
                        motivo: $motivo,
                        desde: Carbon::parse($desde),
                        presentacion: self::presentacionDe($data['item_presentacion_id'] ?? null),
                    );
                } catch (PrecioNoFijableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo fijar el precio')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * `halt()` lanza una excepción de Filament pero está
                     * declarado `void`: el `return` explícito deja claro
                     * —al analizador y a quien lea— que abajo no se sigue.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Precio fijado en '.$fila->monto()->formateado())
                    ->body('Rige desde el '.$fila->vigencia_desde->format('d/m/Y').'.')
                    ->send();
            });
    }

    private static function convenioDe(mixed $id): ?Convenio
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Convenio::query()->find((int) $id);
    }

    private static function presentacionDe(mixed $valor): ?ItemPresentacion
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $presentacion = ItemPresentacion::query()->find((int) $valor);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    private static function sedeDe(mixed $id): ?Sede
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Sede::query()->find((int) $id);
    }
}
