<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios;

use App\Filament\Resources\Convenios\Pages\CreateConvenio;
use App\Filament\Resources\Convenios\Pages\EditConvenio;
use App\Filament\Resources\Convenios\Pages\ListConvenios;
use App\Filament\Resources\Convenios\RelationManagers\CondicionesRelationManager;
use App\Filament\Resources\Convenios\Schemas\ConvenioForm;
use App\Filament\Resources\Convenios\Tables\ConveniosTable;
use App\Models\Convenio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Seguros y convenios — quién paga cada cuenta.
 *
 * Va en pantalla completa y no en modal, al revés que unidades: dar de
 * alta un pagador incluye declarar sobre qué monto se le aplica el
 * descuento del adulto mayor, cuánto cubre de cada cuenta y de qué base
 * hereda sus precios. Eso no cabe —ni debe caber— en un modal de paso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL RÓTULO DICE «SEGUROS Y CONVENIOS» Y NO SOLO «SEGUROS»
 * ─────────────────────────────────────────────────────────────────────
 *
 * En el mostrador todo esto se llama «el seguro del paciente», y esa es
 * la palabra que tiene que estar en el menú para que alguien lo
 * encuentre. Pero acá adentro también vive CONTADO —que no es un
 * seguro— y los convenios institucionales con empresas, que tampoco lo
 * son. Rotularlo «Seguros» a secas haría que nadie busque CONTADO acá, y
 * CONTADO es el pagador de la mayoría de las cuentas.
 */
class ConvenioResource extends Resource
{
    protected static ?string $model = Convenio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'convenios';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationLabel(): string
    {
        return 'Seguros y convenios';
    }

    public static function getModelLabel(): string
    {
        return 'seguro o convenio';
    }

    public static function getPluralModelLabel(): string
    {
        return 'seguros y convenios';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogos y precios';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }

    public static function form(Schema $schema): Schema
    {
        return ConvenioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConveniosTable::configure($table);
    }

    /**
     * Un convenio no se borra: se le pone fecha de fin de vigencia.
     *
     * Borrarlo dejaría cuentas y facturas apuntando a un pagador que ya
     * no existe, y una factura que no se puede reimprimir es un problema
     * ante el SAR, no una fila de menos.
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
     * El porcentaje pactado se carga DENTRO de la ficha del convenio.
     *
     * Una condición sin su convenio no significa nada, y una pantalla
     * aparte obligaría a elegir el pagador de un selector — que es como
     * se termina pactando el 85 % con la aseguradora equivocada.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            CondicionesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListConvenios::route('/'),
            'create' => CreateConvenio::route('/create'),
            'edit'   => EditConvenio::route('/{record}/edit'),
        ];
    }
}
