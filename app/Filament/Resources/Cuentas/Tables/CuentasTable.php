<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Tables;

use App\Domain\Enums\EstadoCuenta;
use App\Models\Cuenta;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Las cuentas, con las vivas arriba.
 *
 * ⚠️ El COSTO y el MARGEN no salen acá. §9.L13: son un permiso, no una
 * columna, y se chequean en el Resource, en la tabla, en el export y en
 * el PDF — los cuatro. Mientras `Ver:Costo` no exista como permiso
 * sembrado, la respuesta correcta es no mostrarlos en ningún lado.
 */
final class CuentasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('abierta_en', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('numero')
                    ->label('Cuenta')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('encuentro.persona')
                    ->label('Paciente')
                    ->state(fn (Cuenta $record): string => $record->encuentro->persona->nombreCompleto())
                    ->description(fn (Cuenta $record): string => $record->encuentro->numero
                        .' · '.$record->encuentro->tipo->etiqueta())
                    ->wrap(),

                TextColumn::make('abierta_en')
                    ->label('Abierta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->badge()
                    ->color(fn (Cuenta $record): string => $record->convenio->tipo->color())
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCuenta $state): string => $state->etiqueta())
                    ->color(fn (EstadoCuenta $state): string => $state->color()),

                TextColumn::make('lineas')
                    ->label('Ítems')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (Cuenta $record): string => $record->saldo()->formateado())
                    ->weight('bold'),

                TextColumn::make('total_paciente')
                    ->label('Paciente')
                    ->alignEnd()
                    ->formatStateUsing(fn (Cuenta $record): string => $record->saldoDelPaciente()->formateado())
                    ->toggleable(),

                TextColumn::make('total_aseguradora')
                    ->label('Aseguradora')
                    ->alignEnd()
                    ->formatStateUsing(fn (Cuenta $record): string => $record->saldoDeLaAseguradora()->formateado())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoCuenta::cases())
                        ->mapWithKeys(fn (EstadoCuenta $e): array => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->relationship('convenio', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ])
            /*
             * Sin bulk actions destructivas (§9.A17). Un borrado masivo
             * sobre cuentas es un incidente del que no se vuelve.
             */
            ->toolbarActions([]);
    }
}
