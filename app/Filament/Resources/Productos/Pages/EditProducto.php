<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\CambiosDeFormasDeVenta;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Schemas\FormasDeVenta;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Item;
use App\Models\PrecioMinimo;
use App\Models\Tarifario;
use App\Services\CambiadorDeStockInfinito;
use App\Services\ReajustadorDePrecios;
use App\Services\SincronizadorDeFormasDeVenta;
use App\Services\SincronizadorDePrincipiosActivos;
use App\Services\UbicadorDeProducto;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Ficha de un producto de farmacia.
 *
 * Igual que en el catálogo: sin acción de borrar —se retira con fecha de
 * fin de vigencia— y con la calculadora de precio en la cabecera, que
 * acá sí tiene de dónde partir porque el producto tiene costo promedio.
 */
class EditProducto extends EditItem
{
    protected static string $resource = ProductoResource::class;

    /**
     * Las formas de venta no son columnas del producto: son sus
     * presentaciones, y se leen al abrir la ficha. Tampoco «Dónde hay»,
     * que son sus lugares mientras es infinito.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $producto = $this->getRecord();

        if ($producto instanceof Item) {
            $data = [...$data, ...FormasDeVenta::estadoDe($producto)];

            /*
             * Un infinito sin lugares anotados —de antes del 13-oct— abre
             * con farmacia marcada: así guardar la ficha lo deja ubicado,
             * en vez de trabarla con «marque al menos un almacén».
             */
            $ubicador = app(UbicadorDeProducto::class);
            $donde = $ubicador->dondeEsta($producto);

            $data['ubicaciones'] = $donde === [] && $producto->tieneStockInfinito()
                ? $ubicador->porDefecto()
                : $donde;
        }

        return $data;
    }

    /**
     * El producto y sus formas de venta se guardan juntos o no se guarda
     * ninguno: una ficha guardada a medias dejaría el nombre nuevo con
     * los envases viejos, sin aviso.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $formas = FormasDeVenta::paraGuardar($data[FormasDeVenta::CAMPO] ?? null);
        $sueltaPrincipal = FormasDeVenta::esSi($data[FormasDeVenta::SUELTA_PRINCIPAL] ?? null);

        /*
         * ─────────────────────────────────────────────────────────────
         * EL STOCK INFINITO NO SE GUARDA COMO UN CAMPO MÁS (13-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Marcarlo da de baja lo que había y quitarlo lo deja en cero:
         * mueve el kardex. Eso pasa por `CambiadorDeStockInfinito`, el
         * mismo de la acción en bloque, y no por el `update()` de la ficha.
         */
        $infinito = array_key_exists('stock_infinito', $data) ? (bool) $data['stock_infinito'] : null;

        /*
         * «Dónde hay» solo llega cuando el campo está a la vista —con el
         * infinito prendido—. Sin él, los lugares quedan como estaban.
         */
        $lugares = array_key_exists('ubicaciones', $data)
            ? UbicadorDeProducto::idsDelFormulario($data['ubicaciones'])
            : null;

        unset(
            $data[FormasDeVenta::CAMPO],
            $data[FormasDeVenta::SUELTA_PRINCIPAL],
            $data['stock_infinito'],
            $data['ubicaciones'],
        );

        $cambios = new CambiosDeFormasDeVenta;
        $reajustes = [];

        try {
            $guardado = DB::transaction(function () use ($record, $data, $formas, $sueltaPrincipal, $infinito, $lugares, &$cambios, &$reajustes): Model {
                $guardado = parent::handleRecordUpdate($record, $data);

                if ($guardado instanceof Item) {
                    $cambios = app(SincronizadorDeFormasDeVenta::class)->sincronizar($guardado, $formas, $sueltaPrincipal);

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 SI CAMBIARON LOS ENVASES, CAMBIAN LOS PRECIOS
                     * ─────────────────────────────────────────────────
                     *
                     * Mauricio (13-oct-2026): «si cambio el blíster a 12
                     * tabletas tiene que actualizar el precio para no
                     * vender a pérdida, y eso automático». Desde el mínimo
                     * guardado, con lo que cambió como porqué. En la misma
                     * transacción: el blíster de 12 no existe ni un
                     * segundo con el precio del de 10.
                     */
                    if ($cambios->hayCambios()) {
                        $reajustes = app(ReajustadorDePrecios::class)->reajustar($guardado, now(), $cambios->porQue());
                    }

                    if ($infinito !== null && (bool) $guardado->stock_infinito !== $infinito) {
                        app(CambiadorDeStockInfinito::class)->cambiar([$guardado], $infinito);
                    }

                    /*
                     * Después del cambio de bandera: al marcarlo, el
                     * cambiador ya lo dejó donde tenía existencia, y lo que
                     * se marcó en la ficha es la última palabra.
                     */
                    if ($lugares !== null && $lugares !== [] && $guardado->tieneStockInfinito()) {
                        app(UbicadorDeProducto::class)->dejarEn($guardado, $lugares);
                    }
                }

                return $guardado;
            });
        } catch (SihlaException $e) {
            /*
             * Las formas de venta y, desde el 13-oct-2026, el stock
             * infinito: dar de baja lo que había puede fallar con un error
             * de inventario, y tiene que verse igual que el de las formas.
             */
            Notification::make()
                ->danger()
                ->title('No se guardaron los cambios')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }

        $this->avisarLoQueCambio($cambios, $reajustes, $guardado);

        return $guardado;
    }

    /**
     * Lo que pasó solo al guardar: los precios que se movieron y lo que
     * quedó en el estante con el envase de antes. Se queda en pantalla
     * hasta que se cierre: es plata.
     *
     * @param list<array{sede: string, desde: CarbonInterface, formas: list<array{rotulo: string, antes: Monto|null, ahora: Monto}>}> $reajustes
     */
    private function avisarLoQueCambio(CambiosDeFormasDeVenta $cambios, array $reajustes, Model $producto): void
    {
        $lineas = [];

        /*
         * Un precio de antes de que se guardara el mínimo —cargado a mano,
         * o uno que la migración no pudo leer— no se ajusta solo: se dice,
         * para que no quede el envase nuevo cobrándose como sueltas.
         */
        if ($cambios->hayCambios() && $reajustes === [] && $producto instanceof Item && $this->tienePrecioSinMinimo($producto)) {
            $lineas[] = 'Este producto no tiene guardado de qué mínimo salen sus precios, así que no se ajustaron solos. '
                .'Abrí «Precios» y usá «Cambiar el precio» una vez: de ahí en adelante se ajustan solos.';
        }

        if ($reajustes === [] && $cambios->avisos === [] && $lineas === []) {
            return;
        }

        foreach ($reajustes as $reajuste) {
            $precios = [];

            foreach ($reajuste['formas'] as $forma) {
                $precios[] = $forma['antes'] instanceof Monto && ! $forma['antes']->igualA($forma['ahora'])
                    ? $forma['rotulo'].' '.$forma['antes']->formateado().' → '.$forma['ahora']->formateado()
                    : $forma['rotulo'].' '.$forma['ahora']->formateado();
            }

            $lineas[] = e($reajuste['sede'].', desde el '.$reajuste['desde']->format('d/m/Y').': '.implode(' · ', $precios));
        }

        foreach ($cambios->avisos as $aviso) {
            $lineas[] = e($aviso);
        }

        Notification::make()
            ->title($reajustes === [] ? 'Revisá los precios' : 'Los precios se ajustaron solos')
            ->body(new HtmlString(implode('<br>', $lineas)))
            ->icon('heroicon-o-arrow-path')
            ->iconColor('warning')
            ->persistent()
            ->send();
    }

    private function tienePrecioSinMinimo(Item $producto): bool
    {
        return Tarifario::query()->where('item_id', $producto->id)->whereNull('convenio_id')->vigentesEn(now())->exists()
            && ! PrecioMinimo::query()->where('item_id', $producto->id)->vigentesEn(now())->exists();
    }

    /**
     * ⚠️ Después de guardar y NO antes: Filament sincroniza la relación
     * muchos-a-muchos recién cuando el modelo ya existe, así que antes de
     * este punto la lista de principios todavía es la vieja.
     *
     * De acá sale `items.principio_activo`, que es lo que alimenta la
     * columna generada `nombre_busqueda` — el buscador del mostrador. El
     * porqué completo está en `SincronizadorDePrincipiosActivos`.
     */
    protected function afterSave(): void
    {
        /** @var Item $producto */
        $producto = $this->record;

        app(SincronizadorDePrincipiosActivos::class)->actualizarElTextoDe($producto);
    }
}
