<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogResource\Pages\ViewActivityLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Registro de Actividad';

    protected static ?string $pluralModelLabel = 'Registros de Actividad';

    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Tipo')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('subject_type')
                    ->label('Modelo')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('causer.name')
                    ->label('Realizado por')
                    ->placeholder('Sistema')
                    ->searchable()
                    ->icon('heroicon-o-user'),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Tipo de log')
                    ->options(fn () => Activity::distinct()->pluck('log_name', 'log_name')->toArray()),
                SelectFilter::make('subject_type')
                    ->label('Modelo')
                    ->options(fn () => Activity::distinct()
                        ->whereNotNull('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                        ->toArray()),
                Filter::make('created_at')
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] ?? null) {
                            return 'Desde: '.$data['from'];
                        }

                        return null;
                    })
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalle de Actividad')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('log_name')
                                ->label('Tipo de log')
                                ->badge()
                                ->color('primary'),
                            TextEntry::make('description')
                                ->label('Descripción'),
                            TextEntry::make('subject_type')
                                ->label('Modelo afectado')
                                ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                            TextEntry::make('subject_id')
                                ->label('ID del registro'),
                            TextEntry::make('causer.name')
                                ->label('Realizado por')
                                ->placeholder('Sistema'),
                            TextEntry::make('created_at')
                                ->label('Fecha y hora')
                                ->dateTime('d/m/Y H:i:s'),
                        ]),
                    ]),

                // activitylog 5 sacó el diff de atributos de `properties` y lo
                // movió a su propia columna `attribute_changes`. `properties`
                // ahora guarda SOLO lo que uno agrega a mano con
                // withProperty()/withProperties().
                Section::make('Cambios Realizados')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('valores_anteriores')
                                ->label('Valores anteriores')
                                ->state(fn (Activity $record): string => self::formatearJson(data_get($record, 'attribute_changes.old')))
                                ->markdown()
                                ->placeholder('Sin datos anteriores'),
                            TextEntry::make('valores_nuevos')
                                ->label('Valores nuevos')
                                ->state(fn (Activity $record): string => self::formatearJson(data_get($record, 'attribute_changes.attributes')))
                                ->markdown()
                                ->placeholder('Sin datos nuevos'),
                        ]),
                    ]),

                Section::make('Propiedades Adicionales')
                    ->icon('heroicon-o-tag')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('contexto_extra')
                            ->label('Contexto extra registrado')
                            ->state(fn (Activity $record): string => self::formatearJson(data_get($record, 'properties')))
                            ->markdown()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view'  => ViewActivityLog::route('/{record}'),
        ];
    }

    /**
     * Formatea un bloque de cambios de activitylog como JSON legible.
     *
     * ⚠️ Por qué se resuelve con ->state() y NO con notación de punto en
     * TextEntry::make('attribute_changes.old'):
     *
     * Cuando el punto apunta dentro de un array, Filament trata el estado
     * como una LISTA y renderiza un elemento por valor, pasando cada uno
     * por separado a formatStateUsing(). El resultado en pantalla es una
     * columna de guiones separados por comas — que es exactamente lo que
     * se veía en la verificación del 17-ago-2026.
     *
     * Con ->state() el callback recibe el registro completo y devuelve un
     * solo string ya formateado.
     */
    private static function formatearJson(mixed $valores): string
    {
        if ($valores instanceof Collection) {
            $valores = $valores->all();
        }

        if (! is_array($valores) || $valores === []) {
            return '—';
        }

        $json = json_encode($valores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '—' : "```json\n".$json."\n```";
    }
}
