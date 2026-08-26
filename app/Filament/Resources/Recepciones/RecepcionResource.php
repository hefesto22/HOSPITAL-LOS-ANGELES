<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones;

use App\Filament\Resources\Recepciones\Pages\CreateRecepcion;
use App\Filament\Resources\Recepciones\Pages\ListRecepciones;
use App\Filament\Resources\Recepciones\Pages\ViewRecepcion;
use App\Filament\Resources\Recepciones\Schemas\RecepcionForm;
use App\Filament\Resources\Recepciones\Schemas\RecepcionInfolist;
use App\Filament\Resources\Recepciones\Tables\RecepcionesTable;
use App\Models\Recepcion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Recepciones — meter mercadería al estante, rápido.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO HAY PANTALLA DE EDICIÓN, Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Guardar una recepción ya movió el kardex y ya recalculó el costo
 * promedio. Editarla después dejaría el documento diciendo una cosa y las
 * existencias otra — y el que gana siempre es el kardex, así que el
 * documento quedaría mintiendo sin que nada lo delate.
 *
 * Por eso las páginas son **listar, crear y ver**. Si una recepción
 * estuvo mal, se corrige con un ajuste, que pide motivo y deja rastro.
 *
 * El badge de la navegación cuenta las que faltan revisar: es la
 * contracara de haber sacado el paso de confirmación, y solo sirve si
 * está a la vista.
 */
class RecepcionResource extends Resource
{
    protected static ?string $model = Recepcion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $slug = 'recepciones';

    protected static ?int $navigationSort = 71;

    protected static ?string $recordTitleAttribute = 'referencia';

    public static function getNavigationLabel(): string
    {
        return 'Recibir mercadería';
    }

    public static function getModelLabel(): string
    {
        return 'recepción';
    }

    public static function getPluralModelLabel(): string
    {
        return 'recepciones';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    /**
     * Cuántas faltan revisar, en el menú.
     *
     * Nulo cuando no hay ninguna: un cero permanente al lado del menú se
     * vuelve invisible en una semana, y entonces el número deja de
     * llamar la atención el día que sí importa.
     */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = static::getModel()::query()->whereNull('revisada_en')->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Recepciones que todavía no revisó nadie. La mercadería ya está en el kardex.';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['referencia'];
    }

    public static function form(Schema $schema): Schema
    {
        return RecepcionForm::configure($schema);
    }

    /**
     * La pantalla de ver tiene su propio esquema y no reusa el
     * formulario: el formulario trae el campo de escaneo y un repeater
     * pensados para CAPTURAR, y el repeater ni siquiera está atado a la
     * relación —las líneas las escribe el registrador—, así que en modo
     * lectura se vería vacío.
     */
    public static function infolist(Schema $schema): Schema
    {
        return RecepcionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecepcionesTable::configure($table);
    }

    /**
     * Una recepción no se borra: explica movimientos de un kardex
     * append-only.
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
            'index'  => ListRecepciones::route('/'),
            'create' => CreateRecepcion::route('/create'),
            'view'   => ViewRecepcion::route('/{record}'),
        ];
    }
}
