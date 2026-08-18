<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes;

use App\Filament\Resources\Sedes\Pages\CreateSede;
use App\Filament\Resources\Sedes\Pages\EditSede;
use App\Filament\Resources\Sedes\Pages\ListSedes;
use App\Filament\Resources\Sedes\Schemas\SedeForm;
use App\Filament\Resources\Sedes\Tables\SedesTable;
use App\Models\Sede;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sedes — establecimientos del hospital (§8.1, ADR-0002).
 *
 * ⚠️ Este Resource NO se borra nunca en bloque ni de a uno: una sede con
 * histórico adentro no se elimina, se cierra poniéndole `vigencia_hasta`.
 * Por eso no hay DeleteAction ni bulk actions (§9.A17).
 */
class SedeResource extends Resource
{
    protected static ?string $model = Sede::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    /*
     * Explícitos a propósito (§10.5). Str::headline convierte "sedes" en
     * algo aceptable por casualidad, pero en cuanto un Resource se llame
     * "formas_de_pago" produce "Formas De Pago". La regla es declararlos
     * SIEMPRE, no solo cuando se ve feo.
     */
    public static function getNavigationLabel(): string
    {
        return 'Sedes';
    }

    public static function getBreadcrumb(): string
    {
        return 'Sedes';
    }

    public static function getModelLabel(): string
    {
        return 'sede';
    }

    public static function getPluralModelLabel(): string
    {
        return 'sedes';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración del hospital';
    }

    /**
     * Contadores con withCount, no con una subconsulta por fila (§12).
     *
     * @return Builder<Sede>
     */
    public static function getEloquentQuery(): Builder
    {
        // El padre declara Builder sin genérico; el @var lo estrecha para
        // que PHPStan sepa sobre qué modelo estamos consultando.
        /** @var Builder<Sede> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->withCount(['servicios', 'almacenes']);
    }

    public static function form(Schema $schema): Schema
    {
        return SedeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SedesTable::configure($table);
    }

    /**
     * Una sede no se borra: se cierra con vigencia. Borrarla dejaría
     * huérfano todo el histórico clínico y fiscal que cuelga de ella.
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
            'index'  => ListSedes::route('/'),
            'create' => CreateSede::route('/create'),
            'edit'   => EditSede::route('/{record}/edit'),
        ];
    }
}
