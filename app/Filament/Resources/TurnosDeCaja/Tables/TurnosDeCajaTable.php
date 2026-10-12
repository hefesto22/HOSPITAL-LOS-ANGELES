<?php

declare(strict_types=1);

namespace App\Filament\Resources\TurnosDeCaja\Tables;

use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Domain\ValueObjects\Decimal;
use App\Models\TurnoDeCaja;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TurnosDeCajaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            /*
             * ─────────────────────────────────────────────────────────
             * UN TURNO SE LEE COMO UN ARQUEO (12-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Quién, cuándo, y si cuadró. La diferencia ya no es un
             * «-20,00 HNL» que hay que interpretar: dice «Cuadró»,
             * «Faltan L 20.00» o «Sobran L 5.00». Las horas dicen
             * «Cerró 18:05» o «Sigue abierto», no «13:17 →».
             *
             * Abiertos / No cuadraron son pestañas en «ListTurnosDeCaja».
             */
            ->columns([
                TextColumn::make('nombre')
                    ->label('Turno')
                    ->state(fn (TurnoDeCaja $record): string => filled($record->nombre) ? (string) $record->nombre : $record->numero)
                    ->description(fn (TurnoDeCaja $record): ?string => filled($record->nombre) ? $record->numero : null)
                    ->weight('medium')
                    ->searchable(['numero', 'nombre']),

                TextColumn::make('usuario.name')
                    ->label('Cajero')
                    ->searchable(),

                TextColumn::make('abierto_en')
                    ->label('Abrió')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (TurnoDeCaja $record): string => self::cierre($record)),

                TextColumn::make('fondo_inicial')
                    ->label('Fondo')
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->alignEnd()
                    ->toggleable(),

                /*
                 * Esperado y contado se escriben al cerrar. Con el turno
                 * abierto todavía no existen: el esperado en vivo está
                 * en «Caja», que es donde se lo necesita.
                 */
                TextColumn::make('efectivo_esperado')
                    ->label('Tenía que haber')
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('efectivo_contado')
                    ->label('Se contó')
                    ->formatStateUsing(fn (mixed $state): string => self::lempiras($state))
                    ->alignEnd()
                    ->placeholder('—'),

                /*
                 * 🔴 LA COLUMNA QUE JUSTIFICA LA PANTALLA.
                 *
                 * Verde si cuadró, roja si falta, amarilla si sobra: un
                 * sobrante también es un error —casi siempre un vuelto
                 * mal dado— y esconderlo enseña a no reportarlo.
                 */
                TextColumn::make('diferencia')
                    ->label('Resultado')
                    ->badge()
                    ->formatStateUsing(fn (TurnoDeCaja $record): string => self::resultado($record))
                    ->color(fn (TurnoDeCaja $record): string => self::colorDelResultado($record))
                    ->placeholder('—')
                    ->description(fn (TurnoDeCaja $record): ?string => $record->notas_cierre === null
                        ? null
                        : Str::limit($record->notas_cierre, 40))
                    ->tooltip(fn (TurnoDeCaja $record): ?string => $record->notas_cierre),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoTurnoDeCaja $state): string => $state->etiqueta())
                    ->color(fn (EstadoTurnoDeCaja $state): string => $state->color()),
            ]);
    }

    /**
     * «Cerró 18:05», «Cerró el 10/09 a las 07:12» o «Sigue abierto».
     */
    private static function cierre(TurnoDeCaja $turno): string
    {
        if ($turno->cerrado_en === null) {
            return 'Sigue abierto';
        }

        return $turno->cerrado_en->isSameDay($turno->abierto_en)
            ? 'Cerró '.$turno->cerrado_en->format('H:i')
            : 'Cerró el '.$turno->cerrado_en->format('d/m').' a las '.$turno->cerrado_en->format('H:i');
    }

    private static function resultado(TurnoDeCaja $turno): string
    {
        if ($turno->diferencia === null) {
            return '—';
        }

        $diferencia = Decimal::de($turno->diferencia);

        if ($diferencia->esCero()) {
            return 'Cuadró';
        }

        $monto = $diferencia->esNegativo() ? Decimal::cero()->restar($diferencia) : $diferencia;

        return ($diferencia->esNegativo() ? 'Faltan ' : 'Sobran ').self::lempiras($monto->redondeado(2));
    }

    private static function colorDelResultado(TurnoDeCaja $turno): string
    {
        if ($turno->diferencia === null) {
            return 'gray';
        }

        $diferencia = Decimal::de($turno->diferencia);

        return match (true) {
            $diferencia->esCero()     => 'success',
            $diferencia->esNegativo() => 'danger',
            default                   => 'warning',
        };
    }

    /**
     * «L 1,250.00», como el resto del sistema (§8.6.2-6), y no el
     * «1.250,00 HNL» de `money('HNL')` con el idioma del panel.
     */
    private static function lempiras(mixed $monto): string
    {
        return 'L '.number_format(is_numeric($monto) ? (float) $monto : 0.0, 2);
    }
}
