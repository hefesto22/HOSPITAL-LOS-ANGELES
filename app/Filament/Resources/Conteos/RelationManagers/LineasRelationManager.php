<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\RelationManagers;

use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Support\UsuarioAutenticado;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las líneas del conteo, para revisarlas antes de cerrar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL RECUENTO A CIEGAS SE RESPETA ACÁ TAMBIÉN
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.G4: quien cuenta no puede ver lo que el sistema espera, porque si lo
 * ve, escribe ese número. La pantalla de contar nunca lo muestra — y esta
 * tampoco, mientras el conteo esté abierto **y quien mira sea quien lo
 * abrió**.
 *
 * Para el resto —el que va a cerrar, dirección, auditoría— las columnas
 * están a la vista desde el primer momento: revisar las diferencias antes
 * de asentarlas es exactamente su trabajo, y el control de cuatro ojos
 * garantiza que no sea la misma persona.
 */
class LineasRelationManager extends RelationManager
{
    protected static string $relationship = 'lineas';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedQueueList;

    protected static ?string $title = 'Lo que se contó';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['item', 'lote', 'contadoPor']))
            ->columns([
                TextColumn::make('item.nombre')
                    ->label('Producto')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('lote.numero')
                    ->label('Lote')
                    ->placeholder('sin lote')
                    ->toggleable(),

                TextColumn::make('lote.fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cantidad_contada')
                    ->label('Contado')
                    ->placeholder('sin contar')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),

                TextColumn::make('cantidad_sistema')
                    ->label('Sistema')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd()
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->puedeVerLosNumerosDelSistema()),

                TextColumn::make('diferencia')
                    ->label('Diferencia')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->color(fn (ConteoLinea $record): string => match (true) {
                        ! $record->estaContada() => 'gray',
                        $record->cuadro()        => 'success',
                        $record->falto()         => 'danger',
                        default                  => 'warning',
                    })
                    ->visible(fn (): bool => $this->puedeVerLosNumerosDelSistema()),

                IconColumn::make('exige_recuento')
                    ->label('Recontar')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-check')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (ConteoLinea $record): string => $record->exige_recuento
                        ? 'La diferencia pasó la tolerancia: hay que contarlo otra vez antes de cerrar.'
                        : 'No hace falta volver a contarlo.'),

                TextColumn::make('veces_contado')
                    ->label('Veces')
                    ->badge()
                    ->color(fn (ConteoLinea $record): string => $record->veces_contado >= 2 ? 'info' : 'gray')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('contadoPor.name')
                    ->label('Contó')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('contado_en')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('pendientes')
                    ->label('Solo las que faltan contar')
                    ->query(fn (Builder $query): Builder => $query->whereNull('cantidad_contada')),

                Filter::make('con_diferencia')
                    ->label('Solo las que no cuadraron')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('cantidad_contada')
                        ->where('diferencia', '<>', 0)),

                Filter::make('exigen_recuento')
                    ->label('Solo las que hay que recontar')
                    ->query(fn (Builder $query): Builder => $query->where('exige_recuento', true)),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Todavía no hay líneas')
            ->emptyStateDescription(
                'En un conteo parcial las líneas nacen al escanear. Abrí la pantalla de contar.'
            );
    }

    /**
     * Quien está contando no ve lo que el sistema espera. Todos los
     * demás sí. La regla vive en el modelo — ver `Conteo::esCiegoPara()`.
     */
    private function puedeVerLosNumerosDelSistema(): bool
    {
        $conteo = $this->getOwnerRecord();

        if (! $conteo instanceof Conteo) {
            return true;
        }

        return ! $conteo->esCiegoPara(UsuarioAutenticado::id());
    }
}
