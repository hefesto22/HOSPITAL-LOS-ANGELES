<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas;

use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Facturas\Tables\FacturasTable;
use App\Models\Factura;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las facturas emitidas.
 *
 * De consulta y anulación, nada más: una factura no se crea desde acá
 * —se emite desde la cuenta del paciente, que es donde están los cargos—
 * y no se edita nunca. El papel ya salió impreso.
 */
class FacturaResource extends Resource
{
    protected static ?string $model = Factura::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'facturas';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Facturas';
    }

    public static function getModelLabel(): string
    {
        return 'factura';
    }

    public static function getPluralModelLabel(): string
    {
        return 'facturas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * @return Builder<Factura>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Factura> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with(['cuenta:id,numero', 'persona:id,primer_nombre,primer_apellido']);
    }

    public static function table(Table $table): Table
    {
        return FacturasTable::configure($table);
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
            'index' => ListFacturas::route('/'),
        ];
    }
}
