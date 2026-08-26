<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Schemas;

use App\Models\Recepcion;
use App\Models\RecepcionLinea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Qué trajo el camión — solo lectura, y no hay otra forma.
 *
 * Una recepción no tiene pantalla de edición: guardarla ya movió el
 * kardex, y editarla después dejaría el documento diciendo una cosa y las
 * existencias otra. Esto es lo que se ve cuando alguien pregunta «¿qué
 * entró con esa factura?».
 *
 * El costo unitario se muestra desde la LÍNEA y no desde
 * `costos_promedio`: acá interesa a cuánto llegó ese día, no cuánto vale
 * hoy el promedio de todo el estante.
 */
final class RecepcionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La recepción')
                ->columns(3)
                ->schema([
                    TextEntry::make('fecha_recepcion')
                        ->label('Llegó')
                        ->date('d/m/Y'),

                    TextEntry::make('almacen.nombre')
                        ->label('Almacén'),

                    TextEntry::make('proveedor.nombre')
                        ->label('Proveedor')
                        ->placeholder('Sin proveedor registrado'),

                    TextEntry::make('referencia')
                        ->label('Referencia')
                        ->placeholder('Sin referencia')
                        ->columnSpan(2),

                    TextEntry::make('createdBy.name')
                        ->label('Recibió')
                        ->placeholder('—'),

                    TextEntry::make('revisada_en')
                        ->label('Revisión')
                        ->badge()
                        ->color(fn (?string $state): string => $state === null ? 'warning' : 'success')
                        ->state(fn (Recepcion $record): string => $record->estaRevisada()
                            ? 'Revisada por '.($record->revisadaPor->name ?? 'alguien')
                            : 'Falta revisar')
                        ->columnSpan(3),

                    TextEntry::make('notas')
                        ->label('Notas')
                        ->placeholder('—')
                        ->columnSpan(3),
                ]),

            Section::make('Lo que entró')
                ->schema([
                    RepeatableEntry::make('lineas')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('item.nombre')
                                ->label('Producto')
                                ->columnSpan(2)
                                ->weight('bold'),

                            TextEntry::make('presentacion.nombre')
                                ->label('Presentación')
                                ->placeholder('Unidad de dispensación'),

                            TextEntry::make('cantidad_presentacion')
                                ->label('Cantidad')
                                ->numeric(decimalPlaces: 2),

                            TextEntry::make('cantidad_dispensacion')
                                ->label('Entró al kardex')
                                ->numeric(decimalPlaces: 2)
                                ->suffix(' unidades'),

                            TextEntry::make('costo_por_presentacion')
                                ->label('Costo por presentación')
                                ->money('HNL'),

                            TextEntry::make('costo_unitario')
                                ->label('Costo por unidad')
                                ->money('HNL')
                                ->weight('bold')
                                ->tooltip('Con el impuesto adentro. Es lo que alimentó el costo promedio.'),

                            /*
                             * `helperText()` y no `description()`: ese
                             * método es de `TextColumn` —las tablas— y en
                             * un infolist no existe. Son dos jerarquías
                             * distintas de Filament que se parecen mucho
                             * y no comparten API.
                             */
                            TextEntry::make('lote.numero')
                                ->label('Lote')
                                ->placeholder('Sin lote')
                                ->helperText(fn (RecepcionLinea $record): ?string => $record->fecha_vencimiento === null
                                    ? null
                                    : 'Vence el '.$record->fecha_vencimiento->format('d/m/Y')),
                        ]),

                    TextEntry::make('costo_total')
                        ->label('Total de la recepción')
                        ->money('HNL')
                        ->weight('bold')
                        ->state(fn (Recepcion $record): string => $record->costoTotal()->redondeado(2)),
                ]),
        ]);
    }
}
