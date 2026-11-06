<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades\Schemas;

use App\Domain\Enums\TipoItem;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Item;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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

                    /*
                     * 🔴 EL PRECIO DE TODOS SUS MÉDICOS EN CADA SEGURO
                     * (Mauricio, 6-nov-2026). Al buscar a un médico por su
                     * nombre en la cuenta se carga este ítem, y su precio
                     * es el que el seguro tiene pactado para él: «cirugía
                     * general es de L 1,000, entonces todos los doctores
                     * de cirugía general».
                     *
                     * ⚠️ Sin `searchable()`: con opciones estáticas Filament
                     * busca en el servidor y no encuentra nada (ver
                     * `MedicoForm`).
                     */
                    Select::make('item_id')
                        ->label('Honorario de la especialidad')
                        ->native(false)
                        ->columnSpanFull()
                        ->options(fn (): array => Item::query()
                            ->where('tipo', TipoItem::Honorario->value)
                            ->vigentesEn(now())
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all())
                        ->helperText('Su precio en cada seguro es el que se propone para todos los médicos de esta '
                            .'especialidad. Vacío: se cobra como HONORARIO MÉDICO y el monto se escribe.'),
                ])
                ->columns(2),
        ]);
    }
}
