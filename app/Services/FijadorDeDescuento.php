<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Descuento;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Crear un descuento del hospital, o cambiarle el porcentaje.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE EDITA: SE CIERRA EL VIGENTE Y SE ABRE UNO NUEVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La pregunta que la tabla contesta no es «cuánto se descuenta», es
 * **«cuánto se descontaba el día del servicio»**. Una factura de marzo
 * que se reimprime en septiembre tiene que salir con el porcentaje de
 * marzo, porque ya se cobró y ya se declaró (ADR-0003).
 *
 * Un `UPDATE` sobre la fila vigente borraría esa respuesta para siempre,
 * y con ella la defensa del hospital ante un reclamo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CORREGIR NO ES LO MISMO QUE CAMBIAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el porcentaje que se reemplaza empieza EL MISMO DÍA que el nuevo,
 * no hubo dos porcentajes: hubo uno mal tecleado. Ahí se corrige con un
 * `UPDATE` y no se abre una fila más.
 *
 * Y no es una comodidad: cerrar el vigente poniéndole «el día anterior»
 * cuando empezó hoy dejaría la fila con desde-hoy y hasta-ayer, que la
 * columna generada `vigencia` rechaza al construir el rango. Sin esta
 * rama, corregir un cero de más recién cargado revienta contra la base
 * con un mensaje sobre límites de rango que nadie va a entender.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE SERVICIO NO DEJA CAMBIAR: EL DESTINATARIO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `aplica_a` queda fijo para toda la vida de un nombre. Los ítems se
 * marcan contra el NOMBRE, así que dejar que «Tercera edad» pase a ser
 * manual a mitad de año le cambiaría el destinatario a todos los ítems
 * marcados de una sola vez y sin decir nada. Cambiar de destinatario
 * exige cambiar de nombre.
 */
final class FijadorDeDescuento
{
    /**
     * @param Decimal $porcentaje fracción: 0.25 es 25 %
     *
     * @throws DescuentoNoFijableException
     */
    public function fijar(
        string $nombre,
        AplicacionDeDescuento $aplicaA,
        Decimal $porcentaje,
        CarbonInterface $desde,
        bool $exigeReceta = false,
        ?string $nota = null,
    ): Descuento {
        $nombre = trim($nombre);
        $nota = $this->limpio($nota);

        if (mb_strlen($nombre) < 3) {
            throw DescuentoNoFijableException::elNombreEsMuyCorto($nombre);
        }

        if ($porcentaje->esNegativo() || $porcentaje->mayorQue('1')) {
            throw DescuentoNoFijableException::porcentajeFueraDeRango($porcentaje->redondeado(4));
        }

        $dia = $desde->copy()->startOfDay();

        return DB::transaction(function () use (
            $nombre,
            $aplicaA,
            $porcentaje,
            $dia,
            $exigeReceta,
            $nota
        ): Descuento {
            $anterior = Descuento::query()
                ->where('nombre', $nombre)
                ->orderByDesc('vigencia_desde')
                ->lockForUpdate()
                ->first();

            if ($anterior instanceof Descuento) {
                if ($anterior->aplica_a !== $aplicaA) {
                    throw DescuentoNoFijableException::elNombreYaAplicaAOtraCosa(
                        $nombre,
                        $anterior->aplica_a,
                    );
                }

                /*
                 * Uno que arranca DESPUÉS del día pedido no se puede
                 * dejar atrás: quedaría un hueco o un traslape, y la
                 * restricción de exclusión lo rechazaría con un error
                 * que no menciona ninguna de las dos cosas.
                 */
                if ($anterior->vigencia_desde->startOfDay()->greaterThan($dia)) {
                    throw DescuentoNoFijableException::yaHayUnoPosteriorLlamado(
                        $nombre,
                        $anterior->vigencia_desde,
                    );
                }

                if ($anterior->vigencia_desde->startOfDay()->equalTo($dia)) {
                    $anterior->update([
                        'porcentaje'   => $porcentaje->paraBase(4),
                        'exige_receta' => $exigeReceta,
                        'nota'         => $nota,
                    ]);

                    return $anterior->refresh();
                }

                if ($anterior->vigencia_hasta === null) {
                    $anterior->update(['vigencia_hasta' => $dia->copy()->subDay()]);
                }
            }

            /*
             * No se copian las marcas de los ítems a la fila nueva, y es
             * deliberado: el resolutor busca por NOMBRE, así que la fila
             * nueva ya le llega a todos los ítems que tenían marcada la
             * vieja. Copiar el pivote sería duplicar la verdad en dos
             * lugares que después se despegan.
             */
            return Descuento::query()->create([
                'nombre'         => $nombre,
                'porcentaje'     => $porcentaje->paraBase(4),
                'aplica_a'       => $aplicaA->value,
                'exige_receta'   => $exigeReceta,
                'nota'           => $nota,
                'vigencia_desde' => $dia,
                'vigencia_hasta' => null,
            ]);
        });
    }

    private function limpio(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $limpio = trim($texto);

        return $limpio === '' ? null : $limpio;
    }
}
