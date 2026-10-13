<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos\Tables;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Exceptions\SihlaException;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Abono;
use App\Models\Persona;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeTurnoDeCaja;
use App\Services\ReceptorDeAbono;
use App\Support\UsuarioAutenticado;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AbonosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            /*
             * ─────────────────────────────────────────────────────────
             * SE LEE COMO UN RECIBO (12-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Cuándo, quién pagó, cómo y cuánto — en ese orden. Antes la
             * columna «Cuenta» decía CTA-HLA-2026-000006, que nadie
             * reconoce: ahora va el nombre del paciente y la cuenta
             * debajo. El número de recibo dejó de ser una insignia
             * naranja (todo parecía una alerta) y el motivo de anulación
             * ya no empuja la tabla fuera de la pantalla: va recortado,
             * con el texto entero al pasar el cursor.
             *
             * El turno y quién lo recibió son preguntas de auditoría:
             * siguen a un clic en el selector de columnas.
             *
             * Vigentes / Hoy / Anulados son pestañas en «ListAbonos», no
             * filtros escondidos detrás de un botón.
             */
            ->columns([
                TextColumn::make('fecha_operacion')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Abono $record): string => $record->recibido_en->format('H:i')),

                TextColumn::make('numero')
                    ->label('Recibo')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    ->state(fn (Abono $record): string => $record->cuenta->encuentro->persona->nombreCompleto())
                    ->description(fn (Abono $record): string => $record->cuenta->numero
                        .(filled($record->entregado_por) ? ' · lo dejó '.$record->entregado_por : ''))
                    ->weight('medium')
                    ->wrap()
                    /*
                     * Por nombre (con el mismo parecido que usa admisión:
                     * «Hernandes» encuentra a «Hernández») o por número
                     * de cuenta.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('cuenta', fn (Builder $cuenta): Builder => $cuenta->where('numero', 'ilike', '%'.$search.'%'))
                        ->orWhereHas('cuenta.encuentro', fn (Builder $encuentro): Builder => $encuentro->whereIn(
                            'persona_id',
                            Persona::query()->buscarPorNombre($search)->select('id'),
                        ))),

                /*
                 * Con qué se pagó. Es lo primero que se busca cuando el
                 * banco no muestra un depósito que el sistema sí tiene.
                 */
                TextColumn::make('medios')
                    ->label('Cómo pagó')
                    ->wrap()
                    ->state(fn (Abono $record): string => $record->resumenDeMedios()),

                TextColumn::make('total')
                    ->label('Monto')
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoAbono $state): string => $state->etiqueta())
                    ->color(fn (EstadoAbono $state): string => $state->color())
                    ->description(fn (Abono $record): ?string => $record->motivo_anulacion === null
                        ? null
                        : Str::limit($record->motivo_anulacion, 34))
                    ->tooltip(fn (Abono $record): ?string => $record->motivo_anulacion),

                TextColumn::make('turno.nombre')
                    ->label('Turno')
                    ->state(fn (Abono $record): string => filled($record->turno->nombre)
                        ? (string) $record->turno->nombre
                        : $record->turno->numero)
                    ->description(fn (Abono $record): ?string => filled($record->turno->nombre) ? $record->turno->numero : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('recibidoPor.name')
                    ->label('Recibió')
                    ->toggleable(isToggledHiddenByDefault: true),
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
             * Tampoco aparece a quien no trabaja en ese turno (13-oct-2026):
             * anular saca plata de un arqueo que cuenta otra persona.
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
                        && self::trabajaEn($record->turno)
                        && Gate::allows('update', $record))
                    ->schema([
                        CampoMayusculas::area('motivo')
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

    /**
     * «L 1,250.00», como el resto del sistema (§8.6.2-6). `money('HNL')`
     * con el idioma del panel daba «1.250,00 HNL», que en el mostrador se
     * lee como mil doscientos cincuenta centavos.
     *
     * Presentación nada más: el valor ya viene redondeado de la base.
     */
    private static function lempiras(mixed $monto): string
    {
        return 'L '.number_format(is_numeric($monto) ? (float) $monto : 0.0, 2);
    }

    /**
     * Si quien mira trabaja en ese turno: lo abrió, es de ese turno o es
     * de la administración.
     */
    private static function trabajaEn(TurnoDeCaja $turno): bool
    {
        $usuario = Auth::user();

        return $usuario instanceof User && app(AbridorDeTurnoDeCaja::class)->puedeOperar($usuario, $turno);
    }
}
