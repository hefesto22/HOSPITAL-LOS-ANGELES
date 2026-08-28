<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas;

use App\Filament\Resources\Cuentas\Pages\ListCuentas;
use App\Filament\Resources\Cuentas\Pages\VerCuenta;
use App\Filament\Resources\Cuentas\RelationManagers\CargosRelationManager;
use App\Filament\Resources\Cuentas\Schemas\CuentaInfolist;
use App\Filament\Resources\Cuentas\Tables\CuentasTable;
use App\Models\Cuenta;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las cuentas — la ficha, no el mostrador.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTE RESOURCE NO CREA NI EDITA, Y ES A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Abrir una cuenta y cargarle cosas se hace en la pantalla de cuentas
 * abiertas, que es una página Livewire dedicada (§9.A10). Acá se
 * CONSULTA: es lo que dirección y auditoría necesitan para revisar qué
 * se le cobró a quién y por qué, y lo que caja va a necesitar en el
 * bloque 7 para liquidar.
 *
 * Un formulario de edición sería una puerta trasera al motor de cargos:
 * permitiría guardar una cuenta cuyos totales no cuadran con sus
 * líneas — y de ahí no se vuelve.
 */
class CuentaResource extends Resource
{
    protected static ?string $model = Cuenta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'cuentas-de-pacientes';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function getNavigationLabel(): string
    {
        return 'Cuentas (consulta)';
    }

    public static function getBreadcrumb(): string
    {
        return 'Cuentas';
    }

    public static function getModelLabel(): string
    {
        return 'cuenta';
    }

    public static function getPluralModelLabel(): string
    {
        return 'cuentas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    /**
     * @return Builder<Cuenta>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Cuenta> $consulta */
        $consulta = parent::getEloquentQuery();

        /*
         * El alcance por sede lo pone el trait `BelongsToSede` con un
         * scope global. Se deja explícito el eager loading porque la
         * tabla muestra paciente y pagador en cada fila, y sin esto son
         * cincuenta consultas por página (§13.2).
         */
        return $consulta->with([
            'encuentro:id,numero,tipo,estado,abierto_en,persona_id',
            'encuentro.persona:id,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,apellido_casada',
            'convenio:id,codigo,nombre,tipo',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CuentaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CuentasTable::configure($table);
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
     * @return array<int, mixed>
     */
    public static function getRelations(): array
    {
        return [
            CargosRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCuentas::route('/'),
            'view'  => VerCuenta::route('/{record}'),
        ];
    }
}
