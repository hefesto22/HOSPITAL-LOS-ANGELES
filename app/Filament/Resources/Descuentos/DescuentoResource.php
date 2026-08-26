<?php

declare(strict_types=1);

namespace App\Filament\Resources\Descuentos;

use App\Filament\Resources\Descuentos\Pages\ListDescuentos;
use App\Filament\Resources\Descuentos\Tables\DescuentosTable;
use App\Models\Descuento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * La lista de descuentos del hospital, con nombre y porcentaje.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTA PANTALLA NO EDITA: CREA
 * ─────────────────────────────────────────────────────────────────────
 *
 * No hay botón de editar ni de borrar, igual que en Márgenes objetivo.
 * El porcentaje es un historial, no un valor: cambiarlo es cerrar el
 * vigente y abrir uno nuevo con fecha. Un `UPDATE` sobre la fila vigente
 * borraría la única respuesta que importa cuando alguien pregunte, el
 * año que viene, por qué a ese paciente se le descontó eso en marzo.
 *
 * 🔴 Y el nombre no se toca nunca. Los ítems tienen marcado el descuento
 * POR NOMBRE —ver el encabezado de `Descuento`—, así que renombrar una
 * fila le sacaría el descuento a todos los ítems que la tenían, sin un
 * solo error y con la casilla siguiendo marcada en pantalla.
 */
class DescuentoResource extends Resource
{
    protected static ?string $model = Descuento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $slug = 'descuentos';

    protected static ?int $navigationSort = 54;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Descuentos';
    }

    public static function getBreadcrumb(): string
    {
        return 'Descuentos';
    }

    public static function getModelLabel(): string
    {
        return 'descuento';
    }

    public static function getPluralModelLabel(): string
    {
        return 'descuentos';
    }

    /**
     * Solo aparece si el hospital de verdad da descuentos propios.
     *
     * Ver `sihla.inventario.usa_descuentos_propios`: acá se apaga la
     * NAVEGACIÓN, no el módulo. Las rutas siguen existiendo y un cargo
     * viejo que haya usado un descuento comercial sigue explicándose.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('sihla.inventario.usa_descuentos_propios', false);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public static function table(Table $table): Table
    {
        return DescuentosTable::configure($table);
    }

    /**
     * Ver el encabezado: acá no se edita, se crea uno nuevo con fecha.
     */
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
            'index' => ListDescuentos::route('/'),
        ];
    }
}
