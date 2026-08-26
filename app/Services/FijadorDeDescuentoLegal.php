<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\DescuentoLegal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cargar un porcentaje de descuento de ley, con su vigencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE EDITA: SE CIERRA EL VIGENTE Y SE ABRE UNO NUEVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La pregunta que esta tabla contesta no es «cuánto se descuenta», es
 * **«cuánto se descontaba el día del servicio»**. Una factura de 2027
 * que se reimprime en 2029 tiene que salir con el porcentaje de 2027,
 * porque esa factura ya se le cobró a alguien y ya se declaró.
 *
 * Un `UPDATE` sobre la fila vigente borraría esa respuesta para siempre,
 * y con ella la defensa del hospital ante una denuncia a la línea 115.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CORREGIR NO ES LO MISMO QUE CAMBIAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el porcentaje que se está reemplazando empieza EL MISMO DÍA que el
 * nuevo, no hubo dos porcentajes: hubo uno mal tecleado. Ahí se corrige
 * con un `UPDATE` y no se abre una fila más.
 *
 * Y no es una comodidad: cerrar el vigente poniéndole «el día anterior»
 * cuando empezó hoy dejaría la fila con desde-hoy y hasta-ayer, que el
 * CHECK `descuentos_vigencia_coherente` rechaza. Sin esta rama, corregir
 * un cero de más recién cargado revienta contra la base.
 */
final class FijadorDeDescuentoLegal
{
    /**
     * @param Decimal $porcentaje fracción: 0.25 es 25 %
     *
     * @throws DescuentoNoFijableException
     */
    public function fijar(
        CategoriaLegalDeDescuento $categoria,
        RangoEdad $rango,
        Decimal $porcentaje,
        string $fundamento,
        CarbonInterface $desde,
        bool $exigeReceta = false,
        ?string $nota = null,
    ): DescuentoLegal {
        if ($porcentaje->esNegativo() || $porcentaje->mayorQue('1')) {
            throw DescuentoNoFijableException::porcentajeFueraDeRango($porcentaje->redondeado(4));
        }

        $dia = $desde->copy()->startOfDay();

        return DB::transaction(function () use (
            $categoria,
            $rango,
            $porcentaje,
            $fundamento,
            $dia,
            $exigeReceta,
            $nota
        ): DescuentoLegal {
            $anterior = DescuentoLegal::query()
                ->where('categoria_legal', $categoria->value)
                ->where('rango_edad', $rango->value)
                ->orderByDesc('vigencia_desde')
                ->lockForUpdate()
                ->first();

            if ($anterior instanceof DescuentoLegal) {
                /*
                 * Uno que arranca DESPUÉS del día pedido no se puede
                 * dejar atrás: quedaría un hueco o un traslape, y la
                 * restricción de exclusión lo rechazaría con un error
                 * que no menciona ninguna de las dos cosas.
                 */
                if ($anterior->vigencia_desde->startOfDay()->greaterThan($dia)) {
                    throw DescuentoNoFijableException::yaHayUnoPosterior(
                        $categoria,
                        $rango,
                        $anterior->vigencia_desde,
                    );
                }

                if ($anterior->vigencia_desde->startOfDay()->equalTo($dia)) {
                    $anterior->update([
                        'porcentaje'   => $porcentaje->paraBase(4),
                        'fundamento'   => $fundamento,
                        'exige_receta' => $exigeReceta,
                        'nota'         => $nota,
                    ]);

                    return $anterior->refresh();
                }

                if ($anterior->vigencia_hasta === null) {
                    $anterior->update(['vigencia_hasta' => $dia->copy()->subDay()]);
                }
            }

            return DescuentoLegal::query()->create([
                'categoria_legal' => $categoria->value,
                'rango_edad'      => $rango->value,
                'porcentaje'      => $porcentaje->paraBase(4),
                'fundamento'      => $fundamento,
                'exige_receta'    => $exigeReceta,
                'nota'            => $nota,
                'vigencia_desde'  => $dia,
                'vigencia_hasta'  => null,
            ]);
        });
    }
}
