<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\Exceptions\SihlaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrecioMinimo;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Los precios de farmacia se acomodan solos (Mauricio, 13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 «QUE NO SE VENDA A PÉRDIDA, Y QUE SEA AUTOMÁTICO»
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Si cambio el blíster a 12 tabletas tiene que actualizar el precio. Lo
 * mismo si el descuento cambia, a más o a menos. Si baja al 30, bajan los
 * precios para seguir vendiendo con ganancia y con mejores precios.» Y
 * «que quede en el historial que el descuento o la cantidad cambió y por
 * eso se modificó el precio».
 *
 * Cada lista de farmacia sale de un mínimo que alguien escribió
 * (`PrecioMinimo`). Cuando cambia lo que hay alrededor —lo que trae un
 * envase, cuál es la principal, el descuento por edad más alto— se vuelve
 * a calcular desde ESE mínimo, con los mismos recargos:
 *
 *   Mínimo L 120 por la CAJA X 100, cuarta edad al 40 %   → CAJA L 200.00
 *   la cuarta edad sube al 45 %   120 ÷ 0.55 = 218.18    → CAJA L 219.00
 *   la cuarta edad baja al 30 %   120 ÷ 0.70 = 171.43    → CAJA L 172.00
 *   el BLISTER pasa de 10 a 12    1.20 × 12 × 1.10 = 15.84 → L 26.50
 *
 * Lo que se conserva es el mínimo POR UNIDAD: un envase más grande sale
 * proporcionalmente más caro, nunca más barato por tableta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO SI CAMBIA ALGO, Y CON SU PORQUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Si las listas que salen son las mismas que rigen, no se escribe
 *     nada. Correr esto dos veces no deja dos cambios en el historial.
 *   · Lo que se escribe lleva el motivo: «AJUSTE AUTOMÁTICO: CUARTA EDAD
 *     40 % → 45 %», «AJUSTE AUTOMÁTICO: BLISTER X 10 TAB PASÓ A
 *     BLISTER X 12 TAB». Y al lado, como siempre, el mínimo del que salió.
 *   · Rige desde el día pedido. Si con un precio de ese mismo día ya se
 *     cobró, ese precio es historia que una factura cita: el ajuste
 *     arranca al día siguiente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO TOCA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una lista que no salió de un mínimo —la cargó alguien a mano, o la
 * movió un ajuste masivo de la base de precios— se respeta: el último
 * precio que rige tiene que llevar el «— Mínimo …» que escribe
 * `FijadorDePreciosPorForma`. Si no, alguien decidió otra cosa después y
 * el sistema no le pasa por encima.
 *
 * @phpstan-type Reajuste array{
 *     sede: string,
 *     desde: CarbonInterface,
 *     formas: list<array{rotulo: string, antes: Monto|null, ahora: Monto}>,
 * }
 */
final class ReajustadorDePrecios
{
    public const PREFIJO = 'AJUSTE AUTOMÁTICO: ';

    public function __construct(
        private readonly CalculadoraDePreciosPorForma $calculadora,
        private readonly FijadorDePreciosPorForma $fijador,
        private readonly ResolutorDeDescuentoLegal $descuentos,
    ) {}

    /**
     * Recalcula los precios del producto en cada sede que tiene un mínimo
     * guardado, desde `$dia`.
     *
     * @param string $porQue lo que cambió, sin el prefijo
     *
     * @return list<Reajuste> lo que cambió, una entrada por sede
     *
     * @throws PrecioNoFijableException
     */
    public function reajustar(Item $item, CarbonInterface $dia, string $porQue): array
    {
        $politicas = PrecioMinimo::query()
            ->where('item_id', $item->id)
            ->vigentesEn($dia)
            ->orderByRaw('sede_id is not null')
            ->orderBy('sede_id')
            ->get();

        if ($politicas->isEmpty()) {
            return [];
        }

        $presentaciones = ItemPresentacion::query()->with('unidad')->where('item_id', $item->id)->get();
        $motivo = self::PREFIJO.mb_strtoupper(trim($porQue));
        $reajustes = [];

        foreach ($politicas as $politica) {
            $reajuste = $this->enLaSedeDe($item, $politica, $presentaciones, $dia, $motivo);

            if ($reajuste !== null) {
                $reajustes[] = $reajuste;
            }
        }

        return $reajustes;
    }

    /**
     * Todos los productos con un mínimo guardado, contra el descuento por
     * edad que rige `$dia`. Es lo que corre cuando cambia la ley o un
     * descuento del hospital.
     *
     * Cada producto en su propia transacción: si uno no se puede ajustar
     * —tiene un precio programado para más adelante—, los demás siguen.
     *
     * @return int cuántos productos cambiaron de precio
     */
    public function alDescuentoPorEdad(CarbonInterface $dia): int
    {
        $ids = PrecioMinimo::query()
            ->vigentesEn($dia)
            ->distinct()
            ->orderBy('item_id')
            ->pluck('item_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $cambiaron = 0;

        foreach (array_chunk($ids, 100) as $tanda) {
            $productos = Item::query()->with('unidadDispensacion')->whereIn('id', $tanda)->get();

            foreach ($productos as $item) {
                try {
                    if ($this->reajustar($item, $dia, $this->porQueCambioElDescuento($item, $dia)) !== []) {
                        $cambiaron++;
                    }
                } catch (SihlaException $e) {
                    report($e);
                }
            }
        }

        return $cambiaron;
    }

    /**
     * @param Collection<int, ItemPresentacion> $presentaciones
     *
     * @return Reajuste|null
     *
     * @throws PrecioNoFijableException
     */
    private function enLaSedeDe(
        Item $item,
        PrecioMinimo $politica,
        Collection $presentaciones,
        CarbonInterface $dia,
        string $motivo,
    ): ?array {
        $vigentes = $this->deLaLlave($item, $politica->sede_id)->vigentesEn($dia)->get();

        if (! $this->salioDeUnMinimo($vigentes)) {
            return null;
        }

        $principal = $this->calculadora->principalEntre($this->calculadora->envasesDe($presentaciones));
        $contenido = $principal instanceof ItemPresentacion ? Decimal::de($principal->unidades_por_presentacion) : Decimal::de('1');

        $precios = $this->calculadora->desdeElMinimo(
            item: $item,
            presentaciones: $presentaciones,
            minimoDeLaPrincipal: $politica->minimoPara($contenido),
            fecha: $dia,
            recargoEnvase: $politica->recargoEnvase(),
            recargoSuelta: $politica->recargoSuelta(),
        );

        $formas = [];
        $cambia = false;

        foreach ($precios->formas as $forma) {
            $presentacionId = $forma->presentacion?->id;
            $fila = $vigentes->first(fn (Tarifario $una): bool => $una->item_presentacion_id === $presentacionId);
            $antes = $fila instanceof Tarifario ? ($forma->esLaSuelta() ? $fila->monto() : $fila->montoDelEnvase()) : null;

            if (! $antes instanceof Monto || ! $antes->igualA($forma->lista)) {
                $cambia = true;
            }

            $formas[] = ['rotulo' => $forma->rotulo, 'antes' => $antes, 'ahora' => $forma->lista];
        }

        if (! $cambia) {
            return null;
        }

        $desde = $dia->copy()->startOfDay();

        if ($this->yaSeCobroConUnoDe($item, $politica->sede_id, $desde)) {
            $desde = $desde->addDay();
        }

        $sede = $politica->sede_id === null ? null : Sede::query()->find($politica->sede_id);

        $this->fijador->reajustar($item, $precios, $sede instanceof Sede ? $sede : null, $motivo, $desde);

        return [
            'sede'   => $sede instanceof Sede ? $sede->nombre : 'Todas las sedes',
            'desde'  => $desde,
            'formas' => array_reverse($formas),
        ];
    }

    /**
     * ¿Lo último que se fijó en esta sede salió de un mínimo? Si alguien lo
     * cargó a mano después, el mínimo guardado ya no manda.
     *
     * @param Collection<int, Tarifario> $vigentes
     */
    private function salioDeUnMinimo(Collection $vigentes): bool
    {
        $ultima = $vigentes
            ->sort(fn (Tarifario $una, Tarifario $otra): int => [$otra->vigencia_desde->toDateString(), $otra->id]
                <=> [$una->vigencia_desde->toDateString(), $una->id])
            ->first();

        return $ultima instanceof Tarifario && FijadorDePreciosPorForma::origenDe($ultima->motivo) !== null;
    }

    /**
     * La misma pregunta que se hace `FijadorDePreciosPorForma` antes de
     * corregir lo del día: si con alguno de ese día ya se cobró, se arranca
     * mañana en vez de reventar.
     */
    private function yaSeCobroConUnoDe(Item $item, ?int $sedeId, CarbonInterface $dia): bool
    {
        foreach ($this->deLaLlave($item, $sedeId)->whereDate('vigencia_desde', $dia->toDateString())->get() as $fila) {
            if ($fila->yaSeUso()) {
                return true;
            }
        }

        return false;
    }

    /**
     * La lista de una sede exacta —o la de todas—, sin mezclar: cada
     * mínimo ajusta solo lo suyo.
     *
     * @return Builder<Tarifario>
     */
    private function deLaLlave(Item $item, ?int $sedeId): Builder
    {
        $consulta = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id');

        return $sedeId === null
            ? $consulta->whereNull('sede_id')
            : $consulta->where('sede_id', $sedeId);
    }

    /**
     * «CUARTA EDAD 40 % → 45 %»: qué descuento por edad cambió entre el día
     * en que se calculó la lista que rige y `$dia`.
     *
     * Si la lista se calculó ese mismo día, se compara con el día
     * anterior: es el caso de quien fija el precio a la mañana y la ley
     * cambia a la tarde.
     */
    public function porQueCambioElDescuento(Item $item, CarbonInterface $dia): string
    {
        $calculada = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->vigentesEn($dia)
            ->max('vigencia_desde');

        $hoy = $dia->copy()->startOfDay();
        $antes = is_string($calculada) ? Carbon::parse($calculada)->startOfDay() : $hoy->copy()->subDay();

        if (! $antes->lessThan($hoy)) {
            $antes = $hoy->copy()->subDay();
        }

        $cambios = [];

        foreach (RangoEdad::cases() as $rango) {
            if (! $rango->tieneDescuentoLegal()) {
                continue;
            }

            $eraDe = $this->descuentos->para($item, $rango, $antes)->fraccion;
            $esDe = $this->descuentos->para($item, $rango, $hoy)->fraccion;

            if (! $eraDe->igualA($esDe)) {
                $cambios[] = sprintf('%s %s → %s', mb_strtoupper($rango->etiqueta()), $eraDe->comoPorcentaje(), $esDe->comoPorcentaje());
            }
        }

        return $cambios === []
            ? 'PARA QUE NINGUNA EDAD PAGUE MENOS QUE EL MÍNIMO'
            : 'CAMBIÓ EL DESCUENTO POR EDAD ('.implode('; ', $cambios).')';
    }
}
