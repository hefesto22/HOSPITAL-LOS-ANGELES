<?php

declare(strict_types=1);

namespace App\Filament\Resources\DescuentosLegales\Tables;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Models\DescuentoLegal;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los porcentajes vigentes, agrupados por categoría.
 *
 * Se agrupa por categoría y no por edad a propósito: la pregunta que se
 * hace quien mira esta pantalla es «¿cuánto le descuento a un adulto
 * mayor en una cirugía?», no «¿qué le toca a la cuarta edad?». Y así, un
 * grupo con una sola fila grita que a esa categoría le falta la otra
 * edad.
 */
final class DescuentosLegalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('categoria_legal')
                    ->label('Categoría')
                    ->wrap()
                    ->formatStateUsing(fn (CategoriaLegalDeDescuento $state): string => $state->etiqueta())
                    ->description(fn (DescuentoLegal $record): ?string => $record->categoria_legal->numeral()),

                TextColumn::make('rango_edad')
                    ->label('Edad')
                    ->badge()
                    ->color(fn (RangoEdad $state): string => $state->color())
                    ->formatStateUsing(fn (RangoEdad $state): string => $state->etiqueta())
                    ->sortable(),

                /*
                 * Guardado como fracción y mostrado como porcentaje. Al
                 * revés —guardar 25 y dividir al usar— es donde alguien
                 * multiplica por 25 en vez de por 0.25 y le descuenta al
                 * paciente veinticinco veces el precio.
                 */
                TextColumn::make('porcentaje')
                    ->label('Descuento')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn (DescuentoLegal $record): string => $record
                        ->fraccion()
                        ->por('100')
                        ->redondeado(2).' %')
                    ->sortable(),

                IconColumn::make('exige_receta')
                    ->label('Receta')
                    ->alignCenter()
                    ->boolean()
                    ->tooltip('Art. 34: el descuento en medicamentos exige receta original firmada y sellada.')
                    ->toggleable(),

                TextColumn::make('vigencia_desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('vigencia_hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn (DescuentoLegal $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),

                TextColumn::make('fundamento')
                    ->label('De dónde sale')
                    ->wrap()
                    ->tooltip(fn (DescuentoLegal $record): ?string => $record->nota),
            ])
            /*
             * ⚠️ EL GRUPO HAY QUE DECLARARLO, NO ALCANZA CON NOMBRARLO
             *
             * `defaultGroup('categoria_legal')` a secas hace que Filament
             * arme el título del grupo con el VALOR de la columna, y esa
             * columna está casteada a `CategoriaLegalDeDescuento`. El
             * resolvedor exige `Htmlable|string|null` y recibe un enum:
             * TypeError y pantalla en 500.
             *
             * No se veía con la tabla vacía —sin filas no hay título que
             * resolver— y apareció recién cuando se sembraron los
             * descuentos de ley.
             */
            ->groups([
                Group::make('categoria_legal')
                    ->label('Categoría')
                    ->getTitleFromRecordUsing(
                        fn (DescuentoLegal $record): string => $record->categoria_legal->etiqueta(),
                    )
                    ->collapsible(),
            ])
            ->defaultGroup('categoria_legal')
            ->defaultSort('vigencia_desde', 'desc')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('rango_edad')
                    ->label('Edad')
                    ->options(fn (): array => collect(RangoEdad::conDerechoADescuento())
                        ->mapWithKeys(fn (RangoEdad $rango): array => [$rango->value => $rango->etiqueta()])
                        ->all()),

                SelectFilter::make('categoria_legal')
                    ->label('Categoría')
                    ->options(fn (): array => collect(CategoriaLegalDeDescuento::cases())
                        ->mapWithKeys(fn (CategoriaLegalDeDescuento $categoria): array => [
                            $categoria->value => $categoria->etiqueta(),
                        ])
                        ->all()),

                Filter::make('vigentes')
                    ->label('Solo los vigentes hoy')
                    ->default()
                    ->query(function (Builder $query): void {
                        self::soloVigentes($query);
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Sin porcentajes cargados')
            ->emptyStateDescription(
                'Sin al menos una fila por categoría, el descuento de ley resuelve en cero y el '
                .'hospital le cobra de más a cada adulto mayor que atienda.'
            );
    }

    /**
     * La condición vive en un método propio y no dentro del cierre: el
     * cierre recibe un `Builder` sin genérico, y encadenarle un scope del
     * modelo ahí adentro es lo que PHPStan no puede verificar.
     *
     * @param Builder<DescuentoLegal> $query
     */
    private static function soloVigentes(Builder $query): void
    {
        $query->vigentesEn(now());
    }
}
