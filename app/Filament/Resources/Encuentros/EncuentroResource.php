<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros;

use App\Filament\Resources\Encuentros\Pages\ListEncuentros;
use App\Filament\Resources\Encuentros\Pages\VerEncuentro;
use App\Filament\Resources\Encuentros\Schemas\EncuentroInfolist;
use App\Filament\Resources\Encuentros\Tables\EncuentrosTable;
use App\Models\Encuentro;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los encuentros — de dónde cuelga todo (§8.3).
 *
 * De consulta, igual que las cuentas: abrir un encuentro se hace desde
 * la pantalla de cuentas abiertas, porque abrirlo sin cuenta deja a un
 * paciente sin dónde acumular lo que consume.
 *
 * Los tres tiempos del egreso (§9.K8) están a la vista en la ficha
 * porque son el indicador que mide la demora del egreso — el mayor
 * devorador de capacidad de un hospital.
 */
class EncuentroResource extends Resource
{
    protected static ?string $model = Encuentro::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $slug = 'encuentros';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Encuentros';
    }

    public static function getBreadcrumb(): string
    {
        return 'Encuentros';
    }

    public static function getModelLabel(): string
    {
        return 'encuentro';
    }

    public static function getPluralModelLabel(): string
    {
        return 'encuentros';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    /**
     * @return Builder<Encuentro>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Encuentro> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->with([
            'persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,apellido_casada',
            'expediente:id,numero',
            'servicio:id,nombre',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EncuentroInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EncuentrosTable::configure($table);
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
            'index' => ListEncuentros::route('/'),
            'view'  => VerEncuentro::route('/{record}'),
        ];
    }
}
