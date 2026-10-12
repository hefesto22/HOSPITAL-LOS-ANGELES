<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos\Tables;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Exceptions\SihlaException;
use App\Models\Abono;
use App\Models\TurnoDeCaja;
use App\Services\ReceptorDeAbono;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AbonosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cuenta.numero')
                    ->label('Cuenta')
                    ->searchable()
                    ->description(fn (Abono $record): ?string => $record->entregado_por),

                TextColumn::make('total')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->sortable(),

                /*
                 * Con qué se pagó. Es lo primero que se busca cuando el
                 * banco no muestra un depósito que el sistema sí tiene.
                 */
                TextColumn::make('medios')
                    ->label('Formas de pago')
                    ->wrap()
                    ->state(fn (Abono $record): string => $record->resumenDeMedios()),

                TextColumn::make('turno.numero')
                    ->label('Turno')
                    ->description(fn (Abono $record): ?string => $record->turno?->nombre),

                TextColumn::make('recibidoPor.name')
                    ->label('Recibió')
                    ->toggleable(),

                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Abono $record): string => $record->recibido_en->format('H:i')),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoAbono $state): string => $state->etiqueta())
                    ->color(fn (EstadoAbono $state): string => $state->color())
                    ->description(fn (Abono $record): ?string => $record->motivo_anulacion),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoAbono::class),

                /*
                 * ⚠️ El parámetro se llama `$query` y no `$consulta`: con
                 * cualquier otro nombre Filament entrega un Builder vacío
                 * del contenedor y el filtro no filtra nada, sin error y
                 * sin log. Hay una prueba de arquitectura que lo vigila.
                 */
                Filter::make('hoy')
                    ->label('Solo los de hoy')
                    ->query(fn (Builder $query): Builder => $query->whereDate('fecha_operacion', now()->toDateString())),
            ])
            /*
             * ─────────────────────────────────────────────────────────
             * ANULAR UN RECIBO DESDE LA LISTA (11-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Hasta hoy se anulaba solo desde «Caja», y solo los del
             * turno propio. Un recibo que dejó «Cobrar ahora» al emitir
             * una factura que después se anuló quedaba vivo, y quien lo
             * buscaba acá lo veía sin poder hacer nada.
             *
             * Las reglas son las mismas de siempre y las pone
             * `ReceptorDeAbono::anular()`: motivo escrito y turno
             * abierto. Con el turno cerrado ese efectivo ya se contó y se
             * entregó — devolverlo es otro hecho —, y el botón no aparece.
             */
            ->recordActions([
                Action::make('anular')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading(fn (Abono $record): string => 'Anular el recibo '.$record->numero)
                    ->modalDescription('Sale del saldo de la cuenta y del arqueo del turno. Úsalo si la plata '
                        .'no entró o se devolvió.')
                    ->visible(fn (Abono $record): bool => $record->estado === EstadoAbono::Aplicado
                        && $record->turno instanceof TurnoDeCaja
                        && $record->turno->estaAbierto()
                        && Gate::allows('update', $record))
                    ->schema([
                        Textarea::make('motivo')
                            ->label('¿Por qué se anula?')
                            ->required()
                            ->minLength(10)
                            ->rows(2)
                            ->maxLength(200),
                    ])
                    ->action(function (Abono $record, array $data): void {
                        abort_unless(Gate::allows('update', $record), 403);

                        try {
                            app(ReceptorDeAbono::class)->anular(
                                $record,
                                is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                                UsuarioAutenticado::id(),
                            );
                        } catch (SihlaException $e) {
                            Notification::make()->danger()->title('No se pudo anular')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Recibo anulado')
                            ->body($record->numero.' ya no cuenta en el saldo de la cuenta ni en el arqueo.')
                            ->send();
                    }),
            ]);
    }
}
