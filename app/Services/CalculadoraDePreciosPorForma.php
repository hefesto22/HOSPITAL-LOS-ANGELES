<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\DescuentoAplicable;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioDeForma;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * De UN número —el mínimo del envase principal— a todos los precios del
 * producto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA REGLA, EN LAS PALABRAS DE MAURICIO (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «La caja a 120 es lo mínimo que puede pagar la cuarta edad; menos de
 * ahí se considera pérdida.» Y la caja es el precio de promoción: el
 * blíster y la tableta suelta salen más caros por unidad.
 *
 *   1. Por unidad: mínimo ÷ lo que trae la principal.   120 ÷ 100 = 1.20
 *   2. Cada forma: por unidad × lo que trae × (1 + recargo).
 *        · la principal y los envases MÁS GRANDES, sin recargo;
 *        · los envases más chicos, el recargo de envase (+10 %);
 *        · la unidad suelta, el recargo de suelta (+25 %).
 *   3. La lista: mínimo ÷ (1 − descuento máximo de ley), redondeada
 *      HACIA ARRIBA (`RedondeoDePrecio`). Con el 40 % de la cuarta edad,
 *      L 120 → L 200: la cuarta edad paga exactamente el mínimo.
 *
 *   CAJA X 100     L 120.00 → L 200.00   tercera L 150.00 · cuarta L 120.00
 *   BLISTER X 10    L 13.20 →  L 22.00   tercera  L 16.50 · cuarta  L 13.20
 *   TABLETA          L 1.50 →   L 2.50   tercera   L 1.88 · cuarta   L 1.50
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA LISTA PARA TODOS, NO UNA POR EDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es la política cerrada del 17-ago-2026, la misma de
 * `CalculadoraDePrecioDeLista`: el adulto mayor ve el mismo precio que
 * cualquiera y su descuento de ley cae encima. Un precio de lista más alto
 * solo para él anularía el descuento, y eso se descartó y no se vuelve a
 * proponer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTO CALCULA, NO GUARDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La pantalla muestra estos números antes de confirmar, y el que guarda
 * es `FijadorDePreciosPorForma` con este mismo resultado: las dos ven lo
 * mismo porque es el mismo objeto.
 */
final class CalculadoraDePreciosPorForma
{
    public function __construct(
        private readonly ResolutorDeMargenObjetivo $politicas,
        private readonly ResolutorDeDescuentoLegal $descuentos,
        private readonly RedondeoDePrecio $redondeo,
    ) {}

    /**
     * @param Collection<int, ItemPresentacion> $presentaciones las del ítem; la principal es la habitual
     * @param Decimal|null $recargoEnvase nulo = el de la política vigente del tipo
     * @param Decimal|null $recargoSuelta nulo = el de la política vigente del tipo
     *
     * @throws PrecioNoFijableException
     */
    public function desdeElMinimo(
        Item $item,
        Collection $presentaciones,
        Decimal $minimoDeLaPrincipal,
        CarbonInterface $fecha,
        ?Decimal $recargoEnvase = null,
        ?Decimal $recargoSuelta = null,
    ): PreciosPorForma {
        if (! $minimoDeLaPrincipal->mayorQue('0')) {
            throw PrecioNoFijableException::sinMinimo();
        }

        $recargoEnvase ??= $this->recargoEnvaseDe($item, $fecha);
        $recargoSuelta ??= $this->recargoSueltaDe($item, $fecha);

        $descuentoMaximo = $this->descuentos->maximoParaItem($item, $fecha)->fraccion;
        $divisor = Decimal::de('1')->restar($descuentoMaximo);

        $envases = $this->envasesDe($presentaciones);
        $principal = $this->principalEntre($envases);
        $contenidoPrincipal = $principal instanceof ItemPresentacion ? self::contenidoDe($principal) : Decimal::de('1');
        $porUnidad = $minimoDeLaPrincipal->entre($contenidoPrincipal);
        $descuentosPorEdad = $this->descuentosPorEdad($item, $fecha);

        $formas = [$this->forma(
            clave: FormasDeCobro::SUELTA,
            presentacion: null,
            rotulo: $this->rotuloDeLaSuelta($item),
            contenido: Decimal::de('1'),
            recargo: $principal instanceof ItemPresentacion ? $recargoSuelta : Decimal::cero(),
            esLaPrincipal: ! $principal instanceof ItemPresentacion,
            porUnidad: $porUnidad,
            divisor: $divisor,
            descuentosPorEdad: $descuentosPorEdad,
        )];

        foreach ($envases as $envase) {
            $contenido = self::contenidoDe($envase);
            $esLaPrincipal = $principal instanceof ItemPresentacion && $envase->id === $principal->id;

            $formas[] = $this->forma(
                clave: FormasDeCobro::ENVASE.$envase->id,
                presentacion: $envase,
                rotulo: $envase->envase(),
                contenido: $contenido,
                recargo: ! $esLaPrincipal && $contenido->menorQue($contenidoPrincipal) ? $recargoEnvase : Decimal::cero(),
                esLaPrincipal: $esLaPrincipal,
                porUnidad: $porUnidad,
                divisor: $divisor,
                descuentosPorEdad: $descuentosPorEdad,
            );
        }

        return new PreciosPorForma(
            formas: $formas,
            recargoEnvase: $recargoEnvase,
            recargoSuelta: $recargoSuelta,
            descuentoMaximo: $descuentoMaximo,
        );
    }

    /**
     * Los recargos que propone la pantalla antes de que nadie los toque.
     *
     * @return array{envase: Decimal, suelta: Decimal}
     */
    public function recargosPara(Item $item, CarbonInterface $fecha): array
    {
        return [
            'envase' => $this->recargoEnvaseDe($item, $fecha),
            'suelta' => $this->recargoSueltaDe($item, $fecha),
        ];
    }

    /**
     * La principal, entre los envases que se venden sin abrir: la que tiene
     * la estrella de la ficha. Sin ninguna, la principal es la unidad
     * suelta —la bolsa de suero, la ampolla—.
     *
     * @param list<ItemPresentacion> $envases
     */
    public function principalEntre(array $envases): ?ItemPresentacion
    {
        foreach ($envases as $envase) {
            if ($envase->es_predeterminada) {
                return $envase;
            }
        }

        return null;
    }

    /**
     * Los envases que se venden sin abrir —los que traen más de una
     * unidad—, de chico a grande. «AMPOLLA X 1» es la unidad suelta otra
     * vez: no es una forma aparte (`FormasDeCobro`).
     *
     * @param Collection<int, ItemPresentacion> $presentaciones
     *
     * @return list<ItemPresentacion>
     */
    public function envasesDe(Collection $presentaciones): array
    {
        /*
         * `array_values()` y no `->values()->all()`: `all()` devuelve
         * `array<int, X>`, que para el analizador no es una `list`.
         */
        return array_values($presentaciones
            ->filter(fn (ItemPresentacion $presentacion): bool => self::contenidoDe($presentacion)->mayorQue('1'))
            ->sort(fn (ItemPresentacion $una, ItemPresentacion $otra): int => self::contenidoDe($una)
                ->comparar(self::contenidoDe($otra)))
            ->all());
    }

    /**
     * @param list<array{rango: RangoEdad, descuento: DescuentoAplicable}> $descuentosPorEdad
     */
    private function forma(
        string $clave,
        ?ItemPresentacion $presentacion,
        string $rotulo,
        Decimal $contenido,
        Decimal $recargo,
        bool $esLaPrincipal,
        Decimal $porUnidad,
        Decimal $divisor,
        array $descuentosPorEdad,
    ): PrecioDeForma {
        $minimo = $porUnidad->por($contenido)->por(Decimal::de('1')->sumar($recargo));
        $lista = Monto::de($this->redondeo->haciaArriba($minimo->entre($divisor)));

        $porEdad = [];

        foreach ($descuentosPorEdad as ['rango' => $rango, 'descuento' => $descuento]) {
            $porEdad[] = ['rango' => $rango, 'paga' => $descuento->netoDe($lista)];
        }

        return new PrecioDeForma(
            clave: $clave,
            presentacion: $presentacion,
            rotulo: $rotulo,
            contenido: $contenido,
            recargo: $recargo,
            esLaPrincipal: $esLaPrincipal,
            minimo: Monto::de($minimo),
            lista: $lista,
            porEdad: $porEdad,
        );
    }

    /**
     * El descuento de ley de cada rango que lo tiene: tercera y cuarta.
     * Se resuelven una vez, no una por forma.
     *
     * @return list<array{rango: RangoEdad, descuento: DescuentoAplicable}>
     */
    private function descuentosPorEdad(Item $item, CarbonInterface $fecha): array
    {
        $porEdad = [];

        foreach (RangoEdad::cases() as $rango) {
            if ($rango->tieneDescuentoLegal()) {
                $porEdad[] = ['rango' => $rango, 'descuento' => $this->descuentos->para($item, $rango, $fecha)];
            }
        }

        return $porEdad;
    }

    private function recargoEnvaseDe(Item $item, CarbonInterface $fecha): Decimal
    {
        return $this->politicas->para($item->tipo, $fecha)?->recargoEnvase()
            ?? FijadorDeMargenObjetivo::porDefecto('envase');
    }

    private function recargoSueltaDe(Item $item, CarbonInterface $fecha): Decimal
    {
        return $this->politicas->para($item->tipo, $fecha)?->recargoSuelta()
            ?? FijadorDeMargenObjetivo::porDefecto('suelta');
    }

    private function rotuloDeLaSuelta(Item $item): string
    {
        $unidad = $item->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->nombre : 'UNIDAD';
    }

    private static function contenidoDe(ItemPresentacion $presentacion): Decimal
    {
        $contenido = $presentacion->unidades_por_presentacion;

        return is_numeric($contenido) ? Decimal::de($contenido) : Decimal::cero();
    }
}
