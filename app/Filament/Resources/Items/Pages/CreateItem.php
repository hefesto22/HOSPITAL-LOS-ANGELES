<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use App\Services\FijadorDePrecio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Alta de un ítem, con su precio de lista puesto en el mismo acto.
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
 * Va a `tarifarios`, con vigencia y con motivo, igual que cualquier otro
 * precio (ADR-0003). Por eso el campo se saca de los datos ANTES de
 * crear: si llegara al `create()`, Laravel lo descartaría en silencio
 * por no estar en `$fillable` y el ítem quedaría sin precio sin que
 * nadie viera un error.
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

    protected ?string $precioDeLista = null;

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
        $this->precioDeLista = is_scalar($data['precio_de_lista'] ?? null)
            ? trim((string) $data['precio_de_lista'])
            : null;

        unset($data['precio_de_lista']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $item = $this->getRecord();

        if (! $item instanceof Item) {
            return;
        }

        if ($this->precioDeLista === null || $this->precioDeLista === '' || ! is_numeric($this->precioDeLista)) {
            return;
        }

        /*
         * La vigencia del precio arranca cuando arranca el ítem, no hoy.
         * Un ítem que empieza a ofrecerse el mes que viene con un precio
         * que rige desde hoy deja una ventana en la que el precio existe
         * y el ítem no — y esa ventana la descubre el primero que
         * reimprime una cuenta vieja.
         */
        try {
            app(FijadorDePrecio::class)->fijar(
                item: $item,
                convenio: null,
                sede: null,
                precio: Monto::de($this->precioDeLista),
                motivo: 'Precio de lista fijado al dar de alta el ítem en el catálogo.',
                desde: $item->vigencia_desde,
            );
        } catch (PrecioNoFijableException $e) {
            Notification::make()
                ->warning()
                ->title('El ítem se creó, pero sin precio')
                ->body($e->getMessage().' Cargalo desde Bases de precios.')
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Precio de lista cargado')
            ->body('Ya se puede cobrar. Los precios de cada seguro se cargan desde Bases de precios.')
            ->send();
    }
}
