<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Tables;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\TipoDocumentoFiscal;
use App\Models\Compra;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El gasto del hospital, mes a mes.
 *
 * La columna del ISV muestra **cero en los recibos** aunque la fila tenga
 * el monto en otra parte: es el impuesto ACREDITABLE, que es lo que se
 * lleva a la declaración. Sumar el ISV de los recibos sería reclamar un
 * crédito que no existe.
 */
final class ComprasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha_compra')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('numero_documento')
                    ->label('Documento')
                    ->placeholder('sin número')
                    ->description(fn (Compra $record): string => $record->tipo_documento->etiqueta())
                    ->searchable()
                    ->copyable(),

                TextColumn::make('tipo_documento')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (TipoDocumentoFiscal $state): string => $state->color())
                    ->formatStateUsing(fn (TipoDocumentoFiscal $state): string => $state->etiqueta())
                    ->tooltip(fn (TipoDocumentoFiscal $state): string => $state->explicacion())
                    ->toggleable(),

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categoria_gasto')
                    ->label('En qué')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (CategoriaDeGasto $state): string => $state->etiqueta()),

                TextColumn::make('gravado_quince')
                    ->label('Gravado')
                    ->money('HNL')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * El estado de la columna se reemplaza por el acreditable
                 * y no se lee la columna cruda: en un recibo la base ya
                 * guarda cero, pero pasar por el método deja escrito EN
                 * LA PANTALLA cuál de los dos números es el que cuenta.
                 */
                TextColumn::make('isv')
                    ->label('ISV acreditable')
                    ->alignEnd()
                    ->money('HNL')
                    ->state(fn (Compra $record): string => $record->isvAcreditable()->redondeado(2))
                    ->summarize(Sum::make()->label('ISV del período')->money('HNL')),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Gastado')->money('HNL')),
            ])
            ->filters([
                SelectFilter::make('tipo_documento')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoDocumentoFiscal::cases())
                        ->mapWithKeys(fn (TipoDocumentoFiscal $tipo): array => [
                            $tipo->value => $tipo->etiqueta(),
                        ])
                        ->all()),

                SelectFilter::make('categoria_gasto')
                    ->label('En qué se gastó')
                    ->multiple()
                    ->options(fn (): array => collect(CategoriaDeGasto::cases())
                        ->mapWithKeys(fn (CategoriaDeGasto $categoria): array => [
                            $categoria->value => $categoria->etiqueta(),
                        ])
                        ->all()),

                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                /*
                 * El mes en curso por defecto: es el período de la
                 * declaración, y abrir la pantalla con dos años de
                 * compras a la vista no le sirve a nadie.
                 */
                Filter::make('este_mes')
                    ->label('Solo el mes en curso')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereBetween(
                        'fecha_compra',
                        [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
                    )),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('fecha_compra', 'desc')
            ->emptyStateHeading('Todavía no hay compras registradas este mes')
            ->emptyStateDescription(
                'Acá va el papel: factura o recibo, en qué se gastó y cuánto. La mercadería que '
                .'entra al estante se registra en Recepciones.'
            );
    }
}
