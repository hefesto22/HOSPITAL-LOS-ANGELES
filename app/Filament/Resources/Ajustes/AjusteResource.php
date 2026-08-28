<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes;

use App\Filament\Resources\Ajustes\Pages\CreateAjuste;
use App\Filament\Resources\Ajustes\Pages\ListAjustes;
use App\Filament\Resources\Ajustes\Pages\VerAjuste;
use App\Filament\Resources\Ajustes\RelationManagers\LineasDelAjusteRelationManager;
use App\Filament\Resources\Ajustes\Schemas\AjusteForm;
use App\Filament\Resources\Ajustes\Schemas\AjusteInfolist;
use App\Filament\Resources\Ajustes\Tables\AjustesTable;
use App\Models\Ajuste;
use App\Support\AlmacenesDelUsuario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ajustes y bajas — todo lo que salió sin venderse.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LISTAR, CREAR Y VER. NUNCA EDITAR.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Guardar un ajuste ya movió el kardex y ya sincronizó la cantidad base
 * del costo. Editarlo después dejaría el documento diciendo una cosa y
 * las existencias otra —y el que gana siempre es el kardex, así que el
 * documento quedaría mintiendo sin que nada lo delate—. Un trigger de
 * PostgreSQL rechaza el `UPDATE`, y la policy devuelve `false` para que
 * el botón ni aparezca: prometer algo que la base va a rechazar es peor
 * que no ofrecerlo.
 *
 * Un ajuste equivocado se corrige con OTRO ajuste, de tipo corrección y
 * con su explicación.
 */
class AjusteResource extends Resource
{
    protected static ?string $model = Ajuste::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'ajustes';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'motivo';

    public static function getNavigationLabel(): string
    {
        return 'Ajustes y bajas';
    }

    public static function getBreadcrumb(): string
    {
        return 'Ajustes y bajas';
    }

    public static function getModelLabel(): string
    {
        return 'ajuste';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ajustes';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * ⚠️ Sin badge de navegación a propósito.
     *
     * Un contador acá sería un `COUNT` sobre una tabla que crece para
     * siempre, en cada carga de página de todo el hospital (§9.A15), y no
     * contestaría ninguna pregunta: que haya ajustes es normal. La
     * pregunta que sí importa —cuáles necesitaron autorización— es un
     * filtro de la tabla, que se mira cuando se va a mirar.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['motivo', 'referencia'];
    }

    /**
     * @return Builder<Ajuste>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Ajuste> $consulta */
        $consulta = parent::getEloquentQuery();

        AlmacenesDelUsuario::filtrar($consulta);

        return $consulta;
    }

    public static function form(Schema $schema): Schema
    {
        return AjusteForm::configure($schema);
    }

    /**
     * La ficha tiene su propio esquema y no reusa el formulario: el
     * formulario trae el escaneo y un repeater pensados para CAPTURAR, y
     * el repeater ni siquiera está atado a la relación —las líneas las
     * escribe el registrador—, así que en modo lectura se vería vacío.
     */
    public static function infolist(Schema $schema): Schema
    {
        return AjusteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AjustesTable::configure($table);
    }

    /**
     * @return array<int, mixed>
     */
    public static function getRelations(): array
    {
        return [
            LineasDelAjusteRelationManager::class,
        ];
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
            'index'  => ListAjustes::route('/'),
            'create' => CreateAjuste::route('/create'),
            'view'   => VerAjuste::route('/{record}'),
        ];
    }
}
