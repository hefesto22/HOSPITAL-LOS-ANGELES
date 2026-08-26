<?php

declare(strict_types=1);

namespace App\Filament\Resources\Descuentos\Tables;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Models\Descuento;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los descuentos, agrupados por nombre.
 *
 * Se agrupa por nombre y no por fecha a propósito: las dos filas de
 * «Tercera edad» —la de enero al 25 % y la de julio al 30 %— son el
 * mismo descuento y tienen que verse juntas. Verlas separadas es lo que
 * hace creer que hay dos.
 */
final class DescuentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->weight('bold')
                    ->searchable()
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
                    ->formatStateUsing(fn (Descuento $record): string => $record->comoPorcentaje())
                    ->sortable(),

                TextColumn::make('aplica_a')
                    ->label('A quién')
                    ->badge()
                    ->color(fn (AplicacionDeDescuento $state): string => $state->color())
                    ->formatStateUsing(fn (AplicacionDeDescuento $state): string => $state->etiquetaConTramo())
                    ->description(fn (Descuento $record): ?string => $record->aplica_a->esAutomatico()
                        ? null
                        : 'No se aplica solo'),

                /*
                 * 🔴 Se cuenta por NOMBRE y no con `counts('items')`.
                 *
                 * `counts()` contaría las filas del pivote que apuntan a
                 * ESTE `id`, y una fila recién creada no tiene ninguna:
                 * diría «0 ítems» justo después de cambiar un porcentaje,
                 * haciendo creer que el cambio no le llegó a nadie.
                 * Le llega a todos —el motor de cargos busca por nombre—
                 * y este número tiene que decir lo mismo que cobra el
                 * sistema.
                 *
                 * Cuesta una consulta por fila. Es aceptable acá: esta
                 * tabla tiene tantas filas como descuentos tenga el
                 * hospital, y son un puñado.
                 */
                TextColumn::make('items_marcados')
                    ->label('Ítems')
                    ->alignEnd()
                    ->getStateUsing(fn (Descuento $record): int => $record->cuantosItemsLoTienen())
                    ->tooltip('Cuántos ítems del catálogo tienen marcado un descuento con este nombre.')
                    ->toggleable(),

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
                    ->color(fn (Descuento $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),

                TextColumn::make('nota')
                    ->label('Nota')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultGroup('nombre')
            ->defaultSort('vigencia_desde', 'desc')
            ->paginated([25, 50, 100])
            ->filters([
                SelectFilter::make('aplica_a')
                    ->label('A quién se aplica')
                    ->options(fn (): array => collect(AplicacionDeDescuento::cases())
                        ->mapWithKeys(fn (AplicacionDeDescuento $a): array => [$a->value => $a->etiqueta()])
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
            ->emptyStateHeading('Todavía no hay descuentos')
            ->emptyStateDescription(
                'Creá los que el hospital da con nombre propio —«Tercera edad», «Cuarta edad», '
                .'«Empleado del hospital»— y después marcalos en cada ítem del catálogo. '
                .'Mientras no haya ninguno, el sistema sigue aplicando solo lo del Artículo 30.'
            );
    }

    /**
     * 🔴 El primer parámetro se llama `$query` y no se puede llamar de
     * otra forma: Filament resuelve los argumentos de los closures POR
     * NOMBRE. Con otro nombre llega un Builder vacío del contenedor y el
     * filtro no filtra — sin excepción y sin log.
     *
     * La condición vive en un método propio porque el closure recibe un
     * `Builder` sin genérico, y encadenarle un scope del modelo ahí
     * adentro es lo que PHPStan no puede verificar.
     *
     * @param Builder<Descuento> $query
     */
    private static function soloVigentes(Builder $query): void
    {
        $query->vigentesEn(now());
    }
}
