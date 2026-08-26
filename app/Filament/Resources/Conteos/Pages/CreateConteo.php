<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Pages;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\Conteos\ConteoResource;
use App\Models\Almacen;
use App\Services\AbridorDeConteo;
use App\Support\NumeroDeFormulario;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Abrir un conteo no lo hace Eloquent con el arreglo del formulario.
 *
 * Lo hace `AbridorDeConteo`, que además verifica que no haya otro
 * abierto en ese almacén, que sea un almacén del área de quien abre, y
 * —si el conteo es total— carga una línea por cada existencia con saldo.
 *
 * Guardar acá redirige DIRECTO a la pantalla de contar. Quien abre un
 * conteo va a contar; devolverlo a la lista para que busque el botón es
 * un clic que se paga de pie frente al estante.
 */
class CreateConteo extends CreateRecord
{
    protected static string $resource = ConteoResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $almacen = Almacen::query()->findOrFail($data['almacen_id']);

        try {
            return app(AbridorDeConteo::class)->abrir(
                almacen: $almacen,
                alcance: AlcanceDeConteo::from((string) ($data['alcance'] ?? 'parcial')),
                descripcion: is_string($data['descripcion'] ?? null) ? $data['descripcion'] : null,

                /*
                 * Acá el respaldo SÍ es seguro: tolerancia cero significa
                 * «cualquier diferencia exige recuento», que es el
                 * comportamiento más estricto. Un respaldo que aflojara
                 * un control no sería aceptable.
                 */
                tolerancia: NumeroDeFormulario::aDecimalO(
                    $data['tolerancia_recuento'] ?? null,
                    Decimal::cero(),
                ),
                notas: is_string($data['notas'] ?? null) ? $data['notas'] : null,
            );
        } catch (ConteoException|AjusteException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo abrir el conteo')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.almacen_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('contar', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Conteo abierto. A contar.';
    }
}
