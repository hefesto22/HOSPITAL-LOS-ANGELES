<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\Schemas\CompraForm;
use App\Filament\Resources\Compras\Tables\ComprasTable;
use App\Models\Compra;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Compras — en qué se gastó la plata.
 *
 * ⚠️ Esta pantalla NO mueve inventario. Ni una unidad. Es el registro
 * fiscal: qué facturó el proveedor, con cuánto ISV, y bajo qué categoría
 * de gasto. Lo que entra al estante se registra en **Recepciones**.
 *
 * Son dos hechos distintos y por eso son dos pantallas: se compra
 * papelería que nunca entra a un kardex, llega una donación que no tiene
 * factura, y llega mercadería el lunes cuya factura aparece el viernes.
 */
class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?string $slug = 'compras';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'numero_documento';

    public static function getNavigationLabel(): string
    {
        return 'Compras';
    }

    public static function getModelLabel(): string
    {
        return 'compra';
    }

    public static function getPluralModelLabel(): string
    {
        return 'compras';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['numero_documento'];
    }

    public static function form(Schema $schema): Schema
    {
        return CompraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComprasTable::configure($table);
    }

    /**
     * Una compra no se borra: forma parte del Libro de Compras del
     * período. Si estuvo mal, se corrige el registro.
     */
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
            'index'  => ListCompras::route('/'),
            'create' => CreateCompra::route('/create'),
            'edit'   => EditCompra::route('/{record}/edit'),
        ];
    }
}
