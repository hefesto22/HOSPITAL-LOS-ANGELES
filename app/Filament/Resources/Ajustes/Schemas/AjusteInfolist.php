<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Schemas;

use App\Domain\Enums\TipoDeAjuste;
use App\Models\Ajuste;
use App\Models\MargenObjetivo;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * La ficha de un ajuste asentado.
 *
 * No hay pantalla de edición y no puede haberla: un trigger de PostgreSQL
 * rechaza cualquier `UPDATE`. Lo que se ve acá es lo que pasó, y para
 * siempre.
 */
final class AjusteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El ajuste')
                ->columns(3)
                ->schema([
                    TextEntry::make('tipo')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn (TipoDeAjuste $state): string => $state->etiqueta())
                        ->color(fn (TipoDeAjuste $state): string => $state->color()),

                    TextEntry::make('almacen.nombre')
                        ->label('Almacén'),

                    TextEntry::make('fecha_operacion')
                        ->label('Fecha de la operación')
                        ->date('d/m/Y')
                        ->helperText('Cuándo pasó, que no es cuándo se digitó.'),

                    TextEntry::make('motivo')
                        ->label('Qué pasó')
                        ->columnSpanFull(),

                    TextEntry::make('referencia')
                        ->label('Referencia')
                        ->placeholder('—'),

                    TextEntry::make('conteo_id')
                        ->label('Conteo que lo originó')
                        ->prefix('#')
                        ->placeholder('ninguno: se registró a mano')
                        ->helperText('Las diferencias de conteo no se escriben directo.'),

                    TextEntry::make('ocurrido_en')
                        ->label('Registrado')
                        ->dateTime('d/m/Y H:i'),
                ]),

            Section::make('Plata y firmas')
                ->columns(3)
                ->schema([
                    TextEntry::make('valor_absoluto')
                        ->label('Valor al costo del momento')
                        ->money('HNL')
                        ->visible(fn (): bool => Gate::allows('viewAny', MargenObjetivo::class))
                        ->helperText(
                            'Congelado al asentar: el promedio se mueve con cada compra, y '
                            .'recalcularlo daría otro número.'
                        ),

                    TextEntry::make('createdBy.name')
                        ->label('Lo registró')
                        ->placeholder('—'),

                    TextEntry::make('autorizadoPor.name')
                        ->label('Lo autorizó')
                        ->placeholder('no hizo falta: no pasó el tope')
                        ->helperText(fn (Ajuste $record): ?string => $record->estaAutorizado()
                            ? 'Superó el tope sin autorización, así que alguien de dirección respondió por él.'
                            : null),

                    TextEntry::make('notas')
                        ->label('Notas')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
