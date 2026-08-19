<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\RelationManagers;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Convenio;
use App\Models\ConvenioCondicion;
use App\Services\FijadorDeCondicion;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Lo pactado con este convenio, renovación por renovación.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE ESCRIBE LO QUE PAGA, NO LO QUE DESCUENTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El campo pide **qué porcentaje de la lista paga el convenio**: 85 para
 * «lista menos 15 %». Se escribe así y no como descuento porque el día
 * que un convenio pague por encima de la lista —pasa cuando el tarifario
 * institucional es más alto— el descuento tendría que ser negativo, y un
 * «−10 %» de descuento es una frase que nadie lee bien a la primera. El
 * ayudante debajo del campo muestra la resta en vivo, que es como lo
 * piensa quien negocia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ NO SE EDITA: SE PACTA DE NUEVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Sin botón de editar ni de borrar. Cambiar el porcentaje es cerrar el
 * vigente y abrir uno nuevo con fecha; sobreescribirlo dejaría las
 * facturas de la renovación anterior sin explicación.
 */
class CondicionesRelationManager extends RelationManager
{
    protected static string $relationship = 'condiciones';

    protected static ?string $title = 'Porcentaje pactado';

    protected static function getModelLabel(): ?string
    {
        return 'condición';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'condiciones';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('factor_sobre_lista')
                    ->label('Sobre el precio de lista')
                    ->weight('bold')
                    ->formatStateUsing(fn (ConvenioCondicion $record): string => $record->resumen()),

                TextColumn::make('vigencia_desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('vigencia_hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn (ConvenioCondicion $record): string => $record->vigencia_hasta === null
                        ? 'success'
                        : 'gray'),

                TextColumn::make('motivo')
                    ->label('Por qué')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('vigencia_desde', 'desc')
            ->paginated([10, 25])
            ->headerActions([
                $this->accionDePactar(),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateHeading('Sin porcentaje pactado')
            ->emptyStateDescription(
                'Mientras no haya ninguno, este pagador paga el precio de lista completo, salvo en '
                .'los ítems que tengan precio negociado propio.'
            );
    }

    private function accionDePactar(): Action
    {
        return Action::make('pactarCondicion')
            ->label('Pactar un porcentaje')
            ->icon(Heroicon::OutlinedPlus)
            /*
             * Admisión y caja LEEN el convenio; pactar el porcentaje que
             * se le cobra es otra cosa. Se pide `update` sobre el
             * convenio, que la matriz solo le concede a dirección.
             */
            ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading('Pactar un porcentaje nuevo')
            ->modalDescription(
                'El porcentaje vigente se cierra el día anterior y este arranca en la fecha que '
                .'elijas. Las facturas ya emitidas no cambian.'
            )
            ->modalSubmitActionLabel('Pactar')
            ->modalWidth('lg')
            ->schema([
                TextInput::make('paga')
                    ->label('Qué porcentaje de la lista paga')
                    ->suffix('% de la lista')
                    ->required()
                    ->live(onBlur: true)
                    /*
                     * `regex` y no `numeric`: `numeric` acepta "1e3", que
                     * entra a bcmath como cero y dejaría todo el catálogo
                     * gratis para este pagador.
                     */
                    ->rule('regex:/^\d{1,4}(\.\d{1,2})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número: 85, o 87.5.',
                    ])
                    ->helperText(fn (Get $get): string => self::ejemplo($get('paga'))),

                DatePicker::make('vigencia_desde')
                    ->label('Vigente desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Tiene que ser posterior a todo lo que ya se pactó con este convenio.'),

                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText('Citá el contrato o el acta de la renovación si los hay.'),
            ])
            ->action(function (array $data, Action $action, FijadorDeCondicion $fijador): void {
                $convenio = $this->getOwnerRecord();

                if (! $convenio instanceof Convenio) {
                    return;
                }

                /** @var string $paga */
                $paga = $data['paga'];

                /** @var string $motivo */
                $motivo = $data['motivo'];

                /** @var string $desde */
                $desde = $data['vigencia_desde'];

                try {
                    $condicion = $fijador->fijar(
                        convenio: $convenio,
                        factor: Decimal::de($paga)->entre('100'),
                        motivo: $motivo,
                        desde: Carbon::parse($desde),
                    );
                } catch (PrecioNoFijableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo pactar el porcentaje')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * `halt()` lanza una excepción de Filament pero está
                     * declarado `void`: el `return` explícito deja claro
                     * que abajo no se sigue.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($condicion->resumen())
                    ->body('Rige desde el '.$condicion->vigencia_desde->format('d/m/Y').'.')
                    ->send();
            });
    }

    private static function ejemplo(mixed $paga): string
    {
        if (! is_string($paga) || preg_match('/^\d{1,4}(\.\d{1,2})?$/', $paga) !== 1) {
            return 'Escribí lo que PAGA, no lo que descuenta: 85 quiere decir «lista menos 15 %».';
        }

        $factor = Decimal::de($paga)->entre('100');
        $sobre = Decimal::de('100')->por($factor)->redondeado(2);

        return "Un ítem de L 100.00 de lista se le cobraría a L {$sobre}.";
    }
}
