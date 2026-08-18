<?php

declare(strict_types=1);

namespace App\Filament\Resources\Unidades\Schemas;

use App\Domain\Enums\MagnitudDeMedida;
use App\Filament\Schemas\Components\CampoMayusculas;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class UnidadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(15)
                    ->disabledOn('edit')
                    ->helperText('Corto y en mayúsculas: ML, AMP, CAJA.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(60),

                /*
                 * El símbolo NO pasa por CampoMayusculas y es a propósito:
                 * "mg" en mayúscula deja de ser miligramo, y en una dosis
                 * esa diferencia mata.
                 */
                TextInput::make('simbolo')
                    ->label('Símbolo')
                    ->maxLength(10)
                    ->helperText('Se respeta tal cual se escribe: mg y Mg no son lo mismo.'),

                Select::make('magnitud')
                    ->label('Qué mide')
                    ->options(fn (): array => collect(MagnitudDeMedida::cases())
                        ->mapWithKeys(fn (MagnitudDeMedida $m): array => [$m->value => $m->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $magnitud = $state === null ? null : MagnitudDeMedida::tryFrom($state);

                        if ($magnitud instanceof MagnitudDeMedida) {
                            $set('permite_fraccion', $magnitud->admiteFraccionPorNaturaleza());
                        }
                    }),

                Toggle::make('permite_fraccion')
                    ->label('Admite cantidades con decimales')
                    ->columnSpanFull()
                    ->helperText(
                        'Media ampolla que se abrió no es media existencia: es una ampolla consumida '
                        .'y una merma. Medio mililitro, en cambio, es una dosis.'
                    ),
            ]);
    }
}
