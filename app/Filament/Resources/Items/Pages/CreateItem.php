<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Filament\Resources\Items\ItemResource;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Services\AsignadorDeCodigoDeItem;
use App\Services\PreciosDeLaFicha;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un ítem, con sus precios puestos en el mismo acto: el de lista
 * y, si se quiere, el de cada seguro (`PreciosDeLaFicha`, 13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL PRECIO SE ESCRIBE ACÁ Y NO EN OTRA PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dar de alta un ítem y decir cuánto cuesta son el MISMO acto para quien
 * lo hace. Separarlos deja el hueco de siempre: el ítem existe, se puede
 * buscar y se puede elegir en una cuenta, y recién ahí —con el paciente
 * enfrente— aparece «este ítem no tiene precio para este pagador».
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PRECIO NO ES UNA COLUMNA DEL ÍTEM
 * ─────────────────────────────────────────────────────────────────────
 *
 * Van a `tarifarios`, con vigencia y con motivo, igual que cualquier otro
 * precio (ADR-0003). Por eso el campo se saca de los datos ANTES de
 * crear: si llegara al `create()`, Laravel lo descartaría en silencio
 * por no estar en `$fillable` y el ítem quedaría sin precio sin que
 * nadie viera un error.
 *
 * Y en la MISMA transacción: si un precio no se puede fijar, el servicio
 * tampoco se crea. Un servicio a medio crear, sin precio, es el hueco que
 * esta pantalla existe para cerrar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS DESCUENTOS NO PASAN POR ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * El selector «Descuentos que aplican» es una relación de Filament: se
 * sincroniza solo, después de crear el ítem, sin que esta clase toque
 * nada.
 *
 * Antes había tres campos que escribían un porcentaje de ley desde este
 * formulario, y se sacaron. El porcentaje del Art. 30 es de la CATEGORÍA
 * entera —todas las radiografías, no esta radiografía—, así que un campo
 * capaz de reescribirlo no puede vivir en la ficha de un producto. Se
 * carga en «Descuentos» o en «Descuentos de ley», con fecha y con
 * fundamento.
 */
class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL CÓDIGO SE ASIGNA ACÁ, NO EN EL FORMULARIO
         * ─────────────────────────────────────────────────────────────
         *
         * El formulario ya no lo pide: es un correlativo interno con el
         * prefijo de la categoría, y el sistema sabe cuál es el
         * siguiente libre.
         *
         * Se calcula en el ÚLTIMO momento a propósito. Cuando se
         * proponía al elegir la categoría, dos personas cargando al
         * mismo tiempo veían el mismo número y la segunda chocaba contra
         * el índice único al guardar —después de haber llenado toda la
         * ficha—.
         */
        if (! isset($data['codigo']) || ! is_string($data['codigo']) || trim($data['codigo']) === '') {
            $categoria = is_numeric($data['categoria_id'] ?? null)
                ? CategoriaItem::query()->find((int) $data['categoria_id'])
                : null;

            if ($categoria instanceof CategoriaItem) {
                $data['codigo'] = app(AsignadorDeCodigoDeItem::class)->siguiente($categoria);
            }
        }

        return $data;
    }

    /**
     * El servicio y sus precios, juntos o ninguno.
     *
     * @param array<string, mixed> $data
     *
     * @throws Halt
     */
    protected function handleRecordCreation(array $data): Model
    {
        $precios = is_array($data['precios'] ?? null) ? $data['precios'] : [];

        unset($data['precios'], $data['precios_motivo']);

        try {
            /** @var Model $creado */
            $creado = DB::transaction(function () use ($data, $precios): Model {
                $item = parent::handleRecordCreation($data);

                if ($item instanceof Item) {
                    app(PreciosDeLaFicha::class)->aplicar($item, $precios, null, esAlta: true);
                }

                return $item;
            });
        } catch (PrecioNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se creó el servicio')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }

        return $creado;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Servicio creado, con sus precios';
    }
}
