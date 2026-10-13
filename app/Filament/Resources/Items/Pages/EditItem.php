<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Filament\Resources\Items\Actions\CalcularPrecioAction;
use App\Filament\Resources\Items\Actions\MoverDeAmbitoAction;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Services\PreciosDeLaFicha;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Los descuentos que se marcan en la pestaña «ISV y descuentos» son una
 * relación de Filament: se sincronizan solos al guardar. Esta clase no
 * tiene que sacarlos del formulario ni escribirlos.
 *
 * Los precios sí (13-oct-2026): se escriben en la misma ficha y no son
 * columnas del ítem. Se sacan de los datos y los fija `PreciosDeLaFicha`,
 * en la misma transacción que el resto: si uno no se puede fijar, no se
 * guarda nada y el aviso dice por qué.
 */
class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    /**
     * A quién se le cambió el precio al guardar, para el aviso.
     *
     * @var list<string>
     */
    protected array $preciosCambiados = [];

    /**
     * Sin acción de borrar: el ítem se retira poniéndole fecha de fin de
     * vigencia. Borrarlo dejaría cargos apuntando a un ítem inexistente y
     * una factura que ya no se puede reimprimir.
     *
     * La calculadora sí está acá, y solo en lo que se compra: un
     * honorario no tiene costo de entrada, así que el botón abriría un
     * modal que solo sabe decir que no (Ruta B del §4.1).
     *
     * Y acá vive «mover de ámbito», que antes era un ícono en cada fila
     * del listado. Es la salida de la jaula —sin ella, la jeringa que
     * alguien cargó en «Catálogo» se queda ahí para siempre— pero se usa
     * una vez cada varios meses: su lugar es la ficha del ítem, no
     * doscientas filas de una tabla que se lee todos los días.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * El historial de precios, a un clic y sin pestañas debajo de
             * la ficha (13-oct-2026): es lo que contesta «¿por qué esta
             * factura de marzo dice L 29.33?».
             */
            Action::make('historialDePrecios')
                ->label('Historial de precios')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->modalHeading(fn (Item $record): string => 'Historial de precios · '.$record->nombre)
                ->modalDescription('Cada precio, desde cuándo rigió, hasta cuándo y por qué. Las facturas guardan el suyo: cambiarlo no las toca.')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->modalContent(fn (Item $record): View => view('filament.pages.partials.historial-de-precios', [
                    'filas' => self::historialDe($record),
                ])),

            /*
             * `true` = con botón de guardar. Llegar a esta pantalla ya
             * exige permiso para modificar el ítem, así que la
             * autorización queda resuelta por dónde se entró. Desde el
             * listado la misma acción es de solo lectura.
             */
            CalcularPrecioAction::make(puedeGuardar: true)
                ->visible(fn (Item $record): bool => CalcularPrecioAction::puedeVerse($record)),

            /*
             * Al listado después de mover, y no de vuelta a esta ficha:
             * el ítem ya no es de este recurso. `Producto` y `Item`
             * tienen alcances distintos, así que recargar esta misma URL
             * daría un 404 con cara de error del sistema.
             *
             * El redirect solo corre si el movimiento se hizo: la acción
             * hace `halt()` cuando el producto tiene inventario escrito,
             * y ahí la pantalla se queda quieta con el aviso a la vista.
             */
            MoverDeAmbitoAction::make()
                ->successRedirectUrl(fn (): string => $this->getResource()::getUrl('index')),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Halt
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $precios = is_array($data['precios'] ?? null) ? $data['precios'] : [];
        $porQue = is_string($data['precios_motivo'] ?? null) ? $data['precios_motivo'] : null;

        unset($data['precios'], $data['precios_motivo']);

        try {
            /** @var Model $guardado */
            $guardado = DB::transaction(function () use ($record, $data, $precios, $porQue): Model {
                $item = parent::handleRecordUpdate($record, $data);

                if ($item instanceof Item) {
                    $this->preciosCambiados = app(PreciosDeLaFicha::class)->aplicar($item, $precios, $porQue, esAlta: false);
                }

                return $item;
            });
        } catch (PrecioNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se guardó')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }

        return $guardado;
    }

    /**
     * Todos los precios del ítem, el más nuevo arriba.
     *
     * @return list<array{pagador: string, donde: string|null, precio: string, desde: string, hasta: string|null, motivo: string}>
     */
    private static function historialDe(Item $item): array
    {
        $filas = [];

        $precios = Tarifario::query()
            ->with(['convenio:id,nombre', 'sede:id,nombre', 'presentacion:id,item_id,nombre'])
            ->where('item_id', $item->id)
            ->orderByDesc('vigencia_desde')
            ->orderBy('convenio_id')
            ->limit(300)
            ->get();

        foreach ($precios as $precio) {
            $convenio = $precio->convenio_id === null ? null : $precio->convenio;
            $sede = $precio->sede_id === null ? null : $precio->sede;
            $variante = $precio->item_presentacion_id === null ? null : $precio->presentacion;

            $donde = array_filter([
                $sede instanceof Sede ? $sede->nombre : null,
                $variante instanceof ItemPresentacion ? $variante->envase() : null,
            ]);

            $filas[] = [
                'pagador' => $convenio instanceof Convenio ? $convenio->nombre : 'Precio de lista',
                'donde'   => $donde === [] ? null : implode(' · ', $donde),
                'precio'  => $precio->monto()->formateado(),
                'desde'   => $precio->vigencia_desde->format('d/m/Y'),
                'hasta'   => $precio->vigencia_hasta?->format('d/m/Y'),
                'motivo'  => $precio->motivo,
            ];
        }

        return $filas;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return match (count($this->preciosCambiados)) {
            0       => 'Guardado',
            1       => 'Guardado · precio nuevo para '.$this->preciosCambiados[0],
            default => 'Guardado · precios nuevos para '.implode(', ', $this->preciosCambiados),
        };
    }
}
