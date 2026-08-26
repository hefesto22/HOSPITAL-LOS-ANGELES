<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Pages;

use App\Filament\Resources\Presupuestos\PresupuestoResource;
use App\Models\Convenio;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\PlantillaPresupuesto;
use App\Models\Sede;
use App\Services\CotizadorDePresupuesto;
use App\Support\ContextoSede;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * ⚠️ El presupuesto NO lo crea Eloquent con el `$data` del formulario:
 * lo arma `CotizadorDePresupuesto`, que resuelve cada precio contra el
 * tarifario del convenio y congela el número.
 *
 * Dejar que Filament haga el `create()` produciría un encabezado sin
 * renglones y con totales en cero — un presupuesto que dice L 0.00.
 */
class CreatePresupuesto extends CreateRecord
{
    protected static string $resource = PresupuestoResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $sedeId = ContextoSede::actualId();

        if ($sedeId === null) {
            throw new RuntimeException('No hay sede activa: no se puede cotizar sin saber en qué sede.');
        }

        $cotizador = app(CotizadorDePresupuesto::class);

        $sede = Sede::query()->findOrFail($sedeId);
        $expediente = Expediente::query()->findOrFail($data['expediente_id']);
        $convenio = Convenio::query()->findOrFail($data['convenio_id']);

        $encuentro = isset($data['encuentro_id']) && is_numeric($data['encuentro_id'])
            ? Encuentro::query()->find((int) $data['encuentro_id'])
            : null;

        $titulo = is_string($data['titulo'] ?? null) ? $data['titulo'] : 'PRESUPUESTO';
        $notas = is_string($data['notas'] ?? null) ? $data['notas'] : null;

        $plantilla = isset($data['plantilla_id']) && is_numeric($data['plantilla_id'])
            ? PlantillaPresupuesto::query()->with('lineas.item')->find((int) $data['plantilla_id'])
            : null;

        $presupuesto = $plantilla instanceof PlantillaPresupuesto
            ? $cotizador->desdePlantilla(
                plantilla: $plantilla,
                expediente: $expediente,
                convenio: $convenio,
                sede: $sede,
                fecha: now(),
                encuentro: $encuentro,
                titulo: $titulo,
            )
            : $cotizador->abrirBorrador(
                expediente: $expediente,
                convenio: $convenio,
                sede: $sede,
                titulo: $titulo,
                encuentro: $encuentro,
            );

        if ($notas !== null) {
            $presupuesto->update(['notas' => $notas]);
        }

        return $presupuesto->refresh();
    }

    /**
     * Al listado no: a la edición, que es donde están los renglones.
     * Un presupuesto recién creado casi siempre necesita un ajuste.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
