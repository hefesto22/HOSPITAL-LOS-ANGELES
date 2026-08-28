<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai;

use App\Filament\Resources\RangosCai\Pages\CreateRangoCai;
use App\Filament\Resources\RangosCai\Pages\EditRangoCai;
use App\Filament\Resources\RangosCai\Pages\ListRangosCai;
use App\Filament\Resources\RangosCai\Schemas\RangoCaiForm;
use App\Filament\Resources\RangosCai\Tables\RangosCaiTable;
use App\Models\RangoCai;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * La resolución del SAR, tecleada tal cual.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES LA PANTALLA MÁS PELIGROSA DEL SISTEMA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un dígito mal copiado acá son todas las facturas del mes emitidas con
 * un número que no corresponde al rango autorizado. Eso no se corrige:
 * se anulan y se vuelven a emitir, con el hallazgo del SAR incluido.
 *
 * Por eso: la carga es de dirección, los campos que ya se usaron se
 * bloquean, y nada de esto se borra nunca.
 */
class RangoCaiResource extends Resource
{
    protected static ?string $model = RangoCai::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $slug = 'rangos-cai';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'cai';

    public static function getNavigationLabel(): string
    {
        return 'Rangos de CAI';
    }

    public static function getModelLabel(): string
    {
        return 'rango de CAI';
    }

    public static function getPluralModelLabel(): string
    {
        return 'rangos de CAI';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración del hospital';
    }

    public static function form(Schema $schema): Schema
    {
        return RangoCaiForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RangosCaiTable::configure($table);
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
            'index'  => ListRangosCai::route('/'),
            'create' => CreateRangoCai::route('/nuevo'),
            'edit'   => EditRangoCai::route('/{record}/editar'),
        ];
    }
}
