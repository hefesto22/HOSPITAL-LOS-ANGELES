<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Models\Persona;
use App\Support\BitacoraDeLectura;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * La ficha del paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ABRIR ESTA PANTALLA QUEDA REGISTRADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El §9 obliga a llevar bitácora de LECTURA del expediente: quién lo
 * abrió, cuándo, desde dónde. Se hace acá, en el `mount`, y no en un
 * middleware ni "más adelante", porque una bitácora no se puede
 * reconstruir hacia atrás: el mes que se use la pantalla sin registrar es
 * un mes que queda sin auditoría, para siempre.
 *
 * `BitacoraDeLectura::registrar()` nunca lanza excepción — si el registro
 * falla, el médico igual abre el expediente y el fallo se reporta. El
 * mecanismo que existe para proteger al paciente no puede ser el que le
 * impide ser atendido.
 */
class VerPaciente extends ViewRecord
{
    protected static string $resource = PacienteResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $persona = $this->getRecord();

        if (! $persona instanceof Persona) {
            return;
        }

        BitacoraDeLectura::registrar(
            recursoTipo: Persona::class,
            recursoId: $persona->getKey(),
            pacienteId: $persona->getKey(),
            accion: AccionDeLectura::Ver,
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar datos'),
        ];
    }
}
