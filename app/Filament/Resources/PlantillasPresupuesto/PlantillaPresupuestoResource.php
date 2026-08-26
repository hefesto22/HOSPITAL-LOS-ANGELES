<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto;

use App\Filament\Resources\PlantillasPresupuesto\Pages\CreatePlantillaPresupuesto;
use App\Filament\Resources\PlantillasPresupuesto\Pages\EditPlantillaPresupuesto;
use App\Filament\Resources\PlantillasPresupuesto\Pages\ListPlantillasPresupuesto;
use App\Filament\Resources\PlantillasPresupuesto\RelationManagers\LineasRelationManager;
use App\Filament\Resources\PlantillasPresupuesto\Schemas\PlantillaPresupuestoForm;
use App\Filament\Resources\PlantillasPresupuesto\Tables\PlantillasPresupuestoTable;
use App\Models\PlantillaPresupuesto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Plantillas de presupuesto — qué lleva cada cirugía (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTA PANTALLA ES LA REPLICABILIDAD DEL MÓDULO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá el hospital escribe su conocimiento: qué lleva una apendicectomía
 * y en qué cantidades. La clínica siguiente escribe el suyo, y ninguna
 * de las dos necesita que alguien toque código (§1.1).
 *
 * ⚠️ La plantilla NO guarda precios, guarda ítems y cantidades. El
 * precio se resuelve al cotizar, con el convenio del caso — por eso una
 * sola plantilla sirve para el particular y para PALIG (ADR-0003).
 */
class PlantillaPresupuestoResource extends Resource
{
    protected static ?string $model = PlantillaPresupuesto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'plantillas-presupuesto';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Plantillas de presupuesto';
    }

    public static function getModelLabel(): string
    {
        return 'plantilla';
    }

    public static function getPluralModelLabel(): string
    {
        return 'plantillas de presupuesto';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    public static function form(Schema $schema): Schema
    {
        return PlantillaPresupuestoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlantillasPresupuestoTable::configure($table);
    }

    /**
     * Una plantilla no se borra: se le pone fecha de fin de vigencia.
     *
     * Los presupuestos que la usaron apuntan a ella, y dentro de ocho
     * meses alguien va a preguntar por qué ese papel tenía los renglones
     * que tenía (§8.4).
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
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LineasRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListPlantillasPresupuesto::route('/'),
            'create' => CreatePlantillaPresupuesto::route('/nueva'),
            'edit'   => EditPlantillaPresupuesto::route('/{record}/editar'),
        ];
    }
}
