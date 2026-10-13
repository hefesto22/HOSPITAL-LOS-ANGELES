<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Actions;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioDeForma;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Filament\Resources\Items\RelationManagers\PreciosRelationManager;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\Unidad;
use App\Services\CalculadoraDePreciosPorForma;
use App\Services\FijadorDePreciosPorForma;
use App\Services\ResumenDePrecios;
use App\Support\ContextoSede;
use App\Support\NumeroDeFormulario;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * «Fijar el precio de venta» de un producto: UN número y la tabla entera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 SE ESCRIBE EL MÍNIMO, NO LA LISTA (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «La caja a 120 es lo mínimo que puede pagar la cuarta edad; menos de
 * ahí se considera pérdida.» Se escribe ese número —el del envase
 * principal, el de la estrella en «Formas de venta»— y la pantalla
 * muestra, antes de guardar, lo que sale de él:
 *
 *   Forma          Lista (todos)   Tercera edad   Cuarta edad
 *   CAJA X 100 ★        L 200.00       L 150.00      L 120.00
 *   BLISTER X 10         L 22.00        L 16.50       L 13.20
 *   TABLETA               L 2.50         L 1.88        L 1.50
 *
 * Los recargos del blíster y de la suelta vienen puestos con los de
 * «Márgenes y recargos» y se pueden cambiar acá para ESTE producto: es
 * lo que Mauricio pidió —«por defecto, pero que se pueda cambiar»—.
 *
 * El cálculo vive en `CalculadoraDePreciosPorForma` y lo que se guarda en
 * `FijadorDePreciosPorForma` (§9.A11). Esta clase solo pregunta y muestra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CAMBIAR EL PRECIO ES ESTA MISMA VENTANA (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «no se puede editar», «debería decir en cuánto se venderá
 * cada cosa, una tabla resumen». Con un precio vigente el botón dice
 * «Cambiar el precio» y la ventana abre con el mínimo, los recargos y la
 * sede de hoy ya puestos, y la tabla de «Así se va a vender» llena desde
 * el primer momento, con lo de hoy al lado de lo nuevo. Se cambia un
 * número y la tabla se mueve sola.
 *
 * La sede viene con la de quien mira y solo se pregunta si hay más de
 * una: con «Todas las sedes» por defecto, el precio nuevo quedaba debajo
 * del de la sede —que le gana— y no cambiaba nada en el mostrador.
 */
final class FijarPrecioDeVentaAction
{
    public static function make(): Action
    {
        return Action::make('fijarPrecioDeVenta')
            ->label(fn (RelationManager $livewire): string => self::yaTienePrecio($livewire) ? 'Cambiar el precio' : 'Fijar el precio de venta')
            ->icon(fn (RelationManager $livewire): Heroicon => self::yaTienePrecio($livewire) ? Heroicon::OutlinedPencilSquare : Heroicon::OutlinedPlus)
            /*
             * Solo en lo que se almacena —medicamentos e insumos—, y con
             * el mismo permiso que fijar cualquier precio: `update` sobre
             * el ítem, que la matriz solo le concede a dirección.
             */
            ->visible(function (RelationManager $livewire): bool {
                $item = $livewire->getOwnerRecord();

                return $item instanceof Item && $item->mueveInventario() && Gate::allows('update', $item);
            })
            ->modalHeading(fn (RelationManager $livewire): string => self::yaTienePrecio($livewire)
                ? 'Cambiar el precio de venta'
                : 'Fijar el precio de venta')
            ->modalDescription(fn (RelationManager $livewire): string => 'Escribí lo menos que se puede cobrar por '
                .self::laPrincipal($livewire).' —lo que paga la cuarta edad—. Todo lo demás se calcula solo, '
                .'y abajo se ve a cuánto se va a vender cada cosa.')
            ->modalSubmitActionLabel('Guardar los precios')
            ->modalWidth('4xl')
            ->fillForm(function (RelationManager $livewire): array {
                $item = $livewire->getOwnerRecord();

                if (! $item instanceof Item) {
                    return ['vigencia_desde' => now()->toDateString()];
                }

                /*
                 * Con lo de hoy, si se sabe de dónde salió: el mínimo que
                 * se escribió la vez pasada y sus recargos. Si no —un
                 * precio de antes de esta ventana—, los de la política.
                 */
                $origen = app(ResumenDePrecios::class)->de($item, PreciosRelationManager::sedeActual(), now())['origen'];
                $recargos = $origen ?? app(CalculadoraDePreciosPorForma::class)->recargosPara($item, now());

                return [
                    'minimo'         => $origen === null ? null : ItemPresentacion::sinCerosDeMas($origen['minimo']->redondeado(2)),
                    'recargo_envase' => self::porciento($recargos['envase']),
                    'recargo_suelta' => self::porciento($recargos['suelta']),
                    'sede_id'        => ContextoSede::actualId(),
                    'vigencia_desde' => now()->toDateString(),
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('minimo')
                        ->label(fn (RelationManager $livewire): string => 'Lo mínimo por '.self::laPrincipal($livewire))
                        ->prefix('L')
                        ->required()
                        ->autofocus()
                        ->live(debounce: 500)
                        /*
                         * `regex` y no `numeric`: `numeric` acepta «1e3»,
                         * que bcmath lee como cero.
                         */
                        ->rule('regex:/^\d{1,9}(\.\d{1,2})?$/')
                        ->validationMessages([
                            'regex' => 'Escribí solo el número, con punto decimal: 120 o 87.50.',
                        ])
                        ->helperText('Lo que paga la cuarta edad, con el descuento de ley ya descontado.'),

                    DatePicker::make('vigencia_desde')
                        ->label('Rige desde')
                        ->required()
                        ->live()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Lo de antes queda en el historial. Hoy mismo se puede corregir si nadie cobró con él.'),

                    TextInput::make('recargo_envase')
                        ->label(fn (RelationManager $livewire): string => 'Recargo de '.self::elMasChico($livewire))
                        ->suffix('%')
                        ->required()
                        ->live(debounce: 500)
                        ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
                        ->validationMessages(['regex' => 'Solo el número: 10, o 12.5.'])
                        ->visible(fn (RelationManager $livewire): bool => self::elMasChico($livewire) !== null)
                        ->helperText('Cuánto más se cobra por unidad que en el envase principal.'),

                    TextInput::make('recargo_suelta')
                        ->label(fn (RelationManager $livewire): string => 'Recargo de '.self::laSuelta($livewire).' suelta')
                        ->suffix('%')
                        ->required()
                        ->live(debounce: 500)
                        ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
                        ->validationMessages(['regex' => 'Solo el número: 25, o 22.5.'])
                        ->visible(fn (RelationManager $livewire): bool => self::principalDe($livewire) instanceof ItemPresentacion)
                        ->helperText('Lo suelto cuesta más: abrir la caja deja unidades que se vencen.'),

                    /*
                     * Solo si hay más de una sede. Con una sola, se fija
                     * para la de quien mira (`fillForm`).
                     */
                    Select::make('sede_id')
                        ->label('En qué sede')
                        ->options(fn (): array => Sede::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->placeholder('Todas las sedes')
                        ->native(false)
                        ->visible(fn (): bool => Sede::query()->vigentesEn(now())->count() > 1),
                ]),

                Placeholder::make('asi_quedan')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (Get $get, RelationManager $livewire): View => self::tabla(
                        $livewire,
                        self::calcular($livewire, $get('minimo'), $get('recargo_envase'), $get('recargo_suelta'), $get('vigencia_desde')),
                    )),

                CampoMayusculas::area('motivo')
                    ->label('Por qué')
                    ->columnSpanFull()
                    ->required()
                    ->minLength(10)
                    ->rows(2)
                    ->placeholder('Ej.: precio nuevo del proveedor, ajuste de la lista del hospital…')
                    ->helperText('Se guarda junto con el mínimo y los recargos de los que salió cada precio.'),
            ])
            ->action(function (
                array $data,
                Action $action,
                RelationManager $livewire,
                FijadorDePreciosPorForma $fijador,
            ): void {
                $item = $livewire->getOwnerRecord();
                $desde = is_string($data['vigencia_desde'] ?? null) ? Carbon::parse($data['vigencia_desde']) : now();
                $precios = self::calcular(
                    $livewire,
                    $data['minimo'] ?? null,
                    $data['recargo_envase'] ?? null,
                    $data['recargo_suelta'] ?? null,
                    $desde,
                );

                if (! $item instanceof Item || ! $precios instanceof PreciosPorForma) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo calcular el precio')
                        ->body('Revisá el mínimo y los recargos: tienen que ser números mayores que cero.')
                        ->send();

                    $action->halt();

                    return;
                }

                try {
                    $fijador->fijar(
                        item: $item,
                        precios: $precios,
                        sede: self::sedeElegida($data),
                        motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                        desde: $desde,
                    );
                } catch (PrecioNoFijableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo fijar el precio')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Precios guardados · rigen desde el '.$desde->format('d/m/Y'))
                    ->body(implode(' · ', array_map(
                        static fn (PrecioDeForma $forma): string => $forma->rotulo.' '.$forma->lista->formateado(),
                        $precios->formas,
                    )))
                    ->send();
            });
    }

    /**
     * Lo que sale de lo escrito, o nulo mientras falte algo o no se
     * entienda. Nunca un cero: un cero acá sería un precio regalado.
     */
    private static function calcular(
        RelationManager $livewire,
        mixed $minimo,
        mixed $recargoEnvase,
        mixed $recargoSuelta,
        mixed $desde,
    ): ?PreciosPorForma {
        $item = $livewire->getOwnerRecord();
        $minimo = NumeroDeFormulario::aDecimal($minimo);

        if (! $item instanceof Item || ! $minimo instanceof Decimal || ! $minimo->mayorQue('0')) {
            return null;
        }

        $fecha = $desde instanceof CarbonInterface
            ? $desde
            : (is_string($desde) && $desde !== '' ? Carbon::parse($desde) : now());

        try {
            return app(CalculadoraDePreciosPorForma::class)->desdeElMinimo(
                item: $item,
                presentaciones: self::presentacionesDe($item),
                minimoDeLaPrincipal: $minimo,
                fecha: $fecha,
                recargoEnvase: self::fraccion($recargoEnvase),
                recargoSuelta: self::fraccion($recargoSuelta),
            );
        } catch (PrecioNoFijableException) {
            return null;
        }
    }

    /**
     * «Así se va a vender»: cada forma con lo que cuesta hoy y lo que va a
     * costar, y lo que paga cada edad con descuento de ley.
     *
     * De la más grande a la suelta, que es como se lee una lista de
     * precios de farmacia.
     */
    private static function tabla(RelationManager $livewire, ?PreciosPorForma $precios): View
    {
        $item = $livewire->getOwnerRecord();
        $hoy = [];

        if ($item instanceof Item) {
            foreach (app(ResumenDePrecios::class)->de($item, PreciosRelationManager::sedeActual(), now())['formas'] as $forma) {
                $hoy[$forma['clave']] = $forma['lista'];
            }
        }

        $filas = [];
        $edades = [];

        foreach ($precios instanceof PreciosPorForma ? $precios->formas : [] as $forma) {
            $antes = $hoy[$forma->clave] ?? null;
            $edades = array_map(fn (array $edad): string => $edad['rango']->etiqueta(), $forma->porEdad);

            $filas[] = [
                'rotulo'    => $forma->rotulo,
                'contenido' => $forma->contenido,
                'principal' => $forma->esLaPrincipal,
                'recargo'   => $forma->esLaPrincipal || $forma->recargo->esCero() ? null : '+'.$forma->recargo->comoPorcentaje(),
                'lista'     => $forma->lista,
                'porUnidad' => $forma->esLaSuelta() ? null : Monto::de($forma->listaPorUnidad()->redondeado(2)),
                'antes'     => $antes,
                'cambio'    => $antes === null ? 'nuevo' : ($forma->lista->mayorQue($antes) ? 'sube' : ($forma->lista->menorQue($antes) ? 'baja' : 'igual')),
                'porEdad'   => array_map(fn (array $edad): Monto => $edad['paga'], $forma->porEdad),
            ];
        }

        usort($filas, fn (array $una, array $otra): int => $otra['contenido']->comparar($una['contenido']));

        return view('filament.forms.asi-se-vendera', [
            'filas'  => $filas,
            'edades' => $edades,
            'hayHoy' => $hoy !== [],
        ]);
    }

    /**
     * ¿Ya tiene un precio de lista que rige hoy? Cambia el botón de
     * «Fijar» a «Cambiar».
     */
    private static function yaTienePrecio(RelationManager $livewire): bool
    {
        $item = $livewire->getOwnerRecord();

        return $item instanceof Item && Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->vigentesEn(now())
            ->exists();
    }

    /**
     * La sede elegida o, si no se preguntó porque hay una sola, la de
     * quien está fijando el precio.
     *
     * @param array<string, mixed> $data
     */
    private static function sedeElegida(array $data): ?Sede
    {
        $id = array_key_exists('sede_id', $data) ? $data['sede_id'] : ContextoSede::actualId();

        return is_numeric($id) ? Sede::query()->find((int) $id) : null;
    }

    // ── Lo que se pregunta del producto ───────────────────────────────

    /**
     * @return Collection<int, ItemPresentacion>
     */
    private static function presentacionesDe(Item $item): Collection
    {
        return ItemPresentacion::query()
            ->with('unidad')
            ->where('item_id', $item->id)
            ->get();
    }

    private static function principalDe(RelationManager $livewire): ?ItemPresentacion
    {
        $item = $livewire->getOwnerRecord();

        if (! $item instanceof Item) {
            return null;
        }

        $calculadora = app(CalculadoraDePreciosPorForma::class);

        return $calculadora->principalEntre($calculadora->envasesDe(self::presentacionesDe($item)));
    }

    /**
     * «la CAJA X 100 TABLETAS», o «la TABLETA» cuando la principal es la
     * unidad suelta.
     */
    private static function laPrincipal(RelationManager $livewire): string
    {
        $principal = self::principalDe($livewire);

        return $principal instanceof ItemPresentacion
            ? $principal->envase()
            : self::laSuelta($livewire);
    }

    private static function laSuelta(RelationManager $livewire): string
    {
        $item = $livewire->getOwnerRecord();
        $unidad = $item instanceof Item ? $item->unidadDispensacion : null;

        return $unidad instanceof Unidad ? $unidad->nombre : 'la unidad';
    }

    /**
     * El primer envase más chico que el principal —el blíster de la caja—,
     * o nulo si no hay ninguno y el recargo de envase no se usa.
     */
    private static function elMasChico(RelationManager $livewire): ?string
    {
        $item = $livewire->getOwnerRecord();
        $principal = self::principalDe($livewire);

        if (! $item instanceof Item || ! $principal instanceof ItemPresentacion) {
            return null;
        }

        foreach (app(CalculadoraDePreciosPorForma::class)->envasesDe(self::presentacionesDe($item)) as $envase) {
            if (Decimal::de($envase->unidades_por_presentacion)->menorQue($principal->unidades_por_presentacion)) {
                return $envase->envase();
            }
        }

        return null;
    }

    private static function fraccion(mixed $porcentaje): ?Decimal
    {
        $valor = NumeroDeFormulario::aDecimal($porcentaje);

        return $valor instanceof Decimal && ! $valor->esNegativo() ? $valor->entre('100') : null;
    }

    private static function porciento(Decimal $fraccion): string
    {
        return ItemPresentacion::sinCerosDeMas($fraccion->por('100')->redondeado(2));
    }
}
