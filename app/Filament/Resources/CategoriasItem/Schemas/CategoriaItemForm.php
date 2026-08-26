<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem\Schemas;

use App\Domain\Enums\AmbitoCatalogo;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\CategoriaItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Formulario de categoría del catálogo — patrón §10.
 */
final class CategoriaItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('categoria')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::estado(),
                ]),
        ]);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-squares-2x2')
            ->schema([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(20)
                    ->disabledOn('edit')
                    ->helperText('Corto y estable. No se puede cambiar después.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Como está escrito en el tarifario: «ÁREA DE HOSPITALIZACIÓN», «RAYOS X».'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL ÁMBITO NO SE CAMBIA CON ÍTEMS ADENTRO
                 * ─────────────────────────────────────────────────────
                 *
                 * La FK compuesta `items_categoria_fk` apunta a
                 * `(id, ambito)`, así que cambiarlo con ítems colgando lo
                 * rechaza la base. Se deshabilita acá para no ofrecer un
                 * camino que termina en un error de SQL en la cara del
                 * usuario, pero el que manda sigue siendo el constraint.
                 */
                Select::make('ambito')
                    ->label('Lado del catálogo')
                    ->required()
                    ->native(false)
                    ->options(fn (): array => collect(AmbitoCatalogo::cases())
                        ->mapWithKeys(fn (AmbitoCatalogo $a): array => [$a->value => $a->etiqueta()])
                        ->all())
                    ->default(AmbitoCatalogo::Servicios->value)
                    ->disabled(fn (?CategoriaItem $record): bool => $record instanceof CategoriaItem
                        && $record->items()->exists())
                    ->helperText(fn (?CategoriaItem $record): string => $record instanceof CategoriaItem
                        && $record->items()->exists()
                            ? 'No se puede cambiar: ya hay ítems clasificados acá. Movelos primero.'
                            : 'Servicios es lo que se ofrece y se cobra. Farmacia es lo que se guarda y se cuenta.'),

                TextInput::make('orden')
                    ->label('Orden')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->default(100)
                    ->required()
                    ->helperText('En qué posición aparece en la lista. El tarifario impreso tiene un orden que el personal ya conoce.'),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Opcional. Qué entra y qué no — se lee cuando alguien duda al clasificar.'),
            ])
            ->columns(2);
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Vigencia')
                    ->description(
                        'Una categoría retirada deja de ofrecerse para ítems nuevos, y sigue explicando '
                        .'las facturas viejas donde aparece (§8.4).'
                    )
                    ->schema([
                        DatePicker::make('vigencia_desde')
                            ->label('Vigente desde')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        DatePicker::make('vigencia_hasta')
                            ->label('Vigente hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('vigencia_desde'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('items_count')
                            ->label('Ítems clasificados')
                            ->state(fn (CategoriaItem $record): int => $record->items()->count()),

                        TextEntry::make('created_at')
                            ->label('Creada')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creada por')
                            ->placeholder('Sistema'),
                    ])
                    ->columns(3),
            ]);
    }
}
