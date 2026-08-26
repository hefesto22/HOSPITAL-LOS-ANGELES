<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Tables;

use App\Domain\Enums\EstadoPresupuesto;
use App\Models\Presupuesto;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PresupuestosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('persona.primer_nombre')
                    ->label('Paciente')
                    ->weight('bold')
                    ->searchable(['primer_nombre', 'primer_apellido'])
                    ->state(fn (Presupuesto $record): string => $record->persona->nombreCompleto())
                    ->description(fn (Presupuesto $record): string => $record->expediente->numero),

                TextColumn::make('titulo')
                    ->label('Concepto')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoPresupuesto $state): string => $state->etiqueta())
                    ->color(fn (EstadoPresupuesto $state): string => $state->color()),

                TextColumn::make('total')
                    ->label('Presupuestado')
                    ->alignEnd()
                    ->money('HNL')
                    ->weight('bold')
                    ->sortable(),

                /*
                 * ⚠️ OCULTA POR DEFECTO Y NO ES CAPRICHO: el consumo se
                 * lee de las cuentas del encuentro, así que esta columna
                 * cuesta UNA CONSULTA POR FILA. En la bandeja no hace
                 * falta —el medidor que importa está en la pantalla de
                 * cuentas, que es donde la cajera lo mira— pero quien
                 * quiera revisar de un vistazo la enciende.
                 */
                TextColumn::make('consumido')
                    ->label('Consumido')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Presupuesto $record): string => $record->encuentro_id === null
                        ? '—'
                        : 'L '.number_format((float) $record->consumido()->redondeado(2), 2))
                    ->badge()
                    ->color(fn (Presupuesto $record): string => $record->encuentro_id === null
                        ? 'gray'
                        : $record->semaforo()),

                TextColumn::make('vence_el')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (Presupuesto $record): string => $record->estado === EstadoPresupuesto::Agregado
                        && $record->estaVencido(now())
                            ? 'danger'
                            : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoPresupuesto::cases())
                        ->mapWithKeys(fn (EstadoPresupuesto $e): array => [$e->value => $e->etiqueta()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make()->label('Abrir'),
            ])
            ->emptyStateHeading('Todavía no hay presupuestos')
            ->emptyStateDescription('Un presupuesto es lo que se le dice a la familia que va a costar. Muchas solo cuentan con ese número.');
    }
}
