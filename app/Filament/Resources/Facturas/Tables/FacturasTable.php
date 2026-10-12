<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Tables;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Domain\Exceptions\SihlaException;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Abono;
use App\Models\AbonoMedio;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\TurnoDeCaja;
use App\Services\EmisorDeFactura;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class FacturasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->striped()
            ->columns([
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 SEIS COLUMNAS QUE SE LEEN DE UN VISTAZO (12-oct-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Mauricio: «mejoremos el diseño, está muy feo». Eran
                 * nueve columnas y la de Imprimir se salía de la
                 * pantalla. El CAI —37 caracteres, el mismo en todo el
                 * rango— se comía la mitad del ancho: pasa al globo del
                 * número. La fecha va debajo del número. Estado y período
                 * se dicen en UNA etiqueta: vigente, declarada o anulada.
                 *
                 * ⚠️ TODA columna lleva `grow(false)` salvo el cliente y
                 * el paciente: Filament reparte el ancho sobrante entre
                 * las que crecen. Con el paciente fijo, el nombre salía
                 * cortado y la cuenta partida en tres renglones.
                 */
                TextColumn::make('numero')
                    ->label('Factura')
                    ->fontFamily(FontFamily::Mono)
                    ->weight('semibold')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número copiado')
                    ->tooltip(fn (Factura $record): string => 'CAI '.$record->cai)
                    ->grow(false)
                    ->description(fn (Factura $record): string => $record->fecha_operacion->format('d/m/Y')
                        .' · '.$record->emitida_en->format('H:i')),

                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->lineClamp(2)
                    ->grow()
                    /*
                     * 🔴 «CONSUMIDOR FINAL» arriba del umbral es lo
                     * primero que busca una revisión del SAR. Que se vea
                     * de un vistazo, sin abrir nada.
                     */
                    ->description(fn (Factura $record): string => $record->esConsumidorFinal()
                        ? 'Sin documento'
                        : $record->rotuloDelDocumento().' '.$record->cliente_documento),

                /*
                 * Quién se atendió, aparte de quién paga (11-oct-2026),
                 * con su cuenta debajo. Las facturas de antes de ese día
                 * lo llevan dentro del nombre del cliente y acá salen con
                 * la raya.
                 */
                TextColumn::make('paciente_nombre')
                    ->label('Paciente')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->grow()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $donde): Builder => $donde
                            ->where('paciente_nombre', 'ilike', '%'.$search.'%')
                            ->orWhereHas('cuenta', fn (Builder $cuenta): Builder => $cuenta->where('numero', 'ilike', '%'.$search.'%')),
                    ))
                    ->description(fn (Factura $record): ?string => $record->cuenta instanceof Cuenta ? $record->cuenta->numero : null),

                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (mixed $state): string => 'L '.number_format(is_numeric($state) ? (float) $state : 0.0, 2))
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->grow(false),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 VIGENTE, DECLARADA O ANULADA
                 * ─────────────────────────────────────────────────────
                 *
                 * El hospital declara el mes anterior el día 10: lo de
                 * julio se anula hasta el 9 de agosto. Vigente es lo que
                 * todavía se puede corregir; declarada, lo que ya quedó
                 * firme —«eso ya no se puede, va nota de crédito»—.
                 *
                 * Es DERIVADO de `fecha_operacion` contra hoy, no una
                 * columna: guardarlo obligaría a un proceso que lo
                 * actualice cada día 10, y el día que no corra la
                 * pantalla mentiría.
                 */
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Factura $record): string => self::situacion($record))
                    ->color(fn (Factura $record): string => self::colorDeLaSituacion($record))
                    ->icon(fn (Factura $record): Heroicon => self::iconoDeLaSituacion($record))
                    ->grow(false)
                    ->description(fn (Factura $record): ?string => self::detalleDeLaSituacion($record))
                    ->tooltip(fn (Factura $record): ?string => $record->estado === EstadoFactura::Anulada
                        ? $record->motivo_anulacion
                        : null),
            ])
            ->filters([
                /*
                 * ⚠️ Vigentes, de hoy, declaradas y anuladas NO están
                 * acá: son PESTAÑAS, arriba de la tabla
                 * (`ListFacturas::getTabs`). Lo que queda acá es el
                 * recorte que se combina con cualquiera de ellas, y va
                 * en el menú del embudo: un recuadro entero con un
                 * botón «Aplicar» para un solo interruptor empujaba la
                 * tabla media pantalla hacia abajo.
                 *
                 * ⚠️ `$query` en el cierre. Con otro nombre Filament
                 * entrega un Builder vacío del contenedor y el filtro
                 * deja de filtrar EN SILENCIO.
                 *
                 * 🔴 «CONSUMIDOR FINAL» arriba del umbral es lo primero
                 * que busca una revisión del SAR.
                 */
                Filter::make('sin_documento')
                    ->label('Sin documento (consumidor final)')
                    ->toggle()
                    ->query(function (Builder $query): void {
                        self::sinDocumento($query);
                    }),
            ])
            ->deferFilters(false)
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
                    ->button()
                    ->outlined()
                    ->size(Size::Small)
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
                    ->iconButton()
                    ->tooltip('Anular la factura')
                    ->color('danger')
                    ->modalHeading('Anular la factura')
                    ->modalDescription(fn (Factura $record): string => 'El número queda consumido: no se '
                        .'reutiliza ni se libera. La cuenta vuelve a abrirse con sus cargos, lista para '
                        .'facturarse otra vez. Se puede hasta el '.$record->limiteParaAnular()->format('d/m/Y')
                        .', que es cuando se declara '.$record->periodoFiscal().'. Si el cliente ya se llevó '
                        .'el papel, esto no alcanza — eso es una nota de crédito.')
                    /*
                     * ⚠️ El botón desaparece pasado el 9. No es que
                     * falte permiso: el mes ya se declaró y anularla
                     * dejaría lo emitido y lo declarado diciendo cosas
                     * distintas. `EmisorDeFactura::anular()` lo verifica
                     * otra vez, porque una pestaña abierta desde ayer
                     * todavía tiene el botón dibujado.
                     */
                    ->visible(fn (Factura $record): bool => $record->sePuedeAnular()
                        && Gate::allows('update', $record))
                    ->schema([
                        CampoMayusculas::area('motivo')
                            ->label('¿Por qué se anula?')
                            ->required()
                            ->minLength(10)
                            ->rows(2)
                            ->maxLength(200),

                        /*
                         * ─────────────────────────────────────────────
                         * 🔴 ¿SE LE DEVOLVIÓ LA PLATA? (11-oct-2026)
                         * ─────────────────────────────────────────────
                         *
                         * Mauricio: «facturé y la anulé, y dice que ya
                         * abonó». Emitir con «Cobrar ahora» deja un
                         * recibo, y anular la factura no lo toca: la
                         * plata entró y, si se vuelve a facturar, ese
                         * recibo sigue pagando la cuenta.
                         *
                         * Pero si la plata SE DEVOLVIÓ, el recibo tiene
                         * que caer con la factura. Se pregunta acá, con
                         * los recibos a la vista, y sin nada marcado:
                         * anular un recibo es sacar plata del arqueo, y
                         * eso no puede pasar sin que alguien lo decida.
                         */
                        CheckboxList::make('devueltos')
                            ->label('¿Se le devolvió la plata?')
                            ->options(fn (Factura $record): array => self::recibosQueSePuedenDevolver($record))
                            ->helperText('Marcá solo el recibo cuya plata salió de la gaveta de vuelta. Sin marcar, '
                                .'el recibo sigue pagando la cuenta y al volver a facturar no se cobra dos veces.')
                            ->visible(fn (Factura $record): bool => self::recibosQueSePuedenDevolver($record) !== []),
                    ])
                    ->action(function (Factura $record, array $data): void {
                        /*
                         * Solo los que la pantalla ofreció: de esta
                         * cuenta, con el turno abierto y que quien anula
                         * puede tocar. Un id armado a mano no pasa.
                         */
                        $ofrecidos = array_keys(self::recibosQueSePuedenDevolver($record));
                        $devueltos = [];

                        foreach (is_array($data['devueltos'] ?? null) ? $data['devueltos'] : [] as $id) {
                            if (is_numeric($id) && in_array((int) $id, $ofrecidos, true)) {
                                $devueltos[] = (int) $id;
                            }
                        }

                        try {
                            app(EmisorDeFactura::class)->anular(
                                $record,
                                is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                                UsuarioAutenticado::id(),
                                $devueltos,
                            );
                        } catch (SihlaException $e) {
                            Notification::make()->danger()->title('No se pudo anular')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Factura anulada')
                            ->body($record->numero.' queda anulada, con su número consumido y tu motivo escrito. '
                                .($devueltos === []
                                    ? ''
                                    : count($devueltos).' recibo(s) anulado(s) por devolución: ya no cuentan en el saldo ni en el arqueo. ')
                                .self::comoQuedoLaCuenta($record))
                            ->send();
                    }),
            ]);
    }

    /**
     * Vigente, declarada o anulada: lo único que decide qué se puede
     * hacer con el papel.
     */
    private static function situacion(Factura $factura): string
    {
        if ($factura->estado === EstadoFactura::Anulada) {
            return 'Anulada';
        }

        return $factura->yaSeDeclaro() ? 'Declarada' : 'Vigente';
    }

    private static function colorDeLaSituacion(Factura $factura): string
    {
        if ($factura->estado === EstadoFactura::Anulada) {
            return 'danger';
        }

        return $factura->yaSeDeclaro() ? 'gray' : 'success';
    }

    private static function iconoDeLaSituacion(Factura $factura): Heroicon
    {
        if ($factura->estado === EstadoFactura::Anulada) {
            return Heroicon::OutlinedXCircle;
        }

        return $factura->yaSeDeclaro() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedCheckCircle;
    }

    /**
     * La línea de abajo de la etiqueta: hasta cuándo se puede anular, en
     * qué declaración viajó, o por qué se anuló.
     */
    private static function detalleDeLaSituacion(Factura $factura): ?string
    {
        if ($factura->estado === EstadoFactura::Anulada) {
            return $factura->motivo_anulacion === null
                ? null
                : Str::limit($factura->motivo_anulacion, 40);
        }

        return $factura->yaSeDeclaro()
            ? 'En la de '.$factura->periodoFiscal()
            : 'Anulable hasta el '.$factura->limiteParaAnular()->format('d/m');
    }

    /**
     * @param Builder<Factura> $consulta
     */
    private static function sinDocumento(Builder $consulta): void
    {
        $consulta->whereNull('cliente_documento');
    }

    /**
     * Los recibos vivos de la cuenta de esta factura que todavía se
     * pueden anular —su turno sigue abierto— y que quien anula puede
     * tocar: «REC-HLA-2026-000002 · Efectivo · L 26.95 · 11/10 19:02».
     *
     * Con el turno cerrado ese efectivo ya se contó y se entregó: no se
     * ofrece, porque devolverlo es otro hecho.
     *
     * @return array<int, string>
     */
    private static function recibosQueSePuedenDevolver(Factura $factura): array
    {
        $recibos = Abono::query()
            ->where('cuenta_id', $factura->cuenta_id)
            ->where('estado', EstadoAbono::Aplicado->value)
            ->whereIn('turno_id', TurnoDeCaja::query()
                ->where('estado', EstadoTurnoDeCaja::Abierto->value)
                ->select('id'))
            ->with('medios')
            ->orderBy('id')
            ->get();

        $opciones = [];

        foreach ($recibos as $recibo) {
            if (! Gate::allows('update', $recibo)) {
                continue;
            }

            $opciones[$recibo->id] = $recibo->numero
                .' · '.$recibo->medios->map(static fn (AbonoMedio $medio): string => $medio->descripcion())->implode(' + ')
                .' · L '.number_format((float) $recibo->total, 2)
                .' · '.$recibo->recibido_en->format('d/m H:i');
        }

        return $opciones;
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
