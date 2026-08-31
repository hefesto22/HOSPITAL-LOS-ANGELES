<?php

declare(strict_types=1);

namespace App\Filament\Resources\Especialidades;

use App\Filament\Resources\Especialidades\Pages\CreateEspecialidad;
use App\Filament\Resources\Especialidades\Pages\EditEspecialidad;
use App\Filament\Resources\Especialidades\Pages\ListEspecialidades;
use App\Filament\Resources\Especialidades\Schemas\EspecialidadForm;
use App\Filament\Resources\Especialidades\Tables\EspecialidadesTable;
use App\Models\Especialidad;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Especialidades médicas.
 *
 * Existe para que «CIRUGÍA GENERAL» sea una sola cosa y no tres formas
 * de escribirla: sobre texto libre no se puede contestar cuánto se le
 * pagó este mes a los anestesiólogos.
 */
class EspecialidadResource extends Resource
{
    protected static ?string $model = Especialidad::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $slug = 'especialidades';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return 'Especialidades';
    }

    public static function getModelLabel(): string
    {
        return 'especialidad';
    }

    public static function getPluralModelLabel(): string
    {
        return 'especialidades';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración del hospital';
    }

    /**
     * @return Builder<Especialidad>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Especialidad> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta->withCount('medicos');
    }

    public static function form(Schema $schema): Schema
    {
        return EspecialidadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EspecialidadesTable::configure($table);
    }

    /**
     * Una especialidad con médicos adentro no se borra: se cierra con
     * vigencia. La FK es restrictOnDelete, así que borrarla fallaría de
     * todas formas (§9.A17).
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
            'index'  => ListEspecialidades::route('/'),
            'create' => CreateEspecialidad::route('/create'),
            'edit'   => EditEspecialidad::route('/{record}/edit'),
        ];
    }
}
