<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Pages;

use App\Domain\Exceptions\RecepcionException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Filament\Resources\Recepciones\RecepcionResource;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Proveedor;
use App\Services\RegistradorDeRecepcion;
use App\Support\NumeroDeFormulario;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Guardar acá NO es guardar un formulario: es mover el kardex.
 *
 * Por eso el guardado no lo hace Eloquent con el arreglo del formulario,
 * sino `RegistradorDeRecepcion`, que arma los objetos del dominio y hace
 * las cuatro escrituras —lote, movimiento, costo promedio y linea— en una
 * sola transaccion. Si algo falla, no queda nada.
 *
 * Los errores del dominio se traducen a un error de validacion de
 * Filament y no a una pantalla blanca: quien esta recibiendo necesita
 * poder corregir la linea y volver a intentar sin perder lo tecleado.
 */
class CreateRecepcion extends CreateRecord
{
    protected static string $resource = RecepcionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $almacen = Almacen::query()->findOrFail($data['almacen_id']);

        $proveedor = isset($data['proveedor_id']) && is_numeric($data['proveedor_id'])
            ? Proveedor::query()->find((int) $data['proveedor_id'])
            : null;

        try {
            return app(RegistradorDeRecepcion::class)->registrar(
                almacen: $almacen,
                lineas: $this->lineasDelFormulario($data),
                proveedor: $proveedor,
                referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                fecha: isset($data['fecha_recepcion'])
                    ? Carbon::parse((string) $data['fecha_recepcion'])
                    : null,
                notas: is_string($data['notas'] ?? null) ? $data['notas'] : null,
            );
        } catch (RecepcionException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo recibir')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.lineas' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<LineaRecibida>
     */
    private function lineasDelFormulario(array $data): array
    {
        $crudas = is_array($data['lineas'] ?? null) ? $data['lineas'] : [];

        $lineas = [];

        foreach ($crudas as $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $vencimiento = $cruda['fecha_vencimiento'] ?? null;

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 LOS NÚMEROS LLEGAN COMO FLOAT, NO COMO TEXTO
             * ─────────────────────────────────────────────────────────
             *
             * Un `<input type="number">` viaja por Livewire como NÚMERO
             * de JavaScript, así que del otro lado llega `float`. El
             * conversor que vivía acá solo entendía `int` y `string`, y
             * ante cualquier otra cosa devolvía **'0'**.
             *
             * Resultado: se tecleaba «10 cajas de 100» —el resumen de la
             * pantalla, que lee el estado en vivo, mostraba las 1.000
             * unidades correctas— y al guardar el dominio recibía
             * cantidad cero y rebotaba con «tiene que ser mayor que
             * cero», señalando justo el campo que estaba bien lleno.
             *
             * Es la lección 🔴 del bloque 5d-1: un conversor de
             * formulario NUNCA devuelve cero ante lo que no entiende.
             * `NumeroDeFormulario` devuelve null —«no entiendo esto»— y
             * acá se traduce a un error que dice cuál es la línea.
             */
            $cantidad = NumeroDeFormulario::aDecimal($cruda['cantidad_presentacion'] ?? null);
            $porPresentacion = NumeroDeFormulario::aDecimal($cruda['unidades_por_presentacion'] ?? null);

            if ($cantidad === null || $porPresentacion === null) {
                throw ValidationException::withMessages([
                    'data.lineas' => 'Hay una línea con la cantidad o el contenido del envase vacíos '
                        .'o mal escritos. Revisá que sean números.',
                ]);
            }

            $lineas[] = new LineaRecibida(
                item: Item::query()->findOrFail($cruda['item_id']),
                presentacion: isset($cruda['item_presentacion_id']) && is_numeric($cruda['item_presentacion_id'])
                    ? ItemPresentacion::query()->find((int) $cruda['item_presentacion_id'])
                    : null,
                cantidadPresentacion: $cantidad,
                unidadesPorPresentacion: $porPresentacion,
                /*
                 * El costo SÍ puede ser cero y con razón: una donación
                 * entra sin costo. Vacío vale cero; texto que no se
                 * entiende, también — el dominio no puede distinguirlos
                 * y el margen se corrige con un ajuste.
                 */
                costoPorPresentacion: NumeroDeFormulario::aDecimalO(
                    $cruda['costo_por_presentacion'] ?? null,
                    Decimal::de('0'),
                ),
                numeroLote: is_string($cruda['numero_lote'] ?? null) ? $cruda['numero_lote'] : null,
                vencimiento: is_string($vencimiento) && $vencimiento !== ''
                    ? Carbon::parse($vencimiento)
                    : null,
            );
        }

        return $lineas;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * VERIFICAR ANTES DE QUE ENTRE AL ESTANTE
     * ─────────────────────────────────────────────────────────────────
     *
     * Guardar una recepción NO es guardar un formulario: mueve el kardex
     * y recalcula el costo promedio, y eso no se deshace con Ctrl+Z —se
     * corrige con un ajuste, que pide motivo y queda escrito—. Un botón
     * que hace todo eso sin preguntar nada es un botón que alguien
     * aprieta con «1000» donde iba «100».
     *
     * Por eso el paso de confirmación muestra el RESUMEN, no un «¿estás
     * seguro?». Lo que hay que verificar es el número: cuántas unidades
     * entran, por cuánto dinero y a qué almacén. Un «¿seguro?» a secas se
     * contesta que sí sin leer; un total que no cuadra con lo que hay en
     * la mano, no.
     *
     * ⚠️ Esto NO reemplaza la revisión posterior de otra persona: son dos
     * controles distintos. Este dice «lo que tecleé está bien»; aquel
     * dice «alguien más miró lo que entró». Por eso este SÍ lo puede
     * hacer quien recibe, y aquel no.
     */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Verificar antes de ingresar al inventario')
            ->modalDescription(fn (): string => $this->resumenParaConfirmar())
            ->modalSubmitActionLabel('Sí, ingresar al inventario')
            ->modalIcon('heroicon-o-archive-box-arrow-down');
    }

    /**
     * «3 productos · 1.000 unidades · L 10,000.00 · ALMACEN-1».
     *
     * Se arma del estado del formulario y no del registro, porque acá
     * todavía no hay registro: la idea es justamente verlo ANTES.
     */
    private function resumenParaConfirmar(): string
    {
        $lineas = is_array($this->data['lineas'] ?? null) ? $this->data['lineas'] : [];

        $unidades = Decimal::de('0');
        $dinero = Decimal::de('0');
        $productos = 0;

        foreach ($lineas as $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $cantidad = NumeroDeFormulario::aDecimal($cruda['cantidad_presentacion'] ?? null);
            $porEnvase = NumeroDeFormulario::aDecimal($cruda['unidades_por_presentacion'] ?? null);

            if ($cantidad === null || $porEnvase === null) {
                continue;
            }

            $productos++;
            $unidades = $unidades->sumar($cantidad->por($porEnvase));
            $dinero = $dinero->sumar($cantidad->por(
                NumeroDeFormulario::aDecimalO($cruda['costo_por_presentacion'] ?? null, Decimal::de('0')),
            ));
        }

        if ($productos === 0) {
            return 'Todavía no agregaste ninguna línea.';
        }

        $almacen = is_numeric($this->data['almacen_id'] ?? null)
            ? Almacen::query()->find((int) $this->data['almacen_id'])
            : null;

        return sprintf(
            'Entran %s unidades de %d %s, por L %s.%s Esto mueve el kardex y recalcula el costo '
            .'promedio: para corregirlo después hace falta un ajuste con motivo.',
            $unidades->redondeado(2),
            $productos,
            $productos === 1 ? 'producto' : 'productos',
            $dinero->redondeado(2),
            $almacen instanceof Almacen ? ' Almacén: '.$almacen->nombre.'.' : '',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Recibido. El kardex ya se movió y el costo promedio se recalculó.';
    }
}
