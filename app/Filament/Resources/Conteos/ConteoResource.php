<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos;

use App\Filament\Resources\Conteos\Pages\ContarConteo;
use App\Filament\Resources\Conteos\Pages\CreateConteo;
use App\Filament\Resources\Conteos\Pages\ListConteos;
use App\Filament\Resources\Conteos\Pages\VerConteo;
use App\Filament\Resources\Conteos\RelationManagers\LineasRelationManager;
use App\Filament\Resources\Conteos\Schemas\ConteoForm;
use App\Filament\Resources\Conteos\Schemas\ConteoInfolist;
use App\Filament\Resources\Conteos\Tables\ConteosTable;
use App\Models\Conteo;
use App\Support\AlmacenesDelUsuario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conteo físico — contar el estante y cuadrarlo con el sistema.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES PANTALLAS, Y NINGUNA ES UN FORMULARIO DE EDICIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · **abrir** — un formulario corto: qué almacén, total o parcial;
 *   · **contar** — la pantalla que se usa de pie frente al estante, con
 *     la pistola de código de barras y sin mouse (§9.A10);
 *   · **ver** — la revisión y el cierre, que es lo que mueve el kardex.
 *
 * No hay página de edición y no puede haberla: un conteo cerrado explica
 * movimientos de un kardex append-only, y la base rechaza cualquier
 * cambio con un trigger.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ALCANCE POR ALMACÉN VA EN LA CONSULTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.L5. Bodega ve los conteos de la bodega central y de los stocks de
 * servicio; farmacia, los de la farmacia; dirección y auditoría, todos.
 * Filtrar acá —y no fila por fila en la policy— cuesta una subconsulta en
 * vez de veinticinco, y de paso tapa el agujero típico de Filament: abrir
 * por URL el registro de otro, que para esta consulta no existe.
 */
class ConteoResource extends Resource
{
    protected static ?string $model = Conteo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'conteos';

    protected static ?int $navigationSort = 72;

    protected static ?string $recordTitleAttribute = 'descripcion';

    public static function getNavigationLabel(): string
    {
        return 'Conteo físico';
    }

    public static function getBreadcrumb(): string
    {
        return 'Conteos físicos';
    }

    public static function getModelLabel(): string
    {
        return 'conteo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'conteos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * Cuántos conteos hay abiertos ahora mismo.
     *
     * Nulo cuando no hay ninguno: un cero permanente al lado del menú se
     * vuelve invisible en una semana, y entonces el número deja de llamar
     * la atención el día que sí importa.
     *
     * Es UN `COUNT` sobre un índice parcial que solo contiene los
     * abiertos —normalmente cero o una fila—, así que el §9.A15 no
     * aplica: acá no hay tabla grande que recorrer.
     */
    public static function getNavigationBadge(): ?string
    {
        /** @var Builder<Conteo> $consulta */
        $consulta = static::getModel()::query();

        AlmacenesDelUsuario::filtrar($consulta);

        $abiertos = $consulta->abiertos()->count();

        return $abiertos > 0 ? (string) $abiertos : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Conteos abiertos. Mientras uno esté abierto no se puede abrir otro en ese almacén.';
    }

    /**
     * @return Builder<Conteo>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Conteo> $consulta */
        $consulta = parent::getEloquentQuery();

        AlmacenesDelUsuario::filtrar($consulta);

        return $consulta;
    }

    public static function form(Schema $schema): Schema
    {
        return ConteoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConteoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConteosTable::configure($table);
    }

    /**
     * Un conteo no se borra: se anula con motivo y queda visible.
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
     * Las líneas se ven en la ficha, paginadas.
     *
     * No como `RepeatableEntry` dentro del infolist: un conteo total de
     * trescientas líneas renderizaría trescientos bloques en una sola
     * carga. Un RelationManager pagina y filtra sin pensarlo.
     *
     * @return array<int, mixed>
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
            'index'  => ListConteos::route('/'),
            'create' => CreateConteo::route('/create'),
            'contar' => ContarConteo::route('/{record}/contar'),
            'view'   => VerConteo::route('/{record}'),
        ];
    }
}
