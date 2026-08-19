<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\MargenObjetivo;
use Carbon\CarbonInterface;

/**
 * Qué margen quería el hospital para este tipo de ítem, ese día.
 *
 * Escalera de dos peldaños: el margen propio del tipo, y si no tiene, el
 * default de la instalación. Los dos vienen en una sola consulta,
 * ordenados para que el específico gane.
 *
 * ⚠️ Devuelve `null` cuando no hay ninguno de los dos. No inventa un
 * default: sin margen definido no hay precio que calcular, y el que
 * llama tiene que decidir qué hacer con eso. Un default silencioso acá
 * sería un precio inventado con cara de calculado.
 */
final class ResolutorDeMargenObjetivo
{
    public function para(TipoItem $tipo, CarbonInterface $fecha): ?MargenObjetivo
    {
        $margen = MargenObjetivo::query()
            ->paraElTipo($tipo)
            ->vigentesEn($fecha)
            ->first();

        return $margen instanceof MargenObjetivo ? $margen : null;
    }

    public function fraccionPara(TipoItem $tipo, CarbonInterface $fecha): ?Decimal
    {
        return $this->para($tipo, $fecha)?->fraccion();
    }
}
