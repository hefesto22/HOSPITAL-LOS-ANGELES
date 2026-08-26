<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Pages;

use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\Conteos\Actions\AnularConteoAction;
use App\Filament\Resources\Conteos\Actions\CerrarConteoAction;
use App\Filament\Resources\Conteos\ConteoResource;
use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Services\RegistradorDeConteo;
use App\Support\NumeroDeFormulario;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

/**
 * La pantalla que se usa de pie frente al estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESCANEAR · TECLEAR · ENTER · SIGUIENTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * No es un Resource CRUD y no puede serlo (§9.A10). Contar es un flujo
 * con estado, y lo que decide si el módulo sirve o no es cuántos
 * segundos toma cada línea. El foco vuelve al campo de escaneo después de
 * cada registro para que la pistola siga disparando sin tocar el mouse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 RECUENTO A CIEGAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá NO se muestra nunca lo que el sistema espera, ni la diferencia
 * (§9.G4). Si el que cuenta ve el número esperado, escribe ese número —y
 * entonces el conteo deja de medir el estante y pasa a confirmar el
 * sistema, que es exactamente lo contrario de para lo que existe.
 *
 * Lo que sí se muestra es qué falta contar y qué hay que recontar: eso
 * guía el trabajo sin filtrar la respuesta.
 */
class ContarConteo extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConteoResource::class;

    protected string $view = 'filament.resources.conteos.pages.contar';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * El token que distingue «volví a contar» de «apreté dos veces».
     *
     * Vive en el estado de Livewire, así que un segundo envío de la misma
     * acción trae el mismo valor y el servicio devuelve la línea sin
     * tocarla. Se renueva después de cada registro exitoso: la lectura
     * siguiente es una observación nueva y tiene que poder serlo.
     */
    public string $claveDeEnvio = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gate::allows('update', $this->getRecord()), 403);

        $this->claveDeEnvio = (string) Str::uuid();

        $this->form->fill([
            'cantidad' => null,
        ]);
    }

    public function getTitle(): string
    {
        $conteo = $this->conteo();
        $conteo->loadMissing('almacen');

        return 'Contar · '.$conteo->almacen->nombre;
    }

    public function getBreadcrumb(): string
    {
        return 'Contar';
    }

    public function getSubheading(): string
    {
        $conteo = $this->conteo();

        if (! $conteo->estaAbierto()) {
            return 'Este conteo ya terminó. No admite más lecturas.';
        }

        $faltan = $conteo->cuantasFaltan();
        $recontar = $conteo->cuantasExigenRecuento();

        $partes = [];

        $partes[] = $faltan === 0
            ? 'No falta ninguna línea por contar.'
            : ($faltan === 1 ? 'Falta 1 línea por contar.' : "Faltan {$faltan} líneas por contar.");

        if ($recontar > 0) {
            $partes[] = $recontar === 1
                ? 'Hay 1 que se tiene que volver a contar antes de cerrar.'
                : "Hay {$recontar} que se tienen que volver a contar antes de cerrar.";
        }

        return implode(' ', $partes);
    }

    // ── El formulario de captura ──────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('¿Qué estás contando?')
                    ->columns(4)
                    ->schema([
                        BarcodeInput::make('escaneo')
                            ->label('Escaneá el código de barras')
                            ->dehydrated(false)
                            ->live()
                            ->autofocus()
                            ->columnSpanFull()
                            ->helperText(
                                'Con la pistola o con la cámara del teléfono. El producto y su '
                                .'lote quedan puestos; solo tecleás cuánto hay.'
                            )
                            ->afterStateUpdated(fn (mixed $state, Set $set) => $this->resolverEscaneo($state, $set)),

                        Select::make('item_id')
                            ->label('Producto')
                            ->options(fn (): array => Item::query()
                                ->orderBy('nombre')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                                ->all())
                            ->getSearchResultsUsing(fn (string $search): array => Item::buscar($search)
                                ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::itemDe($value)?->etiqueta())
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->live()
                            ->columnSpan(2)
                            ->afterStateUpdated(fn (Set $set) => $set('lote_id', null)),

                        Select::make('lote_id')
                            ->label('Lote')
                            ->options(fn (Get $get): array => $this->lotesDe($get('item_id')))
                            ->native(false)
                            ->searchable()
                            ->required(fn (Get $get): bool => self::itemDe($get('item_id'))?->requiere_lote === true)
                            ->helperText('Se cuenta lote por lote: es lo que dice qué se va a vencer.'),

                        TextInput::make('cantidad')
                            ->label('¿Cuánto hay?')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step('0.0001')
                            ->helperText('Cero es válido: el estante vacío es un dato.'),

                        TextInput::make('notas')
                            ->label('Nota')
                            ->maxLength(200)
                            ->columnSpanFull()
                            ->placeholder('Opcional: dos cajas golpeadas, una sin etiqueta…'),
                    ]),
            ]);
    }

    /**
     * Registrar la lectura y dejar el foco listo para la siguiente.
     */
    public function registrar(): void
    {
        $datos = $this->form->getState();

        $item = self::itemDe($datos['item_id'] ?? null);

        if (! $item instanceof Item) {
            return;
        }

        $lote = isset($datos['lote_id']) && is_numeric($datos['lote_id'])
            ? Lote::query()->find((int) $datos['lote_id'])
            : null;

        /*
         * ⚠️ Si no se entiende el número, NO se asume cero.
         *
         * Acá el cero es un valor legal —«el estante está vacío»—, así que
         * un conversor que devolviera '0' ante un decimal que llegó como
         * float guardaría en silencio la baja del lote completo. Ver
         * `NumeroDeFormulario`.
         */
        $cantidad = NumeroDeFormulario::aDecimal($datos['cantidad'] ?? null);

        if (! $cantidad instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('No se entiende esa cantidad')
                ->body(
                    'Escribí solo números, con punto o coma para los decimales. Ejemplo: 12.5 '
                    .'para media caja de un frasco fraccionable.'
                )
                ->persistent()
                ->send();

            return;
        }

        try {
            $linea = app(RegistradorDeConteo::class)->contar(
                conteo: $this->conteo(),
                item: $item,
                lote: $lote,
                cantidad: $cantidad,
                notas: is_string($datos['notas'] ?? null) && $datos['notas'] !== '' ? $datos['notas'] : null,
                claveDeEnvio: $this->claveDeEnvio,
            );
        } catch (ConteoException|AjusteException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo registrar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $aviso = Notification::make()->title($item->nombre);

        /*
         * El único momento en que la pantalla dice algo sobre la
         * diferencia: que hay que volver a contar. NO dice cuánta ni para
         * qué lado — eso rompería el conteo a ciegas.
         */
        $linea->exige_recuento
            ? $aviso->warning()->body('Registrado. Esta línea hay que contarla otra vez antes de cerrar.')
            : $aviso->success()->body('Registrado.');

        $aviso->send();

        /*
         * Token nuevo: lo que venga después es una lectura distinta, y
         * tiene que poder contar como recuento. Renovarlo acá —y no al
         * empezar la petición— es lo que hace que el reintento del MISMO
         * envío siga trayendo el token viejo.
         */
        $this->claveDeEnvio = (string) Str::uuid();

        /*
         * Se limpia todo menos el foco: el campo de escaneo vuelve a
         * quedar listo y la pistola sigue disparando sin tocar el mouse.
         * La tabla se refresca sola con el re-render de Livewire.
         */
        $this->form->fill(['item_id' => null, 'lote_id' => null, 'cantidad' => null, 'notas' => null]);
    }

    // ── La lista de lo que falta ──────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ConteoLinea::query()
                ->with(['item', 'lote'])
                ->where('conteo_id', $this->conteo()->id))
            ->columns([
                TextColumn::make('item.nombre')
                    ->label('Producto')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('lote.numero')
                    ->label('Lote')
                    ->placeholder('sin lote'),

                TextColumn::make('lote.fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),

                /*
                 * Lo contado sí se muestra: quien acaba de teclear tiene
                 * que poder ver que no se equivocó de tecla. Lo que el
                 * sistema esperaba, no.
                 */
                TextColumn::make('cantidad_contada')
                    ->label('Contado')
                    ->placeholder('sin contar')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),

                IconColumn::make('exige_recuento')
                    ->label('Recontar')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-check')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                TextColumn::make('veces_contado')
                    ->label('Veces')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('pendientes')
                    ->label('Solo las que faltan')
                    ->query(fn (Builder $query): Builder => $query->whereNull('cantidad_contada')),

                Filter::make('recontar')
                    ->label('Solo las que hay que recontar')
                    ->query(fn (Builder $query): Builder => $query->where('exige_recuento', true)),
            ])
            ->recordActions([
                /*
                 * Se escaneó el frasco equivocado y todavía no se contó:
                 * sacarlo. Una línea YA contada no se saca —el servicio
                 * lo rechaza con su mensaje— porque borrarla dejaría el
                 * conteo diciendo que ese producto nunca estuvo en la
                 * planilla.
                 */
                Action::make('quitarLinea')
                    ->label('Quitar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Sacar del conteo')
                    ->modalDescription(
                        'Se agregó por error y todavía no se contó. Sacarla no mueve nada.'
                    )
                    ->visible(fn (ConteoLinea $record): bool => ! $record->estaContada()
                        && $this->conteo()->estaAbierto())
                    ->action(function (ConteoLinea $record): void {
                        try {
                            app(RegistradorDeConteo::class)->quitar($record);

                            Notification::make()
                                ->success()
                                ->title('Sacada del conteo')
                                ->send();
                        } catch (ConteoException|AjusteException $e) {
                            Notification::make()
                                ->danger()
                                ->title('No se pudo sacar')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('id')
            ->paginated([25, 50])
            ->deferLoading()
            ->emptyStateHeading('Todavía no contaste nada')
            ->emptyStateDescription('Escaneá el primer producto o buscalo por nombre.');
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verFicha')
                ->label('Ver la ficha')
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->color('gray')
                ->url(fn (): string => ConteoResource::getUrl('view', ['record' => $this->conteo()])),

            CerrarConteoAction::make()
                ->record($this->conteo()),

            AnularConteoAction::make()
                ->record($this->conteo()),
        ];
    }

    // ── Ayudantes ─────────────────────────────────────────────────────

    private function conteo(): Conteo
    {
        $conteo = $this->getRecord();

        abort_unless($conteo instanceof Conteo, 404);

        return $conteo;
    }

    /**
     * Una lectura del código de barras deja puesto el producto y, si se
     * puede deducir sin ambigüedad, el lote.
     *
     * El campo se limpia siempre —haya encontrado o no— para que el
     * siguiente escaneo entre sin borrar a mano.
     */
    private function resolverEscaneo(mixed $state, Set $set): void
    {
        $codigo = trim(is_string($state) ? $state : '');

        if ($codigo === '') {
            return;
        }

        $set('escaneo', null);

        $presentacion = ItemPresentacion::query()
            ->where('codigo_barras', $codigo)
            ->first();

        if (! $presentacion instanceof ItemPresentacion) {
            Notification::make()
                ->warning()
                ->title('Ese código no está en el catálogo')
                ->body(
                    "Ningún producto tiene el código {$codigo}. Buscalo por nombre y contalo "
                    .'igual; darle de alta el código de barras después hace que el próximo '
                    .'conteo sea instantáneo.'
                )
                ->persistent()
                ->send();

            return;
        }

        $set('item_id', $presentacion->item_id);
        $set('lote_id', $this->loteUnicoDe($presentacion->item_id));
    }

    /**
     * TODOS los lotes de ese producto, marcando cuáles no figuran en este
     * almacén.
     *
     * ⚠️ Ofrecer solo los que ya tienen existencia acá sería el error
     * exacto que el conteo existe para atrapar: un lote que está
     * físicamente en el estante y que el sistema nunca recibió en este
     * almacén **no se podría contar**, que es uno de los hallazgos más
     * valiosos de contar. `enElLote()` devuelve cero para ese caso y el
     * cierre lo asienta como sobrante.
     *
     * @return array<int, string>
     */
    private function lotesDe(mixed $itemId): array
    {
        if (! is_numeric($itemId)) {
            return [];
        }

        $enEsteAlmacen = $this->lotesConSaldo((int) $itemId);

        /** @var array<int, string> $opciones */
        $opciones = Lote::query()
            ->where('lotes.item_id', (int) $itemId)
            ->orderByRaw('lotes.fecha_vencimiento asc nulls last')
            ->get()
            ->mapWithKeys(function (Lote $lote) use ($enEsteAlmacen): array {
                $etiqueta = $lote->numero;

                if ($lote->fecha_vencimiento !== null) {
                    $etiqueta .= ' · vence '.$lote->fecha_vencimiento->format('d/m/Y');
                }

                if (! in_array($lote->id, $enEsteAlmacen, true)) {
                    $etiqueta .= ' · (no figura en este almacén)';
                }

                return [$lote->id => $etiqueta];
            })
            ->all();

        return $opciones;
    }

    /**
     * Los ids de lote que sí tienen fila de existencia en este almacén.
     *
     * @return list<int>
     */
    private function lotesConSaldo(int $itemId): array
    {
        /** @var list<int> $ids */
        $ids = Existencia::query()
            ->where('item_id', $itemId)
            ->where('almacen_id', $this->conteo()->almacen_id)
            ->whereNotNull('lote_id')
            ->pluck('lote_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    private function loteUnicoDe(int $itemId): ?int
    {
        $conSaldo = $this->lotesConSaldo($itemId);

        return count($conSaldo) === 1 ? $conSaldo[0] : null;
    }

    private static function itemDe(mixed $id): ?Item
    {
        return is_numeric($id) ? Item::query()->find((int) $id) : null;
    }
}
