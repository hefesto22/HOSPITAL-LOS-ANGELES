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
            Select::make('item_id')
                ->label('Ítem del catálogo')
                ->relationship('item', 'nombre')
                ->getOptionLabelFromRecordUsing(
                    fn (Item $record): string => "{$record->codigo} — {$record->nombre}"
                )
                ->searchable(['codigo', 'nombre'])
                ->preload()
                ->required()
                ->columnSpanFull()
                ->helperText('Buscá por código o por nombre. Si no está en el catálogo, primero hay que darlo de alta ahí.'),

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
