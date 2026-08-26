<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Tables;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Filament\Resources\Conteos\ConteoResource;
use App\Models\Conteo;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Los conteos, con el abierto siempre arriba.
 *
 * ⚠️ Acá NO se muestran diferencias ni saldos del sistema. Mientras el
 * conteo está abierto el recuento es a ciegas (§9.G4): si el que cuenta
 * ve el número que espera el sistema, escribe ese número. Las
 * diferencias aparecen en la pantalla de ver, que es donde se revisan
 * antes de cerrar.
 */
final class ConteosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('almacen'))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('abierto_en')
                    ->label('Abierto')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('almacen.nombre')
                    ->label('Almacén')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoConteo $state): string => $state->etiqueta())
                    ->color(fn (EstadoConteo $state): string => $state->color()),

                TextColumn::make('alcance')
                    ->label('Alcance')
                    ->badge()
                    ->formatStateUsing(fn (AlcanceDeConteo $state): string => $state->etiqueta())
                    ->color(fn (AlcanceDeConteo $state): string => $state->color())
                    ->toggleable(),

                TextColumn::make('descripcion')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('lineas_count')
                    ->label('Líneas')
                    ->counts('lineas')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                TextColumn::make('createdBy.name')
                    ->label('Abrió')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('cerradoPor.name')
                    ->label('Cerró')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoConteo::cases())
                        ->mapWithKeys(fn (EstadoConteo $e): array => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('almacen_id')
                    ->label('Almacén')
                    ->relationship('almacen', 'nombre')
                    ->preload(),
            ])
            ->recordActions([
                /*
                 * El botón que de verdad se usa. Va primero y con color
                 * porque quien entra a esta pantalla con un conteo
                 * abierto viene a contar, no a mirar la lista.
                 */
                Action::make('contar')
                    ->label('Contar')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('warning')
                    ->url(fn (Conteo $record): string => ConteoResource::getUrl('contar', ['record' => $record]))
                    ->visible(fn (Conteo $record): bool => $record->estaAbierto()
                        && Gate::allows('update', $record)),

                ViewAction::make(),
            ])
            /*
             * El más reciente arriba. Como solo puede haber UN conteo
             * abierto por almacén y se abre justo antes de contar, el
             * abierto es casi siempre el primero de la lista — y para las
             * excepciones está el filtro de estado.
             */
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Todavía no se ha contado nada')
            ->emptyStateDescription(
                'Un conteo físico es lo que cuadra el estante con el sistema. Abrí uno, contá con '
                .'la pistola de código de barras, y al cerrarlo las diferencias se asientan solas '
                .'con su motivo.'
            );
    }
}
