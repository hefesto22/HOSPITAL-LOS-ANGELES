<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos;

use App\Filament\Resources\Medicos\Pages\CreateMedico;
use App\Filament\Resources\Medicos\Pages\EditMedico;
use App\Filament\Resources\Medicos\Pages\ListMedicos;
use App\Filament\Resources\Medicos\Schemas\MedicoForm;
use App\Filament\Resources\Medicos\Tables\MedicosTable;
use App\Models\Medico;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los médicos que cobran honorario en el hospital.
 *
 * 🔴 NO son los usuarios del sistema: el cirujano externo que opera un
 * sábado cobra honorario y nunca entra a SIHLA. Si esto fuera una marca
 * sobre `users`, habría que crearle cuenta y contraseña a cada doctor
 * para poder cobrarle un honorario al paciente.
 */
class MedicoResource extends Resource
{
    protected static ?string $model = Medico::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $slug = 'medicos';

    protected static ?int $navigationSort = 31;

    public static function getNavigationLabel(): string
    {
        return 'Médicos';
    }

    public static function getModelLabel(): string
    {
        return 'médico';
    }

    public static function getPluralModelLabel(): string
    {
        return 'médicos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración del hospital';
    }

    /**
     * @return Builder<Medico>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Medico> $consulta */
        $consulta = parent::getEloquentQuery();

        /*
         * Sin enumerar columnas en el `with`. Una columna que falta acá
         * no da error: devuelve NULL, y el listado empieza a mentir en
         * silencio. Ya pasó tres veces con la pantalla de existencias.
         */
        return $consulta
            ->with('especialidad')
            ->withCount('honorarios');
    }

    public static function form(Schema $schema): Schema
    {
        return MedicoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MedicosTable::configure($table);
    }

    /**
     * Un médico con honorarios cobrados no se borra: se cierra con
     * vigencia. Borrarlo dejaría cargos apuntando a nadie, y esos cargos
     * son lo que se le liquida a fin de mes.
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
            'index'  => ListMedicos::route('/'),
            'create' => CreateMedico::route('/create'),
            'edit'   => EditMedico::route('/{record}/edit'),
        ];
    }
}
