<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fusiones;

use App\Filament\Resources\Fusiones\Pages\ListFusiones;
use App\Filament\Resources\Fusiones\Tables\FusionesTable;
use App\Models\FusionDePersona;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bandeja de fusiones de duplicados (§9.D4).
 *
 * ⚠️ Acá NO se crean fusiones. Se proponen desde la ficha del paciente,
 * que es donde quien las propone está mirando los datos de los dos y
 * puede decidir con criterio. Una pantalla de alta con dos selectores
 * sueltos invita a fusionar por nombre parecido sin abrir ninguno de los
 * dos expedientes.
 *
 * Esta pantalla es para la SEGUNDA persona: la que aprueba, rechaza o
 * deshace.
 */
class FusionResource extends Resource
{
    protected static ?string $model = FusionDePersona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingIn;

    protected static ?string $slug = 'fusiones';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return 'Fusiones de duplicados';
    }

    public static function getBreadcrumb(): string
    {
        return 'Fusiones';
    }

    public static function getModelLabel(): string
    {
        return 'fusión';
    }

    public static function getPluralModelLabel(): string
    {
        return 'fusiones';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * Lo que espera decisión, marcado en la navegación.
     *
     * Una bandeja que no avisa que tiene cosas adentro es una bandeja que
     * nadie abre. Y mientras una fusión espera, los dos pacientes siguen
     * separados: el duplicado sigue produciendo daño.
     */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = FusionDePersona::query()->pendientes()->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return Builder<FusionDePersona>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<FusionDePersona> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'duplicada:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento',
            'sobreviviente:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento',
            'propuestaPor:id,name',
            'resueltaPor:id,name',
        ]);
    }

    public static function table(Table $table): Table
    {
        return FusionesTable::configure($table);
    }

    /**
     * Se propone desde la ficha del paciente. Ver el encabezado.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * El expediente de una decisión no se borra: es exactamente lo que se
     * consulta cuando alguien pregunta por qué dos historias clínicas
     * terminaron siendo una.
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
            'index' => ListFusiones::route('/'),
        ];
    }
}
