<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Actions;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioDeForma;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Sede;
use App\Models\Unidad;
use App\Services\CalculadoraDePreciosPorForma;
use App\Services\FijadorDePreciosPorForma;
use App\Support\NumeroDeFormulario;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

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
 */
final class FijarPrecioDeVentaAction
{
    public static function make(): Action
    {
        return Action::make('fijarPrecioDeVenta')
            ->label('Fijar el precio de venta')
            ->icon(Heroicon::OutlinedPlus)
            /*
             * Solo en lo que se almacena —medicamentos e insumos—, y con
             * el mismo permiso que fijar cualquier precio: `update` sobre
             * el ítem, que la matriz solo le concede a dirección.
             */
            ->visible(function (RelationManager $livewire): bool {
                $item = $livewire->getOwnerRecord();

                return $item instanceof Item && $item->mueveInventario() && Gate::allows('update', $item);
            })
            ->modalHeading('Fijar el precio de venta')
            ->modalDescription(fn (RelationManager $livewire): string => 'Escribí lo menos que se puede cobrar por '
                .self::laPrincipal($livewire).': lo que paga la cuarta edad. Menos de eso es pérdida. La '
                .'lista para todos y las demás formas de venta se calculan solas.')
            ->modalSubmitActionLabel('Fijar')
            ->modalWidth('3xl')
            ->fillForm(function (RelationManager $livewire): array {
                $item = $livewire->getOwnerRecord();
                $recargos = $item instanceof Item
                    ? app(CalculadoraDePreciosPorForma::class)->recargosPara($item, now())
                    : null;

                return [
                    'recargo_envase' => $recargos === null ? null : self::porciento($recargos['envase']),
                    'recargo_suelta' => $recargos === null ? null : self::porciento($recargos['suelta']),
                    'vigencia_desde' => now()->toDateString(),
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('minimo')
                        ->label(fn (RelationManager $livewire): string => 'Mínimo de '.self::laPrincipal($livewire))
                        ->prefix('L')
                        ->required()
                        ->autofocus()
                        ->live(onBlur: true)
                        /*
                         * `regex` y no `numeric`: `numeric` acepta «1e3»,
                         * que bcmath lee como cero.
                         */
                        ->rule('regex:/^\d{1,9}(\.\d{1,2})?$/')
                        ->validationMessages([
                            'regex' => 'Escribí solo el número, con punto decimal: 120 o 87.50.',
                        ])
                        ->helperText('Lo que paga la cuarta edad, con el 40 % de ley ya descontado.'),

                    Select::make('sede_id')
                        ->label('En qué sede')
                        ->options(fn (): array => Sede::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->placeholder('Todas las sedes')
                        ->native(false),

                    TextInput::make('recargo_envase')
                        ->label(fn (RelationManager $livewire): string => 'Recargo de '.self::elMasChico($livewire))
                        ->suffix('%')
                        ->required()
                        ->live(onBlur: true)
                        ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
                        ->validationMessages(['regex' => 'Solo el número: 10, o 12.5.'])
                        ->visible(fn (RelationManager $livewire): bool => self::elMasChico($livewire) !== null)
                        ->helperText('Cuánto más se cobra por unidad que en el envase principal.'),

                    TextInput::make('recargo_suelta')
                        ->label(fn (RelationManager $livewire): string => 'Recargo de '.self::laSuelta($livewire).' suelta')
                        ->suffix('%')
                        ->required()
                        ->live(onBlur: true)
                        ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
                        ->validationMessages(['regex' => 'Solo el número: 25, o 22.5.'])
                        ->visible(fn (RelationManager $livewire): bool => self::principalDe($livewire) instanceof ItemPresentacion)
                        ->helperText('Lo suelto cuesta más: abrir la caja deja tabletas que se vencen.'),

                    DatePicker::make('vigencia_desde')
                        ->label('Vigente desde')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Posterior a los precios que ya hay.'),
                ]),

                Placeholder::make('asi_quedan')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (Get $get, RelationManager $livewire): HtmlString => self::tabla(
                        self::calcular($livewire, $get('minimo'), $get('recargo_envase'), $get('recargo_suelta'), $get('vigencia_desde')),
                    )),

                Textarea::make('motivo')
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
                        sede: is_numeric($data['sede_id'] ?? null) ? Sede::query()->find((int) $data['sede_id']) : null,
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
                    ->title('Precios fijados')
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
     * La tabla que se lee antes de apretar «Fijar».
     *
     * Colores con transparencia y estilos en línea: se ve igual en tema
     * claro y oscuro, y el CSS del panel viene compilado (§9.A7).
     */
    private static function tabla(?PreciosPorForma $precios): HtmlString
    {
        if (! $precios instanceof PreciosPorForma) {
            return new HtmlString(
                '<div style="font-size:.875rem;opacity:.7">Escribí el mínimo y acá aparece cuánto se '
                .'cobra cada forma de venta.</div>'
            );
        }

        $edades = [];
        $primera = $precios->formas[0] ?? null;

        foreach ($primera instanceof PrecioDeForma ? $primera->porEdad : [] as ['rango' => $rango]) {
            $edades[] = '<th style="text-align:right;padding:.4rem .6rem;font-weight:600">'.e($rango->etiqueta()).'</th>';
        }

        $filas = '';

        foreach ($precios->formas as $forma) {
            $filas .= self::fila($forma);
        }

        return new HtmlString(
            '<div style="border:1px solid rgba(245,158,11,.30);background:rgba(245,158,11,.06);'
            .'border-radius:.625rem;padding:.5rem .25rem;overflow-x:auto">'
            .'<table style="width:100%;border-collapse:collapse;font-size:.875rem">'
            .'<thead><tr style="opacity:.75">'
            .'<th style="text-align:left;padding:.4rem .6rem;font-weight:600">Forma</th>'
            .'<th style="text-align:right;padding:.4rem .6rem;font-weight:600">Lista (todos)</th>'
            .implode('', $edades)
            .'</tr></thead><tbody>'.$filas.'</tbody></table>'
            .'<div style="font-size:.75rem;opacity:.7;padding:.4rem .6rem 0">La lista se redondea hacia '
            .'arriba, así que la cuarta edad nunca paga menos que el mínimo.</div>'
            .'</div>'
        );
    }

    private static function fila(PrecioDeForma $forma): string
    {
        $marca = $forma->esLaPrincipal
            ? ' <span style="color:rgb(245 158 11);font-weight:600">★ principal</span>'
            : ($forma->recargo->esCero() ? '' : ' <span style="opacity:.7">+'.e($forma->recargo->comoPorcentaje()).'</span>');

        $unitario = $forma->esLaSuelta()
            ? ''
            : '<div style="font-size:.75rem;opacity:.65">'
                .e(Monto::de($forma->listaPorUnidad())->formateado()).' c/u</div>';

        $porEdad = '';

        foreach ($forma->porEdad as ['paga' => $paga]) {
            $porEdad .= '<td style="text-align:right;padding:.4rem .6rem">'.e($paga->formateado()).'</td>';
        }

        return '<tr style="border-top:1px solid rgba(127,127,127,.18)">'
            .'<td style="padding:.4rem .6rem"><strong>'.e($forma->rotulo).'</strong>'.$marca.'</td>'
            .'<td style="text-align:right;padding:.4rem .6rem"><strong>'.e($forma->lista->formateado()).'</strong>'.$unitario.'</td>'
            .$porEdad
            .'</tr>';
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
