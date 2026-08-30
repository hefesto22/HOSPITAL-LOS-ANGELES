<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servicios\Schemas;

use App\Domain\Enums\TipoServicio;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\SedeField;
use App\Models\Servicio;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Formulario de Servicio / área — patrón §10.
 */
final class ServicioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('servicio')
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
                SedeField::make(),

                /*
                 * 🔴 NO SE PIDE AL CREAR. Lo pone `Servicio::booted()` a
                 * partir del nombre: EMERGENCIA da EMERG, QUIRÓFANO da
                 * QUIRO. Al editar se ve pero no se toca: está en los
                 * encuentros y los cargos de esa área.
                 */
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->hiddenOn('create')
                    ->disabledOn('edit')
                    ->maxLength(20)
                    ->helperText('Se genera solo del nombre y ya no cambia. Único dentro de la sede: dos sedes pueden tener cada una su EMERG.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                Select::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoServicio::cases())
                        ->mapWithKeys(fn (TipoServicio $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->helperText(
                        'Determina si el área tiene camas y entra en el censo, y si se atienden '
                        .'pacientes. No es cosmético.'
                    ),

                CampoMayusculas::make('centro_costo')
                    ->label('Centro de costo')
                    ->maxLength(20)
                    ->helperText('A dónde se imputa lo que este servicio consume y no se le cobra al paciente.'),
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
                        'Un servicio que se cierra deja de aparecer en los selectores de hoy y sigue '
                        .'explicando un encuentro de hace dos años.'
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
                            ->afterOrEqual('vigencia_desde')
                            ->helperText('Dejar vacío mientras el servicio siga abierto.'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('almacenes_count')
                            ->label('Almacenes propios')
                            ->state(fn (?Servicio $record): int => $record?->almacenes()->count() ?? 0)
                            ->badge()
                            ->color('info')
                            ->helperText('Cero es válido: el área consume del dispensario.'),

                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creado por')
                            ->placeholder('Sistema'),
                    ])
                    ->columns(3),
            ]);
    }
}
