<?php

declare(strict_types=1);

namespace App\Filament\Resources\TurnosDeCaja;

use App\Filament\Resources\TurnosDeCaja\Pages\ListTurnosDeCaja;
use App\Filament\Resources\TurnosDeCaja\Tables\TurnosDeCajaTable;
use App\Models\TurnoDeCaja;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los turnos cerrados y sus arqueos.
 *
 * Es la pantalla de dirección, no la de la cajera: acá se ve quién cerró,
 * con cuánto, y —lo único que de verdad importa— **cuánto sobró o
 * faltó**. Un turno con diferencia distinta de cero lleva su explicación
 * escrita al lado, porque la base no lo deja cerrar sin ella.
 *
 * Abrir y cerrar se hace en la pantalla de Caja, que es donde está la
 * persona con los billetes en la mano.
 */
class TurnoDeCajaResource extends Resource
{
    protected static ?string $model = TurnoDeCaja::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $slug = 'turnos-de-caja';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Turnos de caja';
    }

    public static function getModelLabel(): string
    {
        return 'turno de caja';
    }

    public static function getPluralModelLabel(): string
    {
        return 'turnos de caja';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * @return Builder<TurnoDeCaja>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<TurnoDeCaja> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with(['usuario:id,name', 'cerradoPor:id,name']);
    }

    public static function table(Table $table): Table
    {
        return TurnosDeCajaTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTurnosDeCaja::route('/'),
        ];
    }
}
