<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\DescuentoAplicable;
use App\Models\DescuentoLegal;
use App\Models\Item;
use Carbon\CarbonInterface;

/**
 * Contesta "cuánto descuento le tocaba a esto el día del servicio".
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA FECHA NUNCA ES OPCIONAL
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ningún método de acá acepta que no le pasen la fecha del servicio, y
 * no hay un default de "hoy". Es la misma regla que en `RangoEdad`: un
 * descuento resuelto contra hoy reimprime la factura de 2027 con el
 * porcentaje de 2029, y esa factura ya se le cobró a alguien.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA ESCALERA DE RANGOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un paciente de 80 años también tiene 60. Si la ley no le da nada
 * específico a la cuarta edad —que es el caso hoy en salud—, le
 * corresponde lo de la tercera, no cero.
 *
 * Por eso se consultan TODOS los rangos de la escalera y se toma el
 * mejor, en vez de buscar el más específico y rendirse. Además de
 * resolver el caso de hoy, protege contra un dato mal cargado: si
 * alguien registrara una cuarta edad con MENOS porcentaje que la
 * tercera, el paciente igual recibe el mayor. La ley no le puede dar
 * menos a alguien por ser más viejo.
 */
final class ResolutorDeDescuentoLegal
{
    /**
     * El descuento de un ítem para un paciente, en la fecha del servicio.
     */
    public function para(Item $item, RangoEdad $rango, CarbonInterface $fechaServicio): DescuentoAplicable
    {
        return $this->paraCategoria($item->categoria_legal_descuento, $rango, $fechaServicio);
    }

    public function paraCategoria(
        CategoriaLegalDeDescuento $categoria,
        RangoEdad $rango,
        CarbonInterface $fechaServicio,
    ): DescuentoAplicable {
        $escalera = $rango->escalera();

        if ($escalera === [] || $categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            return DescuentoAplicable::ninguno();
        }

        return $this->mejorDe($categoria, $escalera, $fechaServicio);
    }

    /**
     * El peor caso: el descuento más alto que este ítem puede recibir de
     * cualquier rango de edad.
     *
     * Es de acá que sale el divisor del precio de lista (§4.5):
     *
     *     precio_lista = costo × (1 + margen) / (1 − descuento_máximo)
     *
     * Calcularlo desde el peor caso es lo que convierte el piso de margen
     * en garantía y no en objetivo que se incumple con cada paciente
     * mayor.
     */
    public function maximoPara(
        CategoriaLegalDeDescuento $categoria,
        CarbonInterface $fechaServicio,
    ): DescuentoAplicable {
        if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            return DescuentoAplicable::ninguno();
        }

        return $this->mejorDe($categoria, RangoEdad::conDerechoADescuento(), $fechaServicio);
    }

    /**
     * De los rangos que se le pasan, el de mayor porcentaje vigente ese
     * día.
     *
     * @param list<RangoEdad> $rangos
     */
    private function mejorDe(
        CategoriaLegalDeDescuento $categoria,
        array $rangos,
        CarbonInterface $fechaServicio,
    ): DescuentoAplicable {
        $mejor = DescuentoLegal::query()
            ->deLaEscalera($categoria, $rangos)
            ->vigentesEn($fechaServicio)
            ->orderByDesc('porcentaje')
            ->first();

        return $mejor instanceof DescuentoLegal
            ? DescuentoAplicable::desdeLaLey($mejor)
            : DescuentoAplicable::ninguno();
    }
}
