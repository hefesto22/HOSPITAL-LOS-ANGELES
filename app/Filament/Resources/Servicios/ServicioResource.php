<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servicios;

use App\Filament\Resources\Servicios\Pages\CreateServicio;
use App\Filament\Resources\Servicios\Pages\EditServicio;
use App\Filament\Resources\Servicios\Pages\ListServicios;
use App\Filament\Resources\Servicios\Schemas\ServicioForm;
use App\Filament\Resources\Servicios\Tables\ServiciosTable;
use App\Models\Servicio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Servicios / áreas de la sede (§8.1).
 *
 * Es DONDE SE ATIENDE al paciente. No es un almacén: un servicio puede
 * tener almacén propio (carro de paro), varios, o ninguno y consumir del
 * dispensario.
 */
class ServicioResource extends Resource
{
    protected static ?string $model = Servicio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'servicios';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return 'Servicios y áreas';
    }

    public static function getBreadcrumb(): string
    {
        return 'Servicios';
    }

    public static function getModelLabel(): string
    {
        return 'servicio';
    }

    public static function getPluralModelLabel(): string
    {
        return 'servicios';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración del hospital';
    }

    /**
     * Eager loading con columnas nombradas + withCount (§12).
     *
     * El scope por sede lo aplica el global scope de BelongsToSede; acá no
     * se repite, porque dos fuentes de scoping que se pueden desincronizar
     * es peor que una (§9.L5).
     *
     * @return Builder<Servicio>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Servicio> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta
            ->with(['sede:id,codigo,nombre'])
            ->withCount('almacenes');
    }

    public static function form(Schema $schema): Schema
    {
        return ServicioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiciosTable::configure($table);
    }

    /**
     * Un servicio con encuentros, cargos o camas adentro no se borra: se
     * cierra con vigencia. La FK es restrictOnDelete, así que borrarlo
     * fallaría de todas formas — mejor no ofrecerlo (§9.A17).
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
            'index'  => ListServicios::route('/'),
            'create' => CreateServicio::route('/create'),
            'edit'   => EditServicio::route('/{record}/edit'),
        ];
    }
}
