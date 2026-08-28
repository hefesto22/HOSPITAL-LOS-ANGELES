<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes;

use App\Filament\Resources\Pacientes\Pages\CreatePaciente;
use App\Filament\Resources\Pacientes\Pages\EditPaciente;
use App\Filament\Resources\Pacientes\Pages\ListPacientes;
use App\Filament\Resources\Pacientes\Pages\VerPaciente;
use App\Filament\Resources\Pacientes\Schemas\PacienteForm;
use App\Filament\Resources\Pacientes\Schemas\PacienteInfolist;
use App\Filament\Resources\Pacientes\Tables\PacientesTable;
use App\Models\Persona;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pacientes — la cara visible del MPI (§8.2).
 *
 * ⚠️ El modelo es `Persona`, no un modelo "Paciente", y no es un descuido.
 *
 * La identidad es única para toda la organización; lo que convierte a
 * alguien en paciente de una sede es tener EXPEDIENTE ahí. Por eso el
 * acompañante y el responsable de pago también son personas y pueden no
 * tener ninguno. Un modelo "Paciente" separado obligaría a duplicar a la
 * misma persona el día que pasa de acompañante a paciente, que es
 * exactamente el duplicado que este módulo existe para evitar.
 */
class PacienteResource extends Resource
{
    protected static ?string $model = Persona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $slug = 'pacientes';

    protected static ?int $navigationSort = 6;

    /*
     * Sin esto Filament derivaría el título de la fila del `id`. El
     * atributo de título es lo que sale en los breadcrumbs y en los
     * selectores.
     */
    protected static ?string $recordTitleAttribute = 'primer_apellido';

    public static function getNavigationLabel(): string
    {
        return 'Pacientes';
    }

    public static function getBreadcrumb(): string
    {
        return 'Pacientes';
    }

    public static function getModelLabel(): string
    {
        return 'paciente';
    }

    public static function getPluralModelLabel(): string
    {
        return 'pacientes';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Atención';
    }

    /**
     * ⚠️ `whereNull('merged_into')` NO es un filtro cosmético.
     *
     * Sin él, el listado ofrece de nuevo los duplicados que alguien ya se
     * tomó el trabajo de fusionar, y admisión vuelve a abrir el expediente
     * equivocado. La fila fusionada sigue existiendo —el §9.D4 exige que
     * la fusión sea reversible— pero deja de ser elegible.
     *
     * @return Builder<Persona>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Persona> $consulta */
        $consulta = parent::getEloquentQuery();

        return $consulta
            ->whereNull('merged_into')
            ->with('identificadores')
            ->withCount('expedientes');
    }

    public static function form(Schema $schema): Schema
    {
        return PacienteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PacienteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PacientesTable::configure($table);
    }

    /**
     * Un paciente NUNCA se borra.
     *
     * Su expediente es append-only (ADR-0004) y su identidad es la llave
     * de todo lo clínico y lo facturado. El mecanismo para un registro
     * creado por error es la FUSIÓN, que no borra ni mueve filas y se
     * puede deshacer.
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
            'index'  => ListPacientes::route('/'),
            'create' => CreatePaciente::route('/nuevo'),
            'view'   => VerPaciente::route('/{record}'),
            'edit'   => EditPaciente::route('/{record}/editar'),
        ];
    }
}
