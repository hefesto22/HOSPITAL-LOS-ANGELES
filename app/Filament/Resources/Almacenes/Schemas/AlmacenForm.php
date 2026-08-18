<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Schemas;

use App\Domain\Enums\TipoAlmacen;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\SedeField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Formulario de Almacén — patrón §10.
 */
final class AlmacenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('almacen')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::control(),
                    self::estado(),
                ]),
        ]);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-archive-box')
            ->schema([
                SedeField::make(),

                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(20)
                    ->disabledOn('edit')
                    ->helperText('Único dentro de la sede.'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                Select::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoAlmacen::cases())
                        ->mapWithKeys(fn (TipoAlmacen $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->helperText('La bodega central no dispensa a paciente: traslada.'),

                Select::make('servicio_id')
                    ->label('Servicio dueño')
                    ->relationship('servicio', 'nombre')
                    ->searchable()
                    ->preload()
                    ->placeholder('Ninguno — no cuelga de un área')
                    ->columnSpanFull()
                    ->helperText(
                        'Dejar vacío para bodega central y farmacia de venta, que no cuelgan de '
                        .'ningún área. Se elige un servicio cuando el almacén es SUYO: el carro de '
                        .'paro de emergencia o el stock de piso de hospitalización.'
                    ),
            ])
            ->columns(2);
    }

    private static function control(): Tab
    {
        return Tab::make('Control')
            ->icon('heroicon-o-lock-closed')
            ->schema([
                Section::make('Estupefacientes y psicotrópicos')
                    ->description(
                        'Marcar esto activa obligaciones ante ARSA: recetario especial autorizado, '
                        .'libro de control con saldo corrido actualizado a diario, y reporte mensual '
                        .'dentro de los primeros 5 días del mes siguiente.'
                    )
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Toggle::make('maneja_controlados')
                            ->label('Este almacén maneja controlados')
                            ->helperText(
                                'Es propiedad del ALMACÉN, no del producto: el mismo medicamento '
                                .'controlado puede estar bajo llave en un almacén y en anaquel en otro.'
                            ),
                    ]),
            ]);
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Vigencia')
                    ->description(
                        'Un almacén que se cierra deja de recibir movimientos y su kardex histórico '
                        .'sigue siendo consultable.'
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
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creado por')
                            ->placeholder('Sistema'),

                        TextEntry::make('updated_at')
                            ->label('Última modificación')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }
}
