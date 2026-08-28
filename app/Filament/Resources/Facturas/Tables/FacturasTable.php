<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Tables;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Exceptions\SihlaException;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Services\EmisorDeFactura;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class FacturasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->copyable()
                    ->description(fn (Factura $record): string => $record->cai),

                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap()
                    /*
                     * 🔴 «CONSUMIDOR FINAL» arriba del umbral es lo
                     * primero que busca una revisión del SAR. Que se vea
                     * de un vistazo, sin abrir nada.
                     */
                    ->description(fn (Factura $record): string => $record->esConsumidorFinal()
                        ? 'sin documento'
                        : $record->rotuloDelDocumento().' '.$record->cliente_documento),

                TextColumn::make('cuenta.numero')
                    ->label('Cuenta')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Factura $record): string => $record->emitida_en->format('H:i')),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoFactura $state): string => $state->etiqueta())
                    ->color(fn (EstadoFactura $state): string => $state->color())
                    ->description(fn (Factura $record): ?string => $record->motivo_anulacion),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoFactura::class),

                Filter::make('sin_documento')
                    ->label('Sin documento (consumidor final)')
                    ->query(fn (Builder $query): Builder => $query->whereNull('cliente_documento')),

                Filter::make('hoy')
                    ->label('Solo las de hoy')
                    ->query(fn (Builder $query): Builder => $query->whereDate('fecha_operacion', now()->toDateString())),
            ])
            ->recordActions([
                /*
                 * ─────────────────────────────────────────────────────
                 * SE ABRE EN MODAL, NO EN PESTAÑA NUEVA
                 * ─────────────────────────────────────────────────────
                 *
                 * Quien cobra no quiere irse de la lista: mira el papel,
                 * lo manda a la impresora y sigue con el siguiente. Una
                 * pestaña aparte por factura termina en diez pestañas
                 * abiertas y en cerrar la que no era.
                 *
                 * ⚠️ Adentro del modal la factura va en un IFRAME, no
                 * pegada como HTML. Es un documento completo, con su
                 * `@page` tamaño carta y sus reglas de `@media print`, y
                 * esas reglas no sobreviven adentro del panel: el CSS de
                 * Filament se les monta encima y lo que sale de la
                 * impresora deja de ser el papel de siempre. Ver
                 * `resources/views/facturas/modal.blade.php`.
                 */
                Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->modalHeading(fn (Factura $record): string => 'Factura '.$record->numero)
                    ->modalDescription('Sale tal como se guardó el día de la emisión.')
                    ->modalWidth('7xl')
                    ->modalContent(fn (Factura $record): View => view('facturas.modal', [
                        'factura' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),

                /*
                 * ⚠️ Anular NO devuelve plata ni libera el número: el
                 * rango sigue consumido y el SAR audita la secuencia.
                 * Sirve para el papel que se arruinó o salió con el
                 * cliente equivocado. Deshacer una factura ya entregada
                 * es una nota de crédito, que todavía no existe.
                 *
                 * Lo que SÍ deshace es el cierre: los cargos vuelven a
                 * pendientes y la cuenta vuelve a abrirse tal como
                 * estaba, para poder volver a facturársela a la misma
                 * paciente. Ver `EmisorDeFactura::anular()`.
                 */
                Action::make('anular')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading('Anular la factura')
                    ->modalDescription(
                        'El número queda consumido: no se reutiliza ni se libera. La cuenta vuelve a abrirse '
                        .'con sus cargos, lista para facturarse otra vez. Si el cliente ya se llevó el papel, '
                        .'esto no alcanza — eso es una nota de crédito.'
                    )
                    ->visible(fn (Factura $record): bool => $record->estaViva() && Gate::allows('update', $record))
                    ->schema([
                        Textarea::make('motivo')
                            ->label('¿Por qué se anula?')
                            ->required()
                            ->minLength(10)
                            ->rows(2)
                            ->maxLength(200),
                    ])
                    ->action(function (Factura $record, array $data): void {
                        try {
                            app(EmisorDeFactura::class)->anular(
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
                            ->title('Factura anulada')
                            ->body($record->numero.' queda anulada, con su número consumido y tu motivo escrito. '
                                .self::comoQuedoLaCuenta($record))
                            ->send();
                    }),
            ]);
    }

    /**
     * La segunda mitad del aviso: qué pasó con la cuenta.
     *
     * Se lee DESPUÉS de anular y desde la base, no de lo que la fila
     * traía cargado: la reapertura la hace el servicio adentro de su
     * transacción y el modelo en memoria todavía dice «cerrada».
     */
    private static function comoQuedoLaCuenta(Factura $factura): string
    {
        $cuenta = $factura->cuenta()->first();

        if (! $cuenta instanceof Cuenta) {
            return '';
        }

        if ($cuenta->estado === EstadoCuenta::Abierta) {
            return 'La cuenta '.$cuenta->numero.' volvió a quedar abierta con sus cargos.';
        }

        return 'La cuenta '.$cuenta->numero.' quedó '.mb_strtolower($cuenta->estado->etiqueta())
            .': revisala antes de volver a facturar.';
    }
}
