<?php

declare(strict_types=1);

namespace App\Filament\Resources\MargenesObjetivo\Actions;

use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\MargenNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Services\FijadorDeMargenObjetivo;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * «Fijar un margen nuevo» — la única forma de cambiar el margen.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE ESCRIBE EN PORCENTAJE, SE GUARDA EN FRACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio dice «120 %», la base guarda `1.2000`. La conversión se hace
 * en un solo lugar —acá— y con `Decimal`, no con `/ 100` en punto
 * flotante: 12.5 % dividido en float es 0.125000000000000006, y ese
 * arrastre termina en el precio de cada producto del catálogo (§8.6.2).
 */
final class FijarMargenAction
{
    public static function make(): Action
    {
        return Action::make('fijarMargen')
            ->label('Fijar un margen nuevo')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Fijar un margen nuevo')
            ->modalDescription(
                'El margen vigente se cierra el día anterior y este arranca en la fecha que elijas. '
                .'Los precios ya calculados no cambian solos: este margen aplica a lo que se calcule '
                .'de acá en adelante.'
            )
            ->modalSubmitActionLabel('Fijar')
            ->modalWidth('lg')
            ->schema([
                /*
                 * El desplegable ofrece SOLO medicamentos e insumos, y no
                 * es un recorte de la pantalla: son los dos únicos tipos
                 * que la calculadora acepta. Lo demás lleva su precio de
                 * lista escrito a mano en el tarifario y ningún margen lo
                 * toca.
                 *
                 * ⚠️ El texto de ayuda decía «tiene que existir siempre
                 * uno así» del default, y era falso: con esos dos tipos
                 * cubiertos, una fila sin tipo no la alcanza nadie —
                 * solo tarifaría EN SILENCIO un tipo futuro, que es el
                 * default silencioso que el §9 prohíbe.
                 */
                Select::make('tipo_item')
                    ->label('Se aplica a')
                    ->options(self::tiposQueSeCompran())
                    ->placeholder('Todo lo demás (no hace falta)')
                    ->native(false)
                    ->helperText(
                        'Medicamentos e insumos son los únicos tipos que se compran, y ya tienen el '
                        .'suyo. Dejarlo vacío solo serviría para tarifar un tipo futuro sin que nadie '
                        .'lo decida: mejor que la calculadora falle y se fije el número a propósito.'
                    ),

                TextInput::make('porcentaje')
                    ->label('Margen sobre el costo')
                    ->suffix('%')
                    ->required()
                    ->live(onBlur: true)
                    /*
                     * `regex` y no `numeric`: `numeric` acepta "1e3" y
                     * "0x1A", y los dos entran a bcmath como cero.
                     */
                    ->rule('regex:/^\d{1,4}(\.\d{1,2})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta dos decimales: 120, o 87.5.',
                    ])
                    ->helperText(fn (Get $get): string => self::ejemplo($get('porcentaje'))),

                DatePicker::make('vigencia_desde')
                    ->label('Vigente desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Tiene que ser posterior a todos los márgenes que ya existen para ese tipo.'),

                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText(
                        'Un margen sin explicación es un margen que nadie se anima a cambiar dos años '
                        .'después. Quedan los dos: el número y la razón.'
                    ),
            ])
            ->action(function (array $data, Action $action, FijadorDeMargenObjetivo $fijador): void {
                /** @var string $porcentaje */
                $porcentaje = $data['porcentaje'];

                /** @var string $motivo */
                $motivo = $data['motivo'];

                /** @var string|null $tipo */
                $tipo = $data['tipo_item'] ?? null;

                /** @var string $desde */
                $desde = $data['vigencia_desde'];

                try {
                    $margen = $fijador->fijar(
                        tipo: $tipo === null || $tipo === '' ? null : TipoItem::from($tipo),
                        fraccion: Decimal::de($porcentaje)->entre('100'),
                        motivo: $motivo,
                        desde: Carbon::parse($desde),
                    );
                } catch (MargenNoFijableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo fijar el margen')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * `halt()` lanza una excepción de Filament, pero está
                     * declarado `void`: el `return` explícito es para que
                     * quede claro —al analizador y a quien lea— que abajo
                     * no se sigue.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Margen fijado en '.$margen->fraccion()->comoPorcentaje())
                    ->body('Aplica a los precios que se calculen desde el '
                        .$margen->vigencia_desde->format('d/m/Y').'.')
                    ->send();
            });
    }

    /**
     * Solo los tipos cuyo precio SE DERIVA del costo. Un honorario o una
     * estancia no se compran, así que un margen ahí no significa nada:
     * su precio se fija a mano en el tarifario (Ruta B del §4.1).
     *
     * @return array<string, string>
     */
    private static function tiposQueSeCompran(): array
    {
        return collect(TipoItem::cases())
            ->filter(fn (TipoItem $tipo): bool => $tipo->precioDerivadoDelCosto())
            ->mapWithKeys(fn (TipoItem $tipo): array => [$tipo->value => $tipo->etiqueta()])
            ->all();
    }

    private static function ejemplo(mixed $porcentaje): string
    {
        if (! is_string($porcentaje) || preg_match('/^\d{1,4}(\.\d{1,2})?$/', $porcentaje) !== 1) {
            return 'Es margen SOBRE EL COSTO: 120 % quiere decir que lo que costó L 10.00 tiene que dejar L 12.00.';
        }

        $ganancia = Decimal::de($porcentaje)->entre('100')->por('100')->redondeado(2);

        return "Lo que costó L 100.00 tendría que dejar L {$ganancia} de ganancia.";
    }
}
