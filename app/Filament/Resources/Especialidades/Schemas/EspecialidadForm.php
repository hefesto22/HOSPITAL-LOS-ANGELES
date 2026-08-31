<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class EspecialidadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La especialidad')
                ->columnSpanFull()
                ->schema([
                    CampoMayusculas::make('codigo')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        ->helperText('Corto y estable: CIRGEN, ANEST, PEDIA. Es lo que se ve en los listados.'),

                    CampoMayusculas::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),

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
                        ->helperText('Vacío mientras el hospital siga atendiendo esta especialidad.'),
                ])
                ->columns(2),
        ]);
    }
}
