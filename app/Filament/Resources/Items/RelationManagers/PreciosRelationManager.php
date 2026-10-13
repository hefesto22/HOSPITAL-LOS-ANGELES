<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Items\Actions\FijarPrecioDeVentaAction;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Services\FijadorDePrecio;
use App\Services\ResumenDePrecios;
use App\Support\ContextoSede;
use App\Support\NumeroDeFormulario;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Los precios de un producto, en su ficha.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PRIMERO LO QUE SE COBRA HOY; LO DE ANTES, APARTE (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta hoy era la tabla del tarifario tal cual: siete columnas, el
 * porqué repetido en cada renglón y dos filas que decían «Vigente» para
 * la tableta —la de la sede y la de todas—. Mauricio: «se ve demasiado
 * saturado y no se entiende cómo funciona», «¿cuál es el precio
 * vigente?».
 *
 * Ahora es una vista propia (`ResumenDePrecios` arma los datos):
 *
 *   1. A cuánto se vende hoy cada forma —caja, blíster, suelta— y lo
 *      que paga cada edad con la ley de hoy. Resuelto con la misma
 *      regla que el cargo, así que lo que se ve es lo que se cobra.
 *   2. Cómo funciona, en una línea: el mínimo de la principal y los
 *      recargos de los que salió todo.
 *   3. Lo demás —cerrado, de otra sede, tapado, próximo—, plegado y
 *      agrupado por cambio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ NO SE EDITA UNA FILA: SE CAMBIA EL PRECIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cambiar el precio» abre la misma ventana que «Fijar», con el mínimo y
 * los recargos de hoy ya puestos. Lo nuevo rige desde la fecha que se
 * elija y lo viejo queda en el historial: un `UPDATE` sobre la fila
 * vigente borraría la respuesta a «¿por qué esta factura de marzo dice
 * L 29.33?». El error de tecleo del mismo día sí se corrige, si nadie
 * cobró con él (`FijadorDePreciosPorForma`).
 *
 * ⚠️ La vista no lleva la tabla de Filament, y es la tabla la que pinta
 * las ventanas de las acciones: por eso `precios-del-item.blade.php`
 * incluye `<x-filament-actions::modals />` a mano. Sin eso el botón no
 * abre nada.
 */
class PreciosRelationManager extends RelationManager
{
    protected static string $relationship = 'precios';

    protected static ?string $title = 'Precios';

    /**
     * @var view-string
     */
    protected string $view = 'filament.resources.items.precios-del-item';

    protected static function getModelLabel(): ?string
    {
        return 'precio';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'precios';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $item = $this->getOwnerRecord();
        $sede = self::sedeActual();

        return [
            'resumen'          => $item instanceof Item ? app(ResumenDePrecios::class)->de($item, $sede, now()) : null,
            'sede'             => $sede?->nombre,
            'seVendePorFormas' => $this->seVendePorFormas(),
            'llevaIsv'         => $this->llevaIsv(),
        ];
    }

    /**
     * En farmacia el precio se fija desde el mínimo del envase principal
     * y salen todas las formas juntas (11-oct-2026).
     */
    public function fijarPrecioDeVentaAction(): Action
    {
        return FijarPrecioDeVentaAction::make();
    }

    /**
     * Para lo que no se vende por formas —un producto «Otro»—: un precio
     * por pagador, escrito a mano.
     */
    public function fijarPrecioAction(): Action
    {
        return Action::make('fijarPrecio')
            ->label('Fijar un precio')
            ->icon(Heroicon::OutlinedPlus)
            /*
             * Ver el precio es una cosa —el paciente lo ve en el
             * mostrador— y ponerlo es otra. Se pide `update` sobre el
             * ítem, que la matriz solo le concede a dirección: sin esto,
             * cualquiera que pudiera abrir la ficha podía fijar el precio
             * de venta del hospital.
             */
            ->visible(fn (): bool => ! $this->seVendePorFormas()
                && Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading('Fijar un precio nuevo')
            ->modalDescription(
                'El precio vigente para ese pagador y sede se cierra el día anterior. Las facturas ya '
                .'emitidas no cambian.'
            )
            ->modalSubmitActionLabel('Fijar')
            ->modalWidth('2xl')
            ->schema([
                /*
                 * Dos columnas: arriba a quién y dónde, abajo cuánto y
                 * desde cuándo. En una sola columna eran seis campos en
                 * fila india, cada uno con su párrafo, y el modal no
                 * entraba en la pantalla.
                 */
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('convenio_id')
                        ->label('Para quién')
                        ->options(fn (): array => Convenio::query()
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all())
                        ->placeholder('Precio de lista')
                        ->native(false)
                        ->searchable()
                        ->helperText('Vacío = el que paga cualquiera sin precio propio.'),

                    Select::make('sede_id')
                        ->label('En qué sede')
                        ->options(fn (): array => Sede::query()
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all())
                        ->placeholder('Todas las sedes')
                        ->native(false)
                        ->helperText('Vacío = vale en todas.'),

                    Select::make('item_presentacion_id')
                        ->label('De qué envase')
                        ->columnSpanFull()
                        ->live()
                        ->options(function (): array {
                            $item = $this->getOwnerRecord();

                            if (! $item instanceof Item) {
                                return [];
                            }

                            return ItemPresentacion::query()
                                ->where('item_id', $item->id)
                                ->orderBy('nombre')
                                ->get()
                                ->mapWithKeys(fn (ItemPresentacion $p): array => [$p->id => $p->nombre])
                                ->all();
                        })
                        ->placeholder('Todo el producto')
                        ->native(false)
                        ->helperText(
                            'Vacío = vale para todo el producto. Elegí uno solo si ese envase tiene su '
                            .'propio costo por unidad.'
                        ),

                    /*
                     * ─────────────────────────────────────────────────
                     * 🔴 EL PRECIO ES POR UNIDAD, Y EL RÓTULO LO DICE
                     * ─────────────────────────────────────────────────
                     *
                     * El tarifario guarda el precio por unidad de
                     * dispensación: el de la TABLETA, no el del blíster.
                     * Con el rótulo «Precio» a secas, quien elegía
                     * «BLISTER X 10» escribía lo que cuesta el blíster, y
                     * cada tableta quedaba diez veces más cara.
                     *
                     * Por eso dice «Precio por TAB», y debajo se lee en
                     * vivo cuánto sale el envase elegido.
                     */
                    TextInput::make('precio')
                        ->label(fn (): string => $this->rotuloDelPrecio())
                        ->prefix('L')
                        ->required()
                        ->live(onBlur: true)
                        /*
                         * `regex` y no `numeric`: `numeric` acepta "1e3",
                         * que entra a bcmath como cero y dejaría el
                         * producto gratis con cara de precio cargado.
                         */
                        ->rule('regex:/^\d{1,9}(\.\d{1,4})?$/')
                        ->validationMessages([
                            'regex' => 'Escribí solo el número, con punto decimal: 29.33.',
                        ])
                        ->helperText(fn (Get $get): string => $this->ayudaDelPrecio($get)),

                    DatePicker::make('vigencia_desde')
                        ->label('Vigente desde')
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Posterior a los precios que ya hay para ese pagador.'),

                    CampoMayusculas::area('motivo')
                        ->label('Por qué')
                        ->columnSpanFull()
                        ->required()
                        ->minLength(10)
                        ->rows(2)
                        ->placeholder('Ej.: costo nuevo del proveedor, tarifa firmada con el seguro…')
                        ->helperText('Es lo que se lee cuando alguien pregunte, dentro de dos años, por qué se vendía a este precio.'),
                ]),
            ])
            ->action(function (array $data, Action $action, FijadorDePrecio $fijador): void {
                $item = $this->getOwnerRecord();

                if (! $item instanceof Item) {
                    return;
                }

                /** @var string $precio */
                $precio = $data['precio'];

                /** @var string $motivo */
                $motivo = $data['motivo'];

                /** @var string $desde */
                $desde = $data['vigencia_desde'];

                try {
                    $fila = $fijador->fijar(
                        item: $item,
                        convenio: self::convenioDe($data['convenio_id'] ?? null),
                        sede: self::sedeDe($data['sede_id'] ?? null),
                        precio: Monto::de($precio),
                        motivo: $motivo,
                        desde: Carbon::parse($desde),
                        presentacion: self::presentacionDe($data['item_presentacion_id'] ?? null),
                    );
                } catch (PrecioNoFijableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo fijar el precio')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    /*
                     * `halt()` lanza una excepción de Filament pero está
                     * declarado `void`: el `return` explícito deja claro
                     * —al analizador y a quien lea— que abajo no se sigue.
                     */
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Precio fijado en '.$fila->monto()->formateado())
                    ->body('Rige desde el '.$fila->vigencia_desde->format('d/m/Y').'.')
                    ->send();
            });
    }

    /**
     * La sede de quien mira: los precios que se muestran son los que se
     * cobran en ella.
     */
    public static function sedeActual(): ?Sede
    {
        $id = ContextoSede::actualId();

        return $id === null ? null : Sede::query()->find($id);
    }

    /**
     * ¿A este ítem la factura le suma ISV?
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 «ANTES DEL ISV» SOLO DONDE HAY ISV (Mauricio, 10-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Los medicamentos, el laboratorio, la hospitalización y los demás
     * servicios médicos son EXENTOS (Ley del ISV, Art. 15 b y d). En
     * ellos «Precio antes del ISV» hacía pensar que al número escrito se
     * le iba a sumar un 15 % en la factura, y quien carga el catálogo
     * terminaba descontándolo a mano. Lo dice solo lo que sí va gravado:
     * cafetería, estética, parqueo.
     */
    private function llevaIsv(): bool
    {
        $item = $this->getOwnerRecord();

        return $item instanceof Item && $item->regimen_isv->esGravado();
    }

    /**
     * ¿Es un producto de farmacia, que se vende por formas —suelta,
     * blíster, caja— y se pone precio desde el mínimo?
     */
    private function seVendePorFormas(): bool
    {
        $item = $this->getOwnerRecord();

        return $item instanceof Item && $item->mueveInventario();
    }

    /**
     * «Precio por TAB», o «Precio por TAB, antes del ISV» en lo que sí
     * lleva impuesto. Sin unidad —un servicio—, «Precio» a secas.
     */
    private function rotuloDelPrecio(): string
    {
        $item = $this->getOwnerRecord();

        $porUnidad = $item instanceof Item && $item->unidad_dispensacion_id !== null
            ? 'Precio por '.$item->unidadDispensacion->codigo
            : 'Precio';

        return $this->llevaIsv() ? $porUnidad.', antes del ISV' : $porUnidad;
    }

    /**
     * Debajo del precio: si lleva ISV o no, y cuánto sale el envase
     * elegido —«El BLISTER X 10 TABLETAS sale a L 25.00»—, que es la
     * cifra con la que se compara contra la factura del proveedor.
     */
    private function ayudaDelPrecio(Get $get): string
    {
        $isv = $this->llevaIsv()
            ? 'La factura le suma el ISV de este ítem.'
            : 'Exento de ISV: se cobra tal cual.';

        $precio = NumeroDeFormulario::aDecimal($get('precio'));
        $envase = self::presentacionDe($get('item_presentacion_id'));

        if (! $precio instanceof Decimal
            || ! $envase instanceof ItemPresentacion
            || ! is_numeric($envase->unidades_por_presentacion)
        ) {
            return $isv;
        }

        $porEnvase = Monto::de($precio->por($envase->unidades_por_presentacion)->redondeado(2));

        return $isv.' El '.$envase->envase().' sale a '.$porEnvase->formateado().'.';
    }

    private static function convenioDe(mixed $id): ?Convenio
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Convenio::query()->find((int) $id);
    }

    private static function presentacionDe(mixed $valor): ?ItemPresentacion
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $presentacion = ItemPresentacion::query()->find((int) $valor);

        return $presentacion instanceof ItemPresentacion ? $presentacion : null;
    }

    private static function sedeDe(mixed $id): ?Sede
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Sede::query()->find((int) $id);
    }
}
