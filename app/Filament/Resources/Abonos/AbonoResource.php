<?php

declare(strict_types=1);

namespace App\Filament\Resources\Abonos;

use App\Filament\Resources\Abonos\Pages\ListAbonos;
use App\Filament\Resources\Abonos\Tables\AbonosTable;
use App\Models\Abono;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los recibos: toda la plata que entró, en una lista.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES DE CONSULTA, NO DE CAPTURA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un abono no se recibe desde acá: se recibe en la pantalla de la cuenta
 * del paciente, con el turno abierto de quien está cobrando. Esta lista
 * existe para lo otro —«¿entró el depósito de la señora?», «¿cuánto se
 * recibió ayer?», «este recibo, ¿quién lo hizo?»— y para que dirección
 * pueda mirar sin pedirle la pantalla a nadie.
 *
 * Por eso no tiene crear ni editar: un recibo no se edita nunca, y
 * recibirlo sin turno no debe ser posible desde ningún lado.
 */
class AbonoResource extends Resource
{
    protected static ?string $model = Abono::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $slug = 'abonos';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Abonos recibidos';
    }

    public static function getModelLabel(): string
    {
        return 'abono';
    }

    public static function getPluralModelLabel(): string
    {
        return 'abonos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * @return Builder<Abono>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Abono> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'cuenta:id,numero,encuentro_id',
            'turno:id,numero,nombre,estado',
            'recibidoPor:id,name',
            'medios',
        ]);
    }

    public static function table(Table $table): Table
    {
        return AbonosTable::configure($table);
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
            'index' => ListAbonos::route('/'),
        ];
    }
}
