<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Pages;

use App\Filament\Resources\PlantillasPresupuesto\PlantillaPresupuestoResource;
use App\Services\AsignadorDeCodigoDePlantilla;
use Filament\Resources\Pages\CreateRecord;

class CreatePlantillaPresupuesto extends CreateRecord
{
    protected static string $resource = PlantillaPresupuestoResource::class;

    /**
     * El código se arma acá y no en el formulario.
     *
     * ⚠️ Al GUARDAR, y no mientras se escribe el nombre: dos personas
     * cargando plantillas a la vez verían el mismo código propuesto y la
     * segunda chocaría contra el índice único al grabar. Acá el
     * `exists()` y el INSERT están a milisegundos, no a minutos.
     *
     * Si por lo que sea ya viniera uno —una importación, una prueba— se
     * respeta: este método propone, no pisa.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $codigo = $data['codigo'] ?? null;

        if (is_string($codigo) && trim($codigo) !== '') {
            return $data;
        }

        $nombre = $data['nombre'] ?? null;

        $data['codigo'] = app(AsignadorDeCodigoDePlantilla::class)
            ->siguiente(is_string($nombre) ? $nombre : '');

        return $data;
    }

    /**
     * Después de crearla se va a la edición, que es donde están las
     * líneas: una plantilla sin renglones no sirve para nada, y mandar
     * al listado invita a dejarla vacía.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
