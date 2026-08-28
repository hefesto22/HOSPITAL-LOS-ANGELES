<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos;

use App\Filament\Resources\Presupuestos\Pages\CreatePresupuesto;
use App\Filament\Resources\Presupuestos\Pages\EditPresupuesto;
use App\Filament\Resources\Presupuestos\Pages\ListPresupuestos;
use App\Filament\Resources\Presupuestos\RelationManagers\RenglonesRelationManager;
use App\Filament\Resources\Presupuestos\Schemas\PresupuestoForm;
use App\Filament\Resources\Presupuestos\Tables\PresupuestosTable;
use App\Models\Presupuesto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El presupuesto al paciente (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 POR QUÉ ESTO IMPORTA MÁS DE LO QUE PARECE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque **muchas familias solo tienen lo que se les presupuestó**.
 * Pasarse no es un desvío de gestión que se explica a fin de mes: es
 * plata que el hospital no va a cobrar, descubierta el día del alta,
 * discutiendo en caja con alguien que no la tiene.
 *
 * De ahí sale todo lo demás del módulo: el aviso al 80 % —el único
 * momento en que todavía se puede hacer algo—, que las líneas opcionales
 * sumen al total (se cotiza el techo, no el piso) y que un presupuesto
 * agotado NUNCA detenga un cargo clínico.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PLANTILLA SE COPIA UNA VEZ Y SE SUELTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Al crear el presupuesto, los renglones de la plantilla se copian con
 * su precio ya resuelto. A partir de ahí son de ESTE paciente: se les
 * cambia la cantidad, se borran los que no van, se agregan los que
 * aparecieron. La plantilla no vuelve a meterse nunca.
 */
class PresupuestoResource extends Resource
{
    protected static ?string $model = Presupuesto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $slug = 'presupuestos';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Presupuestos';
    }

    public static function getModelLabel(): string
    {
        return 'presupuesto';
    }

    public static function getPluralModelLabel(): string
    {
        return 'presupuestos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * @return Builder<Presupuesto>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Presupuesto> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido',
            'expediente:id,numero',
            'convenio:id,codigo,nombre',
            'encuentro:id,numero',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return PresupuestoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PresupuestosTable::configure($table);
    }

    /**
     * Un presupuesto no se borra: se anula con motivo.
     *
     * El papel que la familia se llevó existe aunque el sistema lo
     * olvide, y el día que reclamen hay que poder decir qué decía.
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
            RenglonesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListPresupuestos::route('/'),
            'create' => CreatePresupuesto::route('/nuevo'),
            'edit'   => EditPresupuesto::route('/{record}/editar'),
        ];
    }
}
