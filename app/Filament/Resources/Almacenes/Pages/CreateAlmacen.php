<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Pages;

use App\Domain\Enums\TipoAlmacen;
use App\Filament\Resources\Almacenes\AlmacenResource;
use App\Services\AsignadorDeCodigoDeAlmacen;
use App\Support\ContextoSede;
use Filament\Resources\Pages\CreateRecord;

class CreateAlmacen extends CreateRecord
{
    protected static string $resource = AlmacenResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * El código se pone acá y no en la pantalla.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EN EL SERVIDOR, NO EN UN `default()` DEL FORMULARIO
     * ─────────────────────────────────────────────────────────────────
     *
     * Un valor calculado al ABRIR el formulario se calcula una sola vez,
     * y para cuando alguien termina de llenarlo ya puede estar tomado —
     * dos personas creando carritos al mismo tiempo se llevan el mismo
     * SRV-01 y la segunda choca contra el índice único con un error de
     * base de datos en la cara.
     *
     * Acá se calcula en el instante de guardar, que es lo más cerca del
     * INSERT que se puede estar sin meterse en la transacción.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['codigo'] ?? null)) {
            return $data;
        }

        $tipo = $data['tipo'] ?? null;

        $data['codigo'] = app(AsignadorDeCodigoDeAlmacen::class)->siguiente(
            $tipo instanceof TipoAlmacen ? $tipo : TipoAlmacen::from(is_string($tipo) ? $tipo : TipoAlmacen::BodegaCentral->value),
            is_numeric($data['sede_id'] ?? null) ? (int) $data['sede_id'] : ContextoSede::actualId(),
        );

        return $data;
    }
}
