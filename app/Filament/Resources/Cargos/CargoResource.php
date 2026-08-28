<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargos;

use App\Filament\Resources\Cargos\Pages\ListCargos;
use App\Filament\Resources\Cargos\Tables\CargosTable;
use App\Models\Cargo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los cargos del hospital — la pantalla de auditoría del dinero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO LISTA. NI CREAR, NI EDITAR, NI VER FICHA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un cargo se asienta desde el motor y se explica dentro de su cuenta,
 * que es donde tiene sentido leerlo. Acá está la vista transversal: qué
 * se cobró hoy en todo el hospital, qué está pendiente de facturar, qué
 * se anuló y por qué.
 *
 * Es la consulta que hace auditoría, y es la que va a alimentar el
 * reporte de glosas del bloque 12.
 *
 * ⚠️ El costo NO es una columna de esta tabla. §9.L13: el costo y el
 * margen son un permiso —`Ver:Costo`— que se chequea en el Resource, en
 * la tabla, en el export y en el PDF. Hasta que ese permiso exista y
 * esté sembrado, no se muestra en ninguno de los cuatro.
 */
class CargoResource extends Resource
{
    protected static ?string $model = Cargo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $slug = 'cargos';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'texto';

    public static function getNavigationLabel(): string
    {
        return 'Cargos';
    }

    public static function getBreadcrumb(): string
    {
        return 'Cargos';
    }

    public static function getModelLabel(): string
    {
        return 'cargo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'cargos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    /**
     * @return Builder<Cargo>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Cargo> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'cuenta:id,numero,encuentro_id',
            'cuenta.encuentro:id,numero,persona_id',
            'cuenta.encuentro.persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,apellido_casada',
            'item:id,nombre,codigo',
        ]);
    }

    public static function table(Table $table): Table
    {
        return CargosTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => ListCargos::route('/'),
        ];
    }
}
