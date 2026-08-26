<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros\Schemas;

use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEgreso;
use App\Domain\Enums\TipoEncuentro;
use App\Models\Encuentro;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

final class EncuentroInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Encuentro')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Identificación')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('numero')->label('Número')->copyable(),

                            TextEntry::make('paciente')
                                ->label('Paciente')
                                ->state(fn (Encuentro $record): string => $record->persona->nombreCompleto()),

                            TextEntry::make('expediente.numero')->label('Expediente'),

                            TextEntry::make('tipo')
                                ->label('Tipo de atención')
                                ->badge()
                                ->formatStateUsing(fn (TipoEncuentro $state): string => $state->etiqueta())
                                ->color(fn (TipoEncuentro $state): string => $state->color()),

                            TextEntry::make('servicio.nombre')->label('Servicio')->placeholder('—'),

                            TextEntry::make('medicoTratante.name')->label('Médico tratante')->placeholder('—'),

                            TextEntry::make('motivo')->label('Motivo')->placeholder('—')->columnSpanFull(),
                        ]),

                    Tab::make('Egreso')
                        ->columns(3)
                        ->schema([
                            Section::make('Los tres tiempos')
                                ->columns(3)
                                ->columnSpanFull()
                                ->description(
                                    'Son distintos y obligatorios (§9.K8). Colapsarlos en uno hace imposible '
                                    .'medir la demora del egreso, y produce el caso del paciente que «ya salió» '
                                    .'según el sistema y sigue en la cama sin pagar.'
                                )
                                ->schema([
                                    TextEntry::make('alta_medica_en')
                                        ->label('Alta médica (decisión clínica)')
                                        ->dateTime('d/m/Y H:i')
                                        ->placeholder('Todavía no'),

                                    TextEntry::make('alta_administrativa_en')
                                        ->label('Alta administrativa (cuenta liquidada)')
                                        ->dateTime('d/m/Y H:i')
                                        ->placeholder('Todavía no'),

                                    TextEntry::make('salida_fisica_en')
                                        ->label('Salida física (cama liberada)')
                                        ->dateTime('d/m/Y H:i')
                                        ->placeholder('Todavía no'),
                                ]),

                            TextEntry::make('tipo_egreso')
                                ->label('Tipo de egreso')
                                ->badge()
                                ->placeholder('—')
                                ->formatStateUsing(fn (?TipoEgreso $state): string => $state?->etiqueta() ?? '—')
                                ->color(fn (?TipoEgreso $state): string => $state?->color() ?? 'gray'),

                            TextEntry::make('cerrado_en')->label('Cerrado')->dateTime('d/m/Y H:i')->placeholder('—'),

                            TextEntry::make('encuentroAnterior.numero')
                                ->label('Ingreso anterior (menos de 30 días)')
                                ->placeholder('—')
                                ->helperText('Reingreso temprano: indicador de calidad y de negociación con aseguradoras.'),
                        ]),

                    Tab::make('Estado')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('estado')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (EstadoEncuentro $state): string => $state->etiqueta())
                                ->color(fn (EstadoEncuentro $state): string => $state->color()),

                            TextEntry::make('abierto_en')->label('Ingreso')->dateTime('d/m/Y H:i'),
                            TextEntry::make('motivo_anulacion')->label('Motivo de anulación')->placeholder('—'),
                            TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                            TextEntry::make('updated_at')->label('Último cambio')->dateTime('d/m/Y H:i'),
                        ]),
                ]),
        ]);
    }
}
