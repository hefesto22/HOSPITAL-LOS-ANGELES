<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\RelationManagers;

use App\Models\Item;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los renglones de la plantilla: qué ítem y cuánto.
 *
 * ⚠️ Acá NO se pone precio. El precio se resuelve al cotizar contra el
 * tarifario del convenio del caso (ADR-0003). Una columna de precio en
 * la plantilla obligaría a tener una plantilla por aseguradora.
 */
class LineasRelationManager extends RelationManager
{
    protected static string $relationship = 'lineas';

    protected static ?string $title = 'Qué lleva esta cirugía';

    protected static ?string $modelLabel = 'renglón';

    protected static ?string $pluralModelLabel = 'renglones';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * ─────────────────────────────────────────────────────────
             * ACÁ TAMBIÉN SE PUEDE ESCANEAR
             * ─────────────────────────────────────────────────────────
             *
             * `searchable(['codigo', 'nombre'])` es el buscador que arma
             * Filament solo: un LIKE sobre esas dos columnas. Con eso,
             * pasar el lector por la caja de un medicamento no devolvía
             * NADA, y no por un error visible — la lista simplemente
             * salía vacía y parecía que el producto no estaba cargado.
             *
             * El código de barras del fabricante no vive en el ítem sino
             * en su PRESENTACIÓN: la caja de 100 y el blíster de 12
             * tienen cada uno el suyo. `Item::scopeBuscar` ya sabe eso
             * —lo compara exacto y sin canonizar, porque un `%like%`
             * sobre un EAN devuelve el producto equivocado— además de
             * buscar por nombre con trigramas, que es lo que hace que
             * «acetaminofen» sin tilde encuentre ACETAMINOFÉN.
             *
             * Así que la búsqueda se delega ahí y no se reimplementa: es
             * el mismo buscador del mostrador, y tiene que comportarse
             * igual en las dos pantallas.
             *
             * ⚠️ `soloVigentes: true`: un ítem retirado del catálogo no
             * puede entrar a una plantilla nueva. Los que ya están en
             * plantillas viejas se siguen viendo — eso lo resuelve la
             * relación, no el buscador.
             */
            Select::make('item_id')
                ->label('Ítem del catálogo')
                ->relationship('item', 'nombre')
                ->getOptionLabelFromRecordUsing(
                    fn (Item $record): string => "{$record->codigo} — {$record->nombre}"
                )
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::buscarEnElCatalogo($search))
                ->preload()
                ->required()
                ->columnSpanFull()
                ->helperText('Escaneá el código de barras, o escribí código o nombre. '
                    .'Si no está en el catálogo, primero hay que darlo de alta ahí.'),

            TextInput::make('cantidad')
                ->label('Cantidad típica')
                ->numeric()
                ->minValue(0.0001)
                ->default(1)
                ->required()
                ->helperText('Tres días de habitación son 3. Se ajusta caso por caso al cotizar.'),

            TextInput::make('orden')
                ->label('Orden')
                ->numeric()
                ->integer()
                ->default(0)
                ->helperText('En qué posición sale impreso. De diez en diez deja lugar para meter uno en medio.'),

            Toggle::make('opcional')
                ->label('Es opcional')
                ->helperText('Marcalo si puede que no se use. Igual suma al total: el presupuesto tiene que decir el techo, no el piso.'),

            TextInput::make('nota')
                ->label('Nota interna')
                ->maxLength(200)
                ->columnSpanFull()
                ->helperText('Para quien arma la plantilla, no para el paciente.'),
        ]);
    }

    /**
     * El buscador del mostrador, tal cual, para que escanear encuentre.
     *
     * @return array<int, string>
     */
    private static function buscarEnElCatalogo(string $termino): array
    {
        return Item::buscar($termino, limite: 30, soloVigentes: true)
            ->mapWithKeys(fn (Item $item): array => [
                $item->getKey() => "{$item->codigo} — {$item->nombre}",
            ])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('item:id,codigo,nombre,unidad_dispensacion_id'))
            ->columns([
                TextColumn::make('orden')
                    ->label('#')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('item.codigo')
                    ->label('Código')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('item.nombre')
                    ->label('Ítem')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => is_scalar($state)
                        ? rtrim(rtrim((string) $state, '0'), '.')
                        : '—'),

                IconColumn::make('opcional')
                    ->label('Opcional')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('nota')
                    ->label('Nota')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()->label('Agregar renglón'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin renglones')
            ->emptyStateDescription('Agregá lo que lleva esta cirugía: sala de operaciones, honorarios, días de habitación, laboratorios.');
    }
}
