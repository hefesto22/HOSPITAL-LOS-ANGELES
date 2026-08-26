<?php

declare(strict_types=1);

namespace App\Filament\Resources\DescuentosLegales\Actions;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Services\FijadorDeDescuentoLegal;
use App\Support\NumeroDeFormulario;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Cargar un porcentaje de ley, o corregir el que se acaba de cargar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE ESCRIBE EN PORCENTAJE Y SE GUARDA EN FRACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Quien lee La Gaceta lee «25 %», no «0.25». Pedirle que traduzca es
 * pedirle que se equivoque en un factor de cien — y un factor de cien en
 * un descuento es el hospital regalando la factura entera. La traducción
 * la hace el formulario, en un solo lugar y siempre en la misma
 * dirección.
 */
final class CargarDescuentoAction
{
    public static function make(): Action
    {
        return Action::make('cargarDescuento')
            ->label('Cargar un porcentaje')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Cargar un porcentaje de ley')
            ->modalDescription(
                'Copiá el número tal como está en el decreto y citá de dónde sale. Si ya hay uno '
                .'vigente para esa categoría y esa edad, se cierra el día anterior y este arranca '
                .'el día que pongas — el porcentaje viejo no se pierde.'
            )
            ->modalSubmitActionLabel('Cargar')
            ->schema([
                Select::make('categoria_legal')
                    ->label('Categoría')
                    ->options(fn (): array => collect(CategoriaLegalDeDescuento::cases())
                        ->reject(fn (CategoriaLegalDeDescuento $categoria): bool => $categoria
                            === CategoriaLegalDeDescuento::SinDescuentoLegal)
                        ->mapWithKeys(fn (CategoriaLegalDeDescuento $categoria): array => [
                            $categoria->value => $categoria->etiqueta(),
                        ])
                        ->all())
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->live()
                    /*
                     * El requisito de receta es propiedad de la
                     * CATEGORÍA, no de quien carga el dato: el Art. 34 lo
                     * exige para medicamentos. Se propone y se puede
                     * cambiar, porque una reforma puede quitarlo.
                     */
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $categoria = is_string($state)
                            ? CategoriaLegalDeDescuento::tryFrom($state)
                            : null;

                        if ($categoria instanceof CategoriaLegalDeDescuento) {
                            $set('exige_receta', $categoria->exigeReceta());
                        }
                    })
                    ->helperText(fn (Get $get): ?string => self::categoriaDe($get)?->numeral()),

                Select::make('rango_edad')
                    ->label('Edad')
                    ->options(fn (): array => collect(RangoEdad::conDerechoADescuento())
                        ->mapWithKeys(fn (RangoEdad $rango): array => [$rango->value => $rango->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->default(RangoEdad::Tercera->value)
                    ->helperText(
                        'La escalera sube sola: un paciente de la cuarta edad al que le falte su '
                        .'fila recibe la de la tercera, nunca cero.'
                    ),

                TextInput::make('porcentaje')
                    ->label('Descuento')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step('0.01')
                    ->suffix('%')
                    ->helperText('Escribilo como en el decreto: 25 para 25 %.'),

                DatePicker::make('vigencia_desde')
                    ->label('Rige desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText(
                        'La fecha del decreto, no la de hoy. Una factura vieja que se reimprima '
                        .'tiene que salir con el porcentaje que regía ese día.'
                    ),

                Toggle::make('exige_receta')
                    ->label('Exige receta original')
                    ->helperText('Art. 34: para medicamentos, firmada y sellada.'),

                Textarea::make('fundamento')
                    ->label('De dónde sale')
                    ->required()
                    ->minLength(10)
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Art. 30 numeral 7, Decreto Legislativo 199-2006')
                    ->helperText(
                        'Decreto, artículo y numeral. No es documentación: es lo que hay que poder '
                        .'mostrar cuando llega una denuncia a la línea 115.'
                    ),

                Textarea::make('nota')
                    ->label('Nota')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Opcional. Para dejar dicho qué se verificó y contra qué texto.'),
            ])
            ->action(function (array $data): void {
                self::cargar($data);
            });
    }

    /**
     * El `DatePicker` devuelve la fecha como texto en unas rutas y como
     * Carbon en otras. Se aceptan las dos en vez de tipar una y confiar.
     */
    private static function fechaDe(mixed $valor): CarbonInterface
    {
        if ($valor instanceof CarbonInterface) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : now();
    }

    private static function categoriaDe(Get $get): ?CategoriaLegalDeDescuento
    {
        $valor = $get('categoria_legal');

        return is_string($valor) ? CategoriaLegalDeDescuento::tryFrom($valor) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function cargar(array $data): void
    {
        $categoria = is_string($data['categoria_legal'] ?? null)
            ? CategoriaLegalDeDescuento::tryFrom($data['categoria_legal'])
            : null;

        $rango = is_string($data['rango_edad'] ?? null)
            ? RangoEdad::tryFrom($data['rango_edad'])
            : null;

        $enPorciento = NumeroDeFormulario::aDecimal($data['porcentaje'] ?? null);

        if (! $categoria instanceof CategoriaLegalDeDescuento
            || ! $rango instanceof RangoEdad
            || ! $enPorciento instanceof Decimal) {
            Notification::make()
                ->danger()
                ->title('No se pudo leer el formulario')
                ->body('Revisá la categoría, la edad y el porcentaje.')
                ->send();

            return;
        }

        try {
            $fijado = app(FijadorDeDescuentoLegal::class)->fijar(
                categoria: $categoria,
                rango: $rango,
                porcentaje: $enPorciento->entre('100'),
                fundamento: is_string($data['fundamento'] ?? null) ? $data['fundamento'] : '',
                desde: self::fechaDe($data['vigencia_desde'] ?? null),
                exigeReceta: ($data['exige_receta'] ?? false) === true,
                nota: is_string($data['nota'] ?? null) && $data['nota'] !== '' ? $data['nota'] : null,
            );
        } catch (DescuentoNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo cargar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Porcentaje cargado')
            ->body(sprintf(
                '%s · %s: %s %% desde el %s.',
                $fijado->categoria_legal->etiqueta(),
                $fijado->rango_edad->etiqueta(),
                $fijado->fraccion()->por('100')->redondeado(2),
                $fijado->vigencia_desde->format('d/m/Y'),
            ))
            ->send();
    }
}
