<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Actions;

use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\EscenarioDePrecio;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioSugerido;
use App\Models\Item;
use App\Models\MargenObjetivo;
use App\Services\CalculadoraDePrecioDeLista;
use App\Services\FijadorDePrecio;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * «Calcular precio» — la cuenta completa, antes de decidir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PROPONE, Y SOLO GUARDA SI SE LO PIDEN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Muestra el precio de lista que sale del costo, del margen vigente y del
 * descuento máximo de ley, y debajo qué paga y cuánto deja **cada rango
 * de edad**. Guardar es un segundo acto deliberado, con su propio botón.
 *
 * Y solo se ofrece desde la ficha del ítem (`$puedeGuardar`), nunca desde
 * el listado: llegar a la ficha ya exige permiso para modificar el ítem,
 * así que la autorización queda resuelta por dónde se entró y no por un
 * `can()` con el nombre del permiso escrito a mano. Desde el listado el
 * modal es de solo lectura.
 *
 * Lo que escribe es una fila de tarifario sin convenio y sin sede —el
 * precio de lista— con el motivo redactado desde la propia cuenta. §4.1:
 * «la Ruta A no reemplaza al tarifario: lo alimenta».
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LA TABLA DE ESCENARIOS Y NO SOLO EL PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * §4.5, regla de implementación: **antes de confirmar un precio, la
 * pantalla muestra el margen resultante en cada rango de edad, ya con el
 * descuento aplicado. La decisión se toma con los números a la vista, no
 * a ojo.** Un modal que dijera solo «L 29.33» obligaría a hacer la resta
 * en la cabeza justo cuando importa no equivocarse.
 *
 * ⚠️ Los closures reciben sus argumentos POR NOMBRE. `$get` y `$record`
 * se llaman así porque así los inyecta Filament; con otro nombre llega un
 * objeto vacío del contenedor y el modal se queda mudo, sin error.
 */
final class CalcularPrecioAction
{
    /**
     * ¿Este usuario puede ver la cuenta de este ítem?
     *
     * Dos condiciones distintas, y conviene no confundirlas:
     *
     *  1. **El ítem tiene costo del cual derivar.** Un honorario o una
     *     estancia no se compran, así que el modal solo sabría decir que
     *     no (Ruta B del §4.1).
     *  2. **El usuario puede ver el margen objetivo.** El modal muestra el
     *     margen que persigue el hospital y lo que deja cada rango de
     *     edad: es política comercial, no información del catálogo. La
     *     matriz se la concede a dirección y auditoría, y a nadie más —
     *     que bodega o laboratorio vean cuánto gana el hospital por cada
     *     insumo no aporta nada y sí filtra lo que la matriz protege.
     *
     * Está en un método propio y no en el cierre para poder probarlo sin
     * montar la tabla entera.
     */
    public static function puedeVerse(Item $item): bool
    {
        return $item->tipo->precioDerivadoDelCosto()
            && Gate::allows('viewAny', MargenObjetivo::class);
    }

    public static function make(bool $puedeGuardar = false): Action
    {
        $accion = Action::make('calcularPrecio')
            ->label('Calcular precio')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            ->modalHeading(fn (Item $record): string => 'Precio sugerido · '.$record->nombre)
            ->modalDescription($puedeGuardar
                ? 'La cuenta a la vista. Guardar escribe el precio de lista; las facturas ya emitidas no cambian.'
                : 'Esto no guarda nada: es la cuenta a la vista para decidir el precio del tarifario.')
            ->modalWidth('2xl')
            ->modalCancelActionLabel('Cerrar')
            ->schema([
                TextInput::make('costo')
                    ->label('Costo unitario')
                    ->prefix('L')
                    ->live(onBlur: true)
                    ->autofocus()
                    /*
                     * `regex` y no `numeric`: `numeric` acepta "1e3", que
                     * entra a bcmath como cero y produciría un precio de
                     * cero con cara de calculado.
                     */
                    ->rule('regex:/^\d{1,9}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con punto decimal: 10.50.',
                    ])
                    ->helperText(
                        'El costo promedio de la última entrada. Mientras no exista el kardex se '
                        .'escribe a mano; después lo va a traer solo.'
                    ),

                /*
                 * La cuenta vive DENTRO del schema y no en
                 * `modalContent()` porque acá sí llega `$get`: es el
                 * único lugar donde se puede leer lo que el usuario
                 * acaba de escribir sin haber guardado nada.
                 *
                 * `Html` y no `Placeholder`: el segundo está deprecado a
                 * favor de `TextEntry`, que formatea el estado —badges,
                 * listas, íconos— y para una tabla armada a mano eso
                 * estorba. `Html` escribe lo que se le da y nada más.
                 */
                Html::make(fn (Get $get, Item $record): HtmlString => self::cuenta($record, $get('costo'))),
            ]);

        if (! $puedeGuardar) {
            return $accion->modalSubmitAction(false);
        }

        return $accion
            ->modalSubmitActionLabel('Guardar como precio de lista')
            ->action(function (array $data, Action $action, Item $record, FijadorDePrecio $fijador): void {
                /** @var string $costo */
                $costo = $data['costo'] ?? '';

                try {
                    $precio = app(CalculadoraDePrecioDeLista::class)
                        ->para($record, Monto::de($costo), now());

                    $fila = $fijador->fijar(
                        item: $record,
                        convenio: null,
                        sede: null,
                        precio: $precio->lista,
                        motivo: self::motivo($precio, $costo),
                        desde: now(),
                    );
                } catch (SihlaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo guardar el precio')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * `halt()` lanza una excepción de Filament pero está
                     * declarado `void`: el `return` explícito deja claro
                     * que abajo no se sigue.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Precio de lista fijado en '.$fila->monto()->formateado())
                    ->body('Rige desde el '.$fila->vigencia_desde->format('d/m/Y').'. Queda en la pestaña de precios.')
                    ->send();
            });
    }

    /**
     * El motivo lo redacta la cuenta, no el usuario.
     *
     * Es el único caso del sistema donde el motivo se genera solo, y se
     * justifica porque acá el «por qué» ES la cuenta: costo, margen y
     * descuento máximo, con los números del día en que se fijó. Escrito a
     * mano quedaría peor y más tarde.
     */
    private static function motivo(PrecioSugerido $precio, string $costo): string
    {
        return sprintf(
            'Derivado del costo L %s con margen objetivo %s y descuento máximo de ley %s: '
            .'%s ÷ (1 − %s) = %s. El adulto mayor paga %s y deja %s.',
            $costo,
            $precio->margenObjetivoComoPorcentaje(),
            $precio->descuentoMaximo->comoPorcentaje(),
            $precio->costo->formateado(),
            $precio->descuentoMaximo->comoPorcentaje(),
            $precio->lista->formateado(),
            $precio->peorEscenario()->paga->formateado(),
            $precio->peorEscenario()->margenComoPorcentaje(),
        );
    }

    private static function cuenta(Item $item, mixed $costo): HtmlString
    {
        if (! is_string($costo) || preg_match('/^\d{1,9}(\.\d{1,4})?$/', $costo) !== 1) {
            return self::aviso('Escribí el costo unitario para ver la cuenta.', 'gray');
        }

        try {
            $precio = app(CalculadoraDePrecioDeLista::class)->para($item, Monto::de($costo), now());
        } catch (SihlaException $e) {
            return self::aviso($e->getMessage(), 'warning');
        }

        return new HtmlString(self::tabla($precio));
    }

    private static function tabla(PrecioSugerido $precio): string
    {
        $filas = '';

        foreach ($precio->escenarios as $escenario) {
            $filas .= self::fila($escenario, $precio);
        }

        $lista = e($precio->lista->formateado());
        $margen = e($precio->margenObjetivoComoPorcentaje());
        $descuento = e($precio->descuentoMaximo->comoPorcentaje());

        return <<<HTML
            <div class="space-y-4 text-sm">
                <div class="rounded-lg bg-primary-50 p-4 dark:bg-primary-500/10">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Precio de lista sugerido</p>
                    <p class="mt-1 text-3xl font-bold text-primary-600 dark:text-primary-400">{$lista}</p>
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                        costo × (1 + {$margen}) ÷ (1 − {$descuento})
                    </p>
                </div>

                <table class="w-full text-left">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="py-2">Paciente</th>
                            <th class="py-2 text-right">Descuento</th>
                            <th class="py-2 text-right">Paga</th>
                            <th class="py-2 text-right">Deja</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">{$filas}</tbody>
                </table>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    El precio de lista es el mismo para todos: el descuento de ley cae sobre él, así que
                    el adulto mayor sí paga menos que quien va detrás en la fila. Dividir por el descuento
                    máximo es lo que deja el margen en piso y no en objetivo.
                </p>
            </div>
            HTML;
    }

    private static function fila(EscenarioDePrecio $escenario, PrecioSugerido $precio): string
    {
        $esElPeor = $escenario->rango === $precio->peorEscenario()->rango;

        $rango = e($escenario->rango->etiqueta());
        $descuento = $escenario->descuento->aplica() ? e($escenario->descuento->comoPorcentaje()) : '—';
        $paga = e($escenario->paga->formateado());
        $margen = e($escenario->margenComoPorcentaje());

        $marca = $esElPeor
            ? '<span class="ml-2 rounded bg-warning-100 px-1.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">el piso</span>'
            : '';

        $peso = $esElPeor ? 'font-semibold' : '';

        return <<<HTML
            <tr class="{$peso}">
                <td class="py-2">{$rango}{$marca}</td>
                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{$descuento}</td>
                <td class="py-2 text-right">{$paga}</td>
                <td class="py-2 text-right">{$margen}</td>
            </tr>
            HTML;
    }

    private static function aviso(string $mensaje, string $color): HtmlString
    {
        $clases = $color === 'warning'
            ? 'bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300'
            : 'bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-400';

        return new HtmlString(
            '<p class="rounded-lg p-4 text-sm '.$clases.'">'.e($mensaje).'</p>'
        );
    }
}
