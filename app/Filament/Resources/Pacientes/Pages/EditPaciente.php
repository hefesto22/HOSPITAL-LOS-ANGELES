<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Filament\Resources\Pacientes\PacienteResource;
use App\Models\Persona;
use App\Services\ActualizadorDePersona;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edición de los datos demográficos.
 *
 * ⚠️ Tampoco usa el guardado por defecto. Si el formulario escribiera
 * directo al modelo, los cambios posteriores al alta no dejarían
 * historial y el ADR-0004 quedaría a medias: se sabría cómo entró el
 * paciente y nunca más cómo fue cambiando.
 *
 * `ActualizadorDePersona` aplica el cambio y escribe la versión — o no la
 * escribe, si no cambió nada.
 */
class EditPaciente extends EditRecord
{
    protected static string $resource = PacienteResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver ficha'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $motivo = $data['motivo_cambio'] ?? null;
        unset($data['motivo_cambio']);

        /** @var Persona $record */
        return app(ActualizadorDePersona::class)->actualizar(
            $record,
            $data,
            is_string($motivo) && trim($motivo) !== ''
                ? trim($motivo)
                : 'Corrección de datos desde el panel',
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
