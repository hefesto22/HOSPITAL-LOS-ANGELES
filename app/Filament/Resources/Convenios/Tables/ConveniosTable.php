<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Tables;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Filament\Pages\BasesDePrecios;
use App\Models\Convenio;
use App\Models\Tarifario;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de seguros y convenios.
 *
 * La columna del descuento de ley va primero después del nombre y a
 * propósito: es el dato que hay que poder leer de un vistazo cuando
 * alguien pregunta por qué una factura salió como salió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA COLUMNA «PRECIOS» ES LA QUE CIERRA EL CIRCUITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dar de alta un seguro sin cargarle precios es el error silencioso más
 * caro de este módulo: el convenio existe, se puede elegir en admisión,
 * y recién en el mostrador —a las once de la noche, con el paciente
 * enfrente— aparece «este ítem no tiene precio para este pagador».
 *
 * Un cero en esa columna hace visible ese hueco desde el listado, sin
 * entrar a ninguna ficha. Y el botón de al lado lleva directo a la
 * pantalla donde se arregla.
 *
 * ⚠️ El contado es la excepción y por eso tiene su propia lectura: al
 * paciente particular se le cobra el precio de lista del hospital, así
 * que un cero ahí NO es un hueco — es lo correcto. Pintarlo en rojo
 * mandaría a alguien a «arreglar» algo que no está roto, cargándole a
 * CONTADO una base propia que después se desincroniza de la lista.
 */
final class ConveniosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * 🔴 EL PARÁMETRO SE LLAMA `$query` Y NO ES CAPRICHO.
             *
             * Filament resuelve los argumentos de sus closures POR
             * NOMBRE. `applyQueryScopes()` inyecta `['query' => …]`; un
             * parámetro con cualquier otro nombre no lo encuentra, cae
             * al contenedor y recibe un `Builder` VACÍO — y entonces
             * esta subconsulta se le aplica a un objeto de descarte
             * mientras la tabla consulta sin ella. Sin error, sin log:
             * la columna sale en blanco y parece un problema de datos.
             */
            ->modifyQueryUsing(function (Builder $query): void {
                self::conElConteoDePrecios($query);
            })
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado'),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (TipoConvenio $state): string => $state->color())
                    ->formatStateUsing(fn (TipoConvenio $state): string => $state->etiqueta()),

                /*
                 * El estado llega de PostgreSQL como cadena —PDO
                 * devuelve los `bigint` así— y sin el cast el badge
                 * compararía '0' contra 0 y saldría del color
                 * equivocado.
                 */
                TextColumn::make('items_con_precio')
                    ->label('Precios')
                    ->alignCenter()
                    ->badge()
                    ->sortable()
                    ->default(0)
                    ->color(fn (Convenio $record, mixed $state): string => self::colorDeLosPrecios($record, $state))
                    ->formatStateUsing(fn (Convenio $record, mixed $state): string => self::etiquetaDeLosPrecios($record, $state))
                    ->tooltip(fn (Convenio $record, mixed $state): string => self::explicacionDeLosPrecios($record, $state)),

                TextColumn::make('base_descuento_legal')
                    ->label('Descuento Art. 30')
                    ->badge()
                    ->color(fn (BaseDelDescuentoLegal $state): string => $state->aplica() ? 'success' : 'gray')
                    ->formatStateUsing(fn (BaseDelDescuentoLegal $state): string => $state->etiqueta())
                    ->tooltip(fn (BaseDelDescuentoLegal $state): string => $state->explicacion())
                    ->wrap()
                    ->toggleable(),

                IconColumn::make('requiere_autorizacion')
                    ->label('Autoriza')
                    ->alignCenter()
                    ->boolean()
                    ->tooltip('Exige autorización previa antes de atender.')
                    ->toggleable(),

                /*
                 * El «días» se pega con `formatStateUsing` y no con
                 * `suffix()`: el sufijo se dibuja aunque no haya valor, y
                 * la fila del contado quedaría diciendo «días» sola.
                 */
                TextColumn::make('dias_credito')
                    ->label('Crédito')
                    ->alignEnd()
                    ->placeholder('Al momento')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null
                        ? null
                        : $state.' días')
                    ->toggleable(),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn (Convenio $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo de pagador')
                    ->options(fn (): array => collect(TipoConvenio::cases())
                        ->mapWithKeys(fn (TipoConvenio $tipo): array => [$tipo->value => $tipo->etiqueta()])
                        ->all()),

                Filter::make('vigentes')
                    ->label('Solo los vigentes hoy')
                    ->default()
                    ->query(function (Builder $query): void {
                        self::soloVigentes($query);
                    }),
            ])
            ->recordActions([
                /*
                 * `url` y no `action`: es navegación a otra pantalla, y
                 * un `Action` con cierre haría un viaje de Livewire para
                 * después redirigir igual.
                 *
                 * Al contado va a la lista y no a una base propia: su
                 * precio ES el de lista, y mandarlo a una base vacía
                 * invita a cargarla.
                 */
                Action::make('precios')
                    ->label('Precios')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->color('gray')
                    ->url(fn (Convenio $record): string => $record->esAlContado()
                        ? BasesDePrecios::getUrl()
                        : BasesDePrecios::getUrl(['base' => $record->id]))
                    ->visible(fn (): bool => BasesDePrecios::canAccess()),

                EditAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Todavía no hay seguros cargados')
            ->emptyStateDescription(
                'CONTADO se siembra solo. Las aseguradoras, el IHSS y los convenios '
                .'institucionales se cargan acá: al darlos de alta se les puede heredar '
                .'el catálogo completo con un porcentaje.'
            );
    }

    /**
     * Cuántos ítems tienen precio PROPIO vigente hoy para cada pagador.
     *
     * ─────────────────────────────────────────────────────────────────
     * SUBCONSULTA Y NO `withCount`
     * ─────────────────────────────────────────────────────────────────
     *
     * `withCount` necesitaría una relación `tarifarios` en el modelo, y
     * esa relación sería mentirosa: un tarifario también puede colgar de
     * una sede, y el conteo que interesa acá es el de la base general
     * —`sede_id` nulo— vigente HOY. Metido en la subconsulta, el filtro
     * se lee al lado del número que produce.
     *
     * `addSelect` con clave de texto agrega `convenios.*` solo, así que
     * no hay riesgo de perder las columnas del modelo.
     *
     * Es público a propósito: es la única pieza de esta clase con lógica
     * que puede estar mal —una subconsulta correlacionada mal escrita
     * cuenta de más y nadie lo nota— y una prueba tiene que poder
     * llamarla sin levantar Livewire.
     *
     * @param Builder<Convenio> $query
     */
    public static function conElConteoDePrecios(Builder $query): void
    {
        $query->addSelect(['items_con_precio' => Tarifario::query()
            ->selectRaw('count(*)')
            ->whereColumn('tarifarios.convenio_id', 'convenios.id')
            ->whereNull('tarifarios.sede_id')
            ->vigentesEn(now()),
        ]);
    }

    private static function colorDeLosPrecios(Convenio $record, mixed $state): string
    {
        if ((int) $state > 0) {
            return 'success';
        }

        return $record->esAlContado() ? 'gray' : 'danger';
    }

    private static function etiquetaDeLosPrecios(Convenio $record, mixed $state): string
    {
        if ((int) $state > 0) {
            return (int) $state.' ítems';
        }

        return $record->esAlContado() ? 'Precio de lista' : 'Sin precios';
    }

    private static function explicacionDeLosPrecios(Convenio $record, mixed $state): string
    {
        if ((int) $state > 0) {
            return 'Ítems con precio propio vigente hoy.';
        }

        return $record->esAlContado()
            ? 'Al paciente particular se le cobra el precio de lista del hospital. No lleva base propia.'
            : 'Este pagador no tiene ningún precio propio: se le cobra el precio de lista.';
    }

    /**
     * La condición vive en un método propio y no dentro del cierre por la
     * misma razón que en `ItemsTable`: el cierre recibe un `Builder` sin
     * genérico, y encadenarle un scope del modelo ahí adentro es lo que
     * PHPStan no puede verificar.
     *
     * @param Builder<Convenio> $query
     */
    private static function soloVigentes(Builder $query): void
    {
        $query->vigentesEn(now());
    }
}
