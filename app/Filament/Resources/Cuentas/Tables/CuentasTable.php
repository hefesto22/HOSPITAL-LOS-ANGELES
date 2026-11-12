<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Tables;

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Cuentas\CuentaResource;
use App\Filament\Resources\Cuentas\Pages\ListCuentas;
use App\Models\Cuenta;
use App\Models\Persona;
use App\Support\BitacoraDeLectura;
use App\Support\FichaDeLaCuenta;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las cuentas, para consultar: de quién es, en qué etapa va y cuánto falta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REDISEÑO (12-oct-2026) — «que sea más fácil y entendible»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Antes eran once columnas con un panel de filtros encima: la tabla no
 * cabía, el nombre del paciente se cortaba en «RAMON ENRIQUE…» y la
 * columna «Quién paga» se caía por el borde derecho. Ahora:
 *
 *   · Las ETAPAS van en pestañas arriba (ver `ListCuentas`): en curso,
 *     por cobrar, facturadas, anuladas y todas. Reemplazan al interruptor
 *     «Solo las abiertas» y a los desplegables de estado y de saldo.
 *   · Siete columnas: paciente, cuenta, pagador, estado, total, abonado
 *     y falta. El reparto con el seguro va debajo del total, y solo
 *     cuando hay seguro — en la mitad de las filas era un «—».
 *   · El estado se dice como lo dice el mostrador: «Facturada» y no
 *     «Cerrada», «En egreso» y no «Congelada (cutoff de egreso)».
 *   · Se busca por nombre del paciente, no solo por número de cuenta.
 *   · En el celular quedan paciente, total y falta; lo demás aparece a
 *     medida que la pantalla da ancho.
 *
 * ⚠️ El COSTO y el MARGEN no salen acá. §9.L13: son un permiso, no una
 * columna, y se chequean en el Resource, en la tabla, en el export y en
 * el PDF — los cuatro.
 */
final class CuentasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Por fecha de apertura aunque no haya columna con ese nombre:
             * la fecha va debajo del número de cuenta.
             */
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc($query->qualifyColumn('abierta_en'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->striped()
            /*
             * ⚠️ El parámetro se llama `$query`. Con cualquier otro
             * nombre Filament entrega un Builder vacío del contenedor y
             * el `withSum` se pierde EN SILENCIO: «Abonado» saldría en
             * blanco y «Falta» diría que se debe todo, con la familia
             * enfrente. Hay una prueba de arquitectura que lo vigila.
             */
            ->modifyQueryUsing(function (Builder $query): void {
                self::conLoAbonado($query);
            })
            ->searchPlaceholder('Paciente o número de cuenta')
            ->columns([
                /*
                 * La única que crece. El ícono y la línea de abajo dicen
                 * a qué vino —hospitalización, emergencia, consulta—,
                 * que es lo que distingue dos cuentas del mismo paciente.
                 */
                TextColumn::make('encuentro.persona')
                    ->label('Paciente')
                    ->state(fn (Cuenta $record): string => $record->encuentro->persona->nombreCompleto())
                    ->description(fn (Cuenta $record): string => $record->encuentro->tipo->etiqueta())
                    ->icon(fn (Cuenta $record): string => $record->encuentro->tipo->icono())
                    ->iconColor(fn (Cuenta $record): string => $record->encuentro->tipo->color())
                    ->weight('semibold')
                    ->wrap()
                    ->grow()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where($query->qualifyColumn('numero'), 'ilike', '%'.$search.'%')
                        ->orWhereHas('encuentro', fn (Builder $encuentro): Builder => $encuentro
                            ->where($encuentro->qualifyColumn('numero'), 'ilike', '%'.$search.'%')
                            ->orWhereIn(
                                $encuentro->qualifyColumn('persona_id'),
                                Persona::query()->buscarPorNombre($search)->select('id'),
                            ))),

                /*
                 * El número para copiar y, debajo, cuándo se abrió.
                 * Ordena por la fecha: el número de cuenta es correlativo
                 * por sede, la fecha no miente.
                 */
                TextColumn::make('numero')
                    ->label('Cuenta')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Número de cuenta copiado')
                    ->description(fn (Cuenta $record): string => $record->abierta_en->format('d/m/Y · H:i'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy($query->qualifyColumn('abierta_en'), $direction === 'desc' ? 'desc' : 'asc'))
                    ->grow(false)
                    ->visibleFrom('md'),

                /*
                 * Recortado con globo: lo que se lee de un vistazo es el
                 * color —contado, seguro, institución—, no el nombre
                 * legal completo de la aseguradora.
                 *
                 * Sin `sortable()`: ordenar por una relación hace un JOIN
                 * con `convenios`, y ahí `estado` y `nombre` dejan de ser
                 * nombres únicos.
                 */
                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->badge()
                    ->limit(24)
                    ->color(fn (Cuenta $record): string => $record->convenio->tipo->color())
                    ->tooltip(fn (Cuenta $record): string => $record->convenio->nombre)
                    ->grow(false)
                    ->visibleFrom('lg'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCuenta $state): string => FichaDeLaCuenta::estadoEnPalabras($state))
                    ->icon(fn (EstadoCuenta $state): string => FichaDeLaCuenta::iconoDelEstado($state))
                    ->color(fn (EstadoCuenta $state): string => $state->color())
                    /*
                     * La anulada dice POR QUÉ (21-oct-2026): «se anuló» solo
                     * no le sirve a quien la encuentra una semana después.
                     */
                    ->tooltip(fn (Cuenta $record): string => $record->estado === EstadoCuenta::Anulada
                        && filled($record->motivo_anulacion)
                            ? 'Se anuló: '.$record->motivo_anulacion
                            : FichaDeLaCuenta::queQuiereDecir($record->estado))
                    ->grow(false)
                    ->visibleFrom('sm'),

                /*
                 * Con seguro, debajo va la parte del seguro: el total es
                 * lo que se le dice al cliente (ADR-0008) y el reparto lo
                 * arreglan después. Sin seguro no hay línea de abajo.
                 */
                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    ->state(fn (Cuenta $record): string => $record->saldo()->formateado())
                    ->description(fn (Cuenta $record): ?string => self::loDelSeguro($record))
                    ->grow(false),

                /*
                 * Sale del `withSum` de arriba y no de `Cuenta::abonado()`:
                 * el método consulta por cuenta, y en una lista de
                 * cincuenta filas eso son cincuenta consultas (§13.2).
                 */
                TextColumn::make('abonos_aplicados')
                    ->label('Abonado')
                    ->alignEnd()
                    ->color('success')
                    ->placeholder('—')
                    ->tooltip('Lo que ya se dejó en caja. Los recibos anulados no cuentan.')
                    ->state(fn (Cuenta $record): ?string => self::abonadoFormateado($record))
                    ->grow(false)
                    ->visibleFrom('md'),

                /*
                 * En insignia: es el número por el que se abre esta
                 * pantalla. Ámbar = se debe, verde = saldada, celeste =
                 * pagaron de más (plata a devolver en el egreso).
                 */
                TextColumn::make('falta')
                    ->label('Falta')
                    ->badge()
                    ->alignEnd()
                    ->placeholder('—')
                    ->tooltip('Total menos lo abonado.')
                    ->state(fn (Cuenta $record): ?string => self::comoQuedaLaCuenta($record))
                    ->color(fn (Cuenta $record): string => self::colorDeLoQueFalta($record))
                    ->grow(false),

                /*
                 * Para auditoría, escondidas: se prenden desde el botón
                 * de columnas.
                 */
                TextColumn::make('encuentro.numero')
                    ->label('Encuentro')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('lineas')
                    ->label('Renglones')
                    ->alignEnd()
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Filtros chicos, en el botón de al lado del buscador y
             * aplicados al elegir: las etapas ya están en las pestañas.
             */
            ->filters([
                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->relationship('convenio', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('tipo')
                    ->label('A qué vino')
                    ->options(fn (): array => collect(TipoEncuentro::cases())
                        ->mapWithKeys(fn (TipoEncuentro $tipo): array => [$tipo->value => $tipo->etiqueta()])
                        ->all())
                    /*
                     * ⚠️ `$query` otra vez, por lo mismo de arriba.
                     */
                    ->query(function (Builder $query, array $data): void {
                        self::soloDelTipo($query, $data['value'] ?? null);
                    }),
            ])
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filtros'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (HasTable $livewire): string => self::vacioDe($livewire))
            ->emptyStateDescription('Si buscaste algo, probá con el apellido o con el número de cuenta.')
            /*
             * «Bitácora» abre al costado lo que pasó en la cuenta, sin
             * salir de la lista (Mauricio, 11-nov-2026: «que tenga un
             * acceso a ver bitácora»). «Ver» lleva a la ficha entera.
             */
            ->recordActions([
                Action::make('bitacora')
                    ->label('Bitácora')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->modalHeading(fn (Cuenta $record): string => 'Bitácora · '.$record->numero)
                    ->modalDescription(fn (Cuenta $record): string => $record->encuentro->persona->nombreCompleto())
                    ->visible(fn (Cuenta $record): bool => CuentaResource::canView($record))
                    ->mountUsing(function (Cuenta $record): void {
                        self::registrarQueSeAbrioLaBitacora($record);
                    })
                    ->modalContent(fn (Cuenta $record): View => view('filament.resources.cuentas.bitacora-al-costado', [
                        'cuenta' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),

                ViewAction::make()->label('Ver'),
            ])
            /*
             * Sin bulk actions destructivas (§9.A17). Un borrado masivo
             * sobre cuentas es un incidente del que no se vuelve.
             */
            ->toolbarActions([]);
    }

    // ── Las etapas (las usan las pestañas de `ListCuentas`) ───────────

    /**
     * Lo que todavía no se facturó: abiertas y en egreso.
     *
     * ⚠️ Incluye las CONGELADAS: siguen vivas —admiten cargos tardíos y
     * todavía se facturan—. En el mostrador «en curso» quiere decir
     * «todavía no se cerró».
     *
     * @param Builder<Cuenta> $consulta
     *
     * @return Builder<Cuenta>
     */
    public static function soloEnCurso(Builder $consulta): Builder
    {
        return $consulta->whereIn($consulta->qualifyColumn('estado'), EstadoCuenta::valoresVivos());
    }

    /**
     * Las que deben plata: el total es más que lo abonado. Abiertas o ya
     * facturadas —lo del seguro queda debiéndose después de facturar—;
     * las anuladas no cuentan.
     *
     * @param Builder<Cuenta> $consulta
     *
     * @return Builder<Cuenta>
     */
    public static function soloPorCobrar(Builder $consulta): Builder
    {
        return $consulta
            ->where($consulta->qualifyColumn('estado'), '<>', EstadoCuenta::Anulada->value)
            ->whereRaw(
                'cuentas.total > coalesce((select sum(a.total) from abonos a '
                .'where a.cuenta_id = cuentas.id and a.estado = ?), 0)',
                [EstadoAbono::Aplicado->value],
            );
    }

    /**
     * @param Builder<Cuenta> $consulta
     *
     * @return Builder<Cuenta>
     */
    public static function soloEnEstado(Builder $consulta, EstadoCuenta $estado): Builder
    {
        return $consulta->where($consulta->qualifyColumn('estado'), $estado->value);
    }

    // ── Consultas de la tabla ─────────────────────────────────────────

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
    private static function soloDelTipo(Builder $consulta, mixed $tipo): void
    {
        if (! is_string($tipo) || TipoEncuentro::tryFrom($tipo) === null) {
            return;
        }

        $consulta->whereHas('encuentro', static function (Builder $encuentro) use ($tipo): void {
            $encuentro->where($encuentro->qualifyColumn('tipo'), $tipo);
        });
    }

    // ── Cómo se dice cada cosa ────────────────────────────────────────

    private static function loDelSeguro(Cuenta $cuenta): ?string
    {
        $seguro = $cuenta->saldoDeLaAseguradora();

        return $seguro->esCero() ? null : 'Seguro paga '.$seguro->formateado();
    }

    /**
     * Lo abonado, leído del agregado que puso `conLoAbonado()`.
     *
     * ⚠️ Se lee con `getAttribute()` y no como propiedad: `abonado` es
     * un MÉTODO del modelo. Si el agregado no está —porque alguien reusó
     * esta tabla sin el `modifyQueryUsing`— devuelve cero.
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
     * Lo que falta, en una frase. La regla vive en `FichaDeLaCuenta`: la
     * lista y la ficha de la cuenta dicen lo mismo con las mismas palabras.
     */
    private static function comoQuedaLaCuenta(Cuenta $cuenta): ?string
    {
        return FichaDeLaCuenta::loQueFalta($cuenta->estado, Decimal::de($cuenta->total), self::abonadoDe($cuenta));
    }

    private static function colorDeLoQueFalta(Cuenta $cuenta): string
    {
        return FichaDeLaCuenta::colorDeLoQueFalta($cuenta->estado, Decimal::de($cuenta->total), self::abonadoDe($cuenta));
    }

    private static function vacioDe(HasTable $livewire): string
    {
        $pestana = $livewire instanceof ListCuentas ? $livewire->activeTab : null;

        return match ($pestana) {
            'en_curso'   => 'No hay cuentas en curso',
            'por_cobrar' => 'Nadie debe nada',
            'facturadas' => 'Todavía no hay cuentas facturadas',
            'anuladas'   => 'No hay cuentas anuladas',
            default      => 'No hay cuentas',
        };
    }

    // ── La bitácora al costado ────────────────────────────────────────

    /**
     * Abrir la bitácora queda en la bitácora de LECTURA (§9.L6): dice qué
     * se le hizo a un paciente y quién pagó, igual que la ficha.
     *
     * Se llama al montar la acción —una vez por apertura— y no al pintar
     * el contenido, que Livewire puede pintar más de una vez.
     */
    public static function registrarQueSeAbrioLaBitacora(Cuenta $cuenta): void
    {
        BitacoraDeLectura::registrar(
            recursoTipo: Cuenta::class,
            recursoId: $cuenta->getKey(),
            pacienteId: $cuenta->encuentro->persona_id,
            accion: AccionDeLectura::Ver,
            motivoTexto: 'BITÁCORA DE LA CUENTA',
        );
    }
}
