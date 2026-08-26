<?php

declare(strict_types=1);

namespace App\Filament\Resources\DescuentosLegales;

use App\Filament\Resources\DescuentosLegales\Pages\ListDescuentosLegales;
use App\Filament\Resources\DescuentosLegales\Tables\DescuentosLegalesTable;
use App\Models\DescuentoLegal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Los porcentajes del descuento de ley, por categoría y por edad.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTA PANTALLA TENÍA QUE EXISTIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta hoy estos números entraban solo por seeder: cumplir con una
 * reforma exigía un despliegue. Y las reformas llegan — la ley ya se
 * tocó dos veces en 2025, con el Decreto 45-2025 sobre servicios básicos
 * y con el 59-2025 sobre salud.
 *
 * De acá sale el precio de lista de TODO el catálogo:
 *
 *     precio_lista = costo × (1 + margen) ÷ (1 − descuento_máximo)
 *
 * Por eso cada fila lleva su fundamento citado: cuando llegue una
 * denuncia a la línea 115 por una factura de hace dos años, lo que hay
 * que poder mostrar no es el porcentaje de hoy sino **el que regía el
 * día del servicio, y de dónde salía**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA CUARTA EDAD YA ESTABA MODELADA, LE FALTABAN LAS FILAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El rango existe, la tabla guarda un porcentaje por (categoría, rango)
 * y el resolutor sube la escalera: un paciente de 80 también tiene 60,
 * así que toma el mejor de los dos y nunca cero. Cargar la cuarta edad
 * es escribir filas acá — ningún cambio de esquema, ningún despliegue.
 */
class DescuentoLegalResource extends Resource
{
    protected static ?string $model = DescuentoLegal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'descuentos-de-ley';

    protected static ?int $navigationSort = 55;

    protected static ?string $recordTitleAttribute = 'fundamento';

    public static function getNavigationLabel(): string
    {
        return 'Descuentos de ley';
    }

    public static function getModelLabel(): string
    {
        return 'descuento de ley';
    }

    public static function getPluralModelLabel(): string
    {
        return 'descuentos de ley';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public static function table(Table $table): Table
    {
        return DescuentosLegalesTable::configure($table);
    }

    /**
     * Ver el encabezado de la policy: acá no se edita ni se borra, se
     * carga uno nuevo con fecha.
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
            'index' => ListDescuentosLegales::route('/'),
        ];
    }
}
