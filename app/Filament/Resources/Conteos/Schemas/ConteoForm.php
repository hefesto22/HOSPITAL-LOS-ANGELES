<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Schemas;

use App\Domain\Enums\AlcanceDeConteo;
use App\Models\Almacen;
use App\Services\AbridorDeConteo;
use App\Support\AlmacenesDelUsuario;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Abrir un conteo — cuatro decisiones y a contar.
 *
 * Este formulario NO cuenta nada. Solo dice qué estante se va a contar y
 * con qué reglas; contar es la pantalla siguiente, que se usa de pie y
 * sin mouse.
 *
 * Es corto a propósito: se llena una vez, y cada campo de más es un
 * segundo que alguien pasa frente a una pantalla en vez de frente al
 * estante.
 */
final class ConteoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('¿Qué se va a contar?')
                ->columns(2)
                ->schema([
                    Select::make('almacen_id')
                        ->label('Almacén')
                        ->options(fn (): array => AlmacenesDelUsuario::elegibles()
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (Almacen $almacen): array => [
                                $almacen->id => $almacen->etiqueta(),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->helperText(
                            'Solo aparecen los almacenes de tu área. Mientras haya un conteo '
                            .'abierto en uno, no se puede abrir otro ahí.'
                        ),

                    Radio::make('alcance')
                        ->label('Alcance')
                        ->options(fn (): array => collect(AlcanceDeConteo::cases())
                            ->mapWithKeys(fn (AlcanceDeConteo $a): array => [
                                $a->value => $a->etiqueta(),
                            ])
                            ->all())
                        ->descriptions(fn (): array => collect(AlcanceDeConteo::cases())
                            ->mapWithKeys(fn (AlcanceDeConteo $a): array => [
                                $a->value => $a->descripcion(),
                            ])
                            ->all())
                        ->default(AlcanceDeConteo::Parcial->value)
                        ->required(),

                    TextInput::make('descripcion')
                        ->label('¿Por qué se cuenta?')
                        ->maxLength(160)
                        ->placeholder('Conteo cíclico de antibióticos · agosto')
                        ->helperText(
                            'Opcional, pero es lo que explica dentro de un año por qué ese día '
                            .'alguien contó ese estante.'
                        ),

                    TextInput::make('tolerancia_recuento')
                        ->label('Volver a contar si la diferencia pasa de')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.0001')
                        ->default(fn (): string => AbridorDeConteo::toleranciaPorDefecto()->redondeado(4))
                        ->suffix('unidades')
                        ->required()
                        ->helperText(
                            'En cero, cualquier diferencia exige una segunda pasada — que es lo '
                            .'correcto para controlados e implantes. Para gasas, subilo.'
                        ),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
