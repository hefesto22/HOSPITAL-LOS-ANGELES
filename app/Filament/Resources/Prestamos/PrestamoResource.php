<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos;

use App\Filament\Resources\Prestamos\Pages\ListPrestamos;
use App\Filament\Resources\Prestamos\Tables\PrestamosTable;
use App\Models\Prestamo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Lo que el hospital no tenía y alguien le prestó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTA PANTALLA NO EDITA: SALDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * No hay botón de editar ni de borrar. Registrar el préstamo movió el
 * kardex; cambiarle la cantidad después dejaría el documento diciendo una
 * cosa y la existencia otra, y borrarlo dejaría una entrada de inventario
 * sin dueño — que es el agujero que este módulo vino a tapar.
 *
 * Las dos acciones que sí existen —«Devolver» y «Marcar pagado»— escriben
 * el movimiento que corresponde y cierran el documento con fecha.
 */
class PrestamoResource extends Resource
{
    protected static ?string $model = Prestamo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $slug = 'prestamos';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'presta_nombre';

    public static function getNavigationLabel(): string
    {
        return 'Medicamentos prestados';
    }

    public static function getBreadcrumb(): string
    {
        return 'Préstamos';
    }

    public static function getModelLabel(): string
    {
        return 'préstamo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'préstamos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * Cuántos se deben, en el sidebar.
     *
     * Es el único número que importa de esta pantalla, y ponerlo en el
     * menú es lo que hace que alguien entre. Una lista que hay que
     * acordarse de abrir no se abre.
     */
    public static function getNavigationBadge(): ?string
    {
        $abiertos = Prestamo::query()->queSeDeben()->count();

        return $abiertos > 0 ? (string) $abiertos : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return PrestamosTable::configure($table);
    }

    public static function canEdit(mixed $record): bool
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
            'index' => ListPrestamos::route('/'),
        ];
    }
}
