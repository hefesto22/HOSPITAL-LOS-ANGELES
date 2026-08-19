<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Tables;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Models\Convenio;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de pagadores.
 *
 * La columna del descuento de ley va primero después del nombre y a
 * propósito: es el dato que hay que poder leer de un vistazo cuando
 * alguien pregunta por qué una factura salió como salió.
 */
final class ConveniosTable
{
    public static function configure(Table $table): Table
    {
        return $table
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

                TextColumn::make('base_descuento_legal')
                    ->label('Descuento Art. 30')
                    ->badge()
                    ->color(fn (BaseDelDescuentoLegal $state): string => $state->aplica() ? 'success' : 'gray')
                    ->formatStateUsing(fn (BaseDelDescuentoLegal $state): string => $state->etiqueta())
                    ->tooltip(fn (BaseDelDescuentoLegal $state): string => $state->explicacion())
                    ->wrap(),

                IconColumn::make('requiere_autorizacion')
                    ->label('Autoriza')
                    ->alignCenter()
                    ->boolean()
                    ->tooltip('Exige autorización previa antes de atender.'),

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
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Todavía no hay convenios')
            ->emptyStateDescription(
                'CONTADO se siembra solo. Las aseguradoras, el IHSS y los convenios '
                .'institucionales se cargan acá, uno por uno.'
            );
    }

    /**
     * La condición vive en un método propio y no dentro del cierre por la
     * misma razón que en `ItemsTable`: el cierre recibe un `Builder` sin
     * genérico, y encadenarle un scope del modelo ahí adentro es lo que
     * PHPStan no puede verificar.
     *
     * @param Builder<Convenio> $consulta
     */
    private static function soloVigentes(Builder $consulta): void
    {
        $consulta->vigentesEn(now());
    }
}
