<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai\Schemas;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\RangoCai;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RangoCaiForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lo que dice la resolución del SAR')
                ->description('Copiá cada dato del papel. Nada de esto se deduce ni se inventa.')
                ->columns(2)
                ->schema([
                    Select::make('tipo')
                        ->label('¿Para qué documento?')
                        ->options(fn (): array => array_reduce(
                            TipoDocumentoDeVenta::cases(),
                            /** @param array<string, string> $lista */
                            fn (array $lista, TipoDocumentoDeVenta $tipo): array => $lista + [$tipo->value => $tipo->etiqueta()],
                            [],
                        ))
                        ->default(TipoDocumentoDeVenta::Factura->value)
                        ->native(false)
                        ->required()
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    TextInput::make('cai')
                        ->label('CAI')
                        ->required()
                        ->maxLength(40)
                        ->helperText('Los 32 caracteres, con sus guiones, tal como vienen impresos.')
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    /*
                     * 🔴 LOS TRES SEGMENTOS SE COPIAN.
                     *
                     * El número fiscal es establecimiento-punto-tipo-
                     * correlativo. Los tres primeros los asigna el SAR;
                     * el sistema no los deduce de nada.
                     */
                    TextInput::make('establecimiento')
                        ->label('Establecimiento')
                        ->required()
                        ->length(3)
                        ->placeholder('000')
                        ->helperText('Primer segmento del número.')
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    TextInput::make('punto_emision')
                        ->label('Punto de emisión')
                        ->required()
                        ->length(3)
                        ->placeholder('001')
                        ->helperText('Segundo segmento. Es la caja o la ventanilla que emite.')
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    TextInput::make('tipo_codigo')
                        ->label('Código del tipo de documento')
                        ->required()
                        ->length(2)
                        ->placeholder('01')
                        ->helperText('Tercer segmento, el que asigna el SAR. Copialo: adivinarlo son facturas con número inválido.')
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    DatePicker::make('fecha_limite_emision')
                        ->label('Fecha límite de emisión')
                        ->required()
                        ->native(false)
                        ->helperText('Pasada esta fecha, ningún documento emitido con este CAI tiene validez.'),
                ]),

            Section::make('El rango autorizado')
                ->columns(3)
                ->schema([
                    TextInput::make('desde')
                        ->label('Desde')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1)
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    TextInput::make('hasta')
                        ->label('Hasta')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->disabled(fn (?RangoCai $record): bool => self::yaSeUso($record)),

                    /*
                     * Al crear arranca en «desde» y después NUNCA se
                     * toca a mano: lo avanza el emisor con la fila
                     * bloqueada. Editarlo sería repetir o saltar
                     * números, que es lo único que el SAR no perdona.
                     */
                    TextInput::make('siguiente')
                        ->label('Próximo número')
                        ->numeric()
                        ->required()
                        ->default(1)
                        ->disabledOn('edit')
                        ->helperText('Lo avanza el sistema al emitir. No se corrige a mano.'),
                ]),

            Section::make('Control')
                ->columns(2)
                ->schema([
                    Toggle::make('activo')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Solo uno activo por tipo y punto de emisión. Es el que la caja consume.'),

                    TextInput::make('resolucion')
                        ->label('N.º de resolución')
                        ->maxLength(60),

                    Textarea::make('nota')
                        ->label('Nota')
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * ¿Este rango ya emitió algo?
     *
     * Desde el primer documento, sus datos dejan de ser editables: ya
     * hay papeles impresos que dicen ese CAI y ese número.
     */
    private static function yaSeUso(?RangoCai $record): bool
    {
        return $record instanceof RangoCai && $record->siguiente > $record->desde;
    }
}
