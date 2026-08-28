<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Tables;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuenta;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las cuentas, con las vivas arriba.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ TIENE QUE CONTESTAR ESTA PANTALLA DE UN VISTAZO
 * ─────────────────────────────────────────────────────────────────────
 *
 * De quién es la cuenta, cuánto suma, cuánto dejó la familia y cuánto
 * falta. Nueve columnas sueltas de números no contestan eso: por eso la
 * plata va en DOS GRUPOS con encabezado propio.
 *
 *   · «Cómo va» — Total, Abonado, Falta. Se lee de izquierda a derecha
 *     como una resta.
 *   · «Quién paga» — Paciente y Seguro. Es el reparto del total, no
 *     plata distinta.
 *
 * Antes las tres últimas columnas se llamaban «Total», «Paciente» y
 * «Aseguradora», con una columna «Paciente» de NOMBRES tres lugares a la
 * izquierda. Dos cosas distintas con el mismo encabezado en la misma
 * fila; no se entendía qué era cada número, y con razón.
 *
 * ⚠️ Igual que en el catálogo: TODA columna lleva `grow(false)` salvo el
 * nombre del paciente. Filament reparte el ancho sobrante en partes
 * iguales entre las que crecen, y con nueve columnas creciendo el número
 * de cuenta se partía en tres renglones mientras «Aseguradora» se caía
 * por el borde derecho.
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
            ->striped()
            /*
             * ⚠️ El parámetro se llama `$query`. Con cualquier otro
             * nombre Filament entrega un Builder vacío del contenedor y
             * el `withSum` se pierde EN SILENCIO: la columna «Abonado»
             * saldría en blanco y «Falta» diría que se debe todo, con la
             * familia enfrente. Hay una prueba de arquitectura que lo
             * vigila.
             */
            ->modifyQueryUsing(function (Builder $query): void {
                self::conLoAbonado($query);
            })
            ->columns([
                /*
                 * Los dos identificadores juntos: el de la cuenta arriba
                 * y el del encuentro abajo. Son la misma pregunta —«¿cuál
                 * es este papel?»— y el del encuentro le estaba comiendo
                 * tres renglones a la celda del paciente.
                 */
                TextColumn::make('numero')
                    ->label('Cuenta')
                    ->fontFamily(FontFamily::Mono)
                    ->weight('medium')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Número de cuenta copiado')
                    ->grow(false)
                    ->description(fn (Cuenta $record): string => $record->encuentro->numero),

                /*
                 * La única que crece, y debajo el porqué de la visita
                 * —hospitalización, consulta externa, emergencia—, que es
                 * lo que distingue dos cuentas del mismo paciente.
                 */
                TextColumn::make('encuentro.persona')
                    ->label('Paciente')
                    ->state(fn (Cuenta $record): string => $record->encuentro->persona->nombreCompleto())
                    ->description(fn (Cuenta $record): string => $record->encuentro->tipo->etiqueta())
                    ->weight('medium')
                    ->wrap()
                    ->lineClamp(2)
                    ->grow(),

                TextColumn::make('abierta_en')
                    ->label('Abierta')
                    ->date('d/m/Y')
                    ->sortable()
                    ->grow(false)
                    ->description(fn (Cuenta $record): string => $record->abierta_en->format('H:i')),

                /*
                 * Recortado con globo: «PAN-AMERICAN LIFE INSURANCE
                 * GROUP» entero hace la columna más ancha que el nombre
                 * del paciente, y lo que se necesita leer de un vistazo
                 * es de qué color es la insignia —particular, seguro,
                 * empresa— no el nombre legal completo.
                 */
                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->badge()
                    ->limit(22)
                    ->color(fn (Cuenta $record): string => $record->convenio->tipo->color())
                    ->tooltip(fn (Cuenta $record): string => $record->convenio->nombre)
                    ->sortable()
                    ->grow(false),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCuenta $state): string => $state->etiqueta())
                    ->color(fn (EstadoCuenta $state): string => $state->color())
                    ->grow(false),

                TextColumn::make('lineas')
                    ->label('Ítems')
                    ->alignEnd()
                    ->sortable()
                    ->grow(false)
                    ->tooltip('Cuántos renglones tiene cargados.')
                    ->toggleable(),

                /*
                 * La resta, en orden de lectura: cuánto suma, cuánto
                 * dejaron, cuánto falta.
                 */
                ColumnGroup::make('Cómo va', [
                    TextColumn::make('total')
                        ->label('Total')
                        ->alignEnd()
                        ->sortable()
                        ->weight('bold')
                        ->grow(false)
                        ->state(fn (Cuenta $record): string => $record->saldo()->formateado()),

                    /*
                     * Sale del `withSum` de arriba y no de
                     * `Cuenta::abonado()`: el método consulta por cuenta,
                     * y en una lista de cincuenta filas eso son cincuenta
                     * consultas que nadie ve.
                     */
                    TextColumn::make('abonos_aplicados')
                        ->label('Abonado')
                        ->alignEnd()
                        ->color('success')
                        ->placeholder('—')
                        ->grow(false)
                        ->tooltip('Suma de los abonos aplicados. Los anulados no cuentan.')
                        ->state(fn (Cuenta $record): ?string => self::abonadoFormateado($record)),

                    /*
                     * En insignia y no en texto plano: es el número por
                     * el que se abre esta pantalla. Verde = saldada, y
                     * celeste = pagaron de más, que es plata a devolver
                     * en el egreso, no un error.
                     */
                    TextColumn::make('falta')
                        ->label('Falta')
                        ->badge()
                        ->alignEnd()
                        ->grow(false)
                        ->tooltip('Total menos lo abonado. Es lo que se cobra en ventanilla.')
                        ->state(fn (Cuenta $record): string => self::comoQuedaLaCuenta($record))
                        ->color(fn (Cuenta $record): string => self::colorDeLoQueFalta($record)),
                ])->alignEnd(),

                /*
                 * El reparto del mismo total, no plata aparte. Sin
                 * aseguradora la segunda columna sale con raya y no con
                 * «L. 0.00», que es ruido en la mitad de las filas.
                 */
                ColumnGroup::make('Quién paga', [
                    TextColumn::make('total_paciente')
                        ->label('Paciente')
                        ->alignEnd()
                        ->grow(false)
                        ->toggleable()
                        ->state(fn (Cuenta $record): string => $record->saldoDelPaciente()->formateado()),

                    TextColumn::make('total_aseguradora')
                        ->label('Seguro')
                        ->alignEnd()
                        ->placeholder('—')
                        ->grow(false)
                        ->toggleable()
                        ->state(fn (Cuenta $record): ?string => $record->saldoDeLaAseguradora()->esCero()
                            ? null
                            : $record->saldoDeLaAseguradora()->formateado()),
                ])->alignEnd(),
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

                /*
                 * «¿A quién hay que cobrarle?» — la pregunta con la que
                 * se abre esta pantalla la mitad de las veces.
                 *
                 * ⚠️ `$query` otra vez, por lo mismo de arriba.
                 */
                TernaryFilter::make('con_saldo')
                    ->label('Saldo')
                    ->placeholder('Todas')
                    ->trueLabel('Solo las que deben')
                    ->falseLabel('Solo las saldadas')
                    ->queries(
                        true: function (Builder $query): void {
                            self::soloConSaldo($query);
                        },
                        false: function (Builder $query): void {
                            self::soloSaldadas($query);
                        },
                    ),
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

    /**
     * La suma de los abonos vivos, en la misma consulta de la lista.
     *
     * ⚠️ Solo los APLICADOS. Sumar también los anulados diría que la
     * familia pagó algo que ya se le devolvió.
     *
     * @param Builder<Cuenta> $consulta
     */
    private static function conLoAbonado(Builder $consulta): void
    {
        $consulta->withSum(
            ['abonos as abonos_aplicados' => static function (Builder $consultaDeAbonos): void {
                $consultaDeAbonos->where('estado', EstadoAbono::Aplicado->value);
            }],
            'total',
        );
    }

    /**
     * @param Builder<Cuenta> $consulta
     */
    private static function soloConSaldo(Builder $consulta): void
    {
        $consulta->whereRaw(
            'cuentas.total > coalesce((select sum(a.total) from abonos a '
            .'where a.cuenta_id = cuentas.id and a.estado = ?), 0)',
            [EstadoAbono::Aplicado->value],
        );
    }

    /**
     * @param Builder<Cuenta> $consulta
     */
    private static function soloSaldadas(Builder $consulta): void
    {
        $consulta->whereRaw(
            'cuentas.total <= coalesce((select sum(a.total) from abonos a '
            .'where a.cuenta_id = cuentas.id and a.estado = ?), 0)',
            [EstadoAbono::Aplicado->value],
        );
    }

    /**
     * Lo abonado, leído del agregado que puso `conLoAbonado()`.
     *
     * ⚠️ Se lee con `getAttribute()` y no como propiedad: `abonado` es
     * un MÉTODO del modelo, y el alias del agregado no tiene por qué
     * chocar con él. Si el agregado no está —porque alguien reusó esta
     * tabla sin el `modifyQueryUsing`— devuelve cero.
     */
    private static function abonadoDe(Cuenta $cuenta): Decimal
    {
        /** @var mixed $suma */
        $suma = $cuenta->getAttribute('abonos_aplicados');

        return is_numeric($suma) ? Decimal::de((string) $suma) : Decimal::cero();
    }

    private static function abonadoFormateado(Cuenta $cuenta): ?string
    {
        $abonado = self::abonadoDe($cuenta);

        return $abonado->esCero() ? null : Monto::de($abonado)->formateado();
    }

    /**
     * Lo que falta, en una frase: el número, «Saldada», o la plata que
     * hay que devolver.
     */
    private static function comoQuedaLaCuenta(Cuenta $cuenta): string
    {
        $falta = Decimal::de($cuenta->total)->restar(self::abonadoDe($cuenta));

        if ($falta->esCero()) {
            return 'Saldada';
        }

        if ($falta->esNegativo()) {
            return 'A favor '.Monto::de(Decimal::cero()->restar($falta))->formateado();
        }

        return Monto::de($falta)->formateado();
    }

    private static function colorDeLoQueFalta(Cuenta $cuenta): string
    {
        $falta = Decimal::de($cuenta->total)->restar(self::abonadoDe($cuenta));

        if ($falta->esCero()) {
            return 'success';
        }

        return $falta->esNegativo() ? 'info' : 'warning';
    }
}
