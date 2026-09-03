<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Prestamo;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL ÚNICO MOMENTO EN QUE LA DEUDA SE PUEDE PAGAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: «cuando a nosotros nos entre de ese medicamento a
 * bodega o farmacia, que aparezca que hay que devolverle x cantidad a x
 * empresa o persona».
 *
 * Un préstamo de medicamento se pide un martes a las once de la noche
 * porque no había, y se devuelve el día que llega la compra. Entre esos
 * dos momentos no hay nada que hacer: no se puede devolver lo que no se
 * tiene. Así que la pantalla de «lo que se debe» sirve para consultar,
 * pero no es donde la deuda se salda — nadie la abre por las mañanas.
 *
 * Donde se salda es acá: entra la caja, el producto está en la mano, y
 * ahí es cuando decir «de esto hay que devolverle 20 tabletas a Farmacia
 * San José» convierte un aviso en un acto. Un mes después, esas 20
 * tabletas ya se despacharon y la deuda se paga en efectivo, más caro.
 *
 * Este servicio contesta una sola pregunta, y la contesta igual desde las
 * tres pantallas que la hacen: el renglón de la recepción mientras se
 * teclea, el resumen de confirmación, y el aviso de después de guardar.
 * Tres redacciones distintas de la misma deuda es como una dice 20 y otra
 * 200.
 */
final class AvisoDeLoQueSeDebe
{
    /**
     * Lo que se le debe a alguien de estos productos.
     *
     * ⚠️ Solo lo que se DEBE de verdad: lo que trajo el médico o la
     * familia del paciente está registrado para que el kardex cuadre,
     * pero no hay a quién devolvérselo y ponerlo acá sería ruido — un
     * aviso con ruido se aprende a ignorar en tres recepciones.
     *
     * @param list<int> $itemIds
     *
     * @return ColeccionDeModelos<int, Prestamo>
     */
    public function deLosItems(array $itemIds): ColeccionDeModelos
    {
        $ids = array_values(array_unique(array_filter($itemIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            /** @var ColeccionDeModelos<int, Prestamo> $vacia */
            $vacia = new ColeccionDeModelos;

            return $vacia;
        }

        return Prestamo::query()
            ->queSeDeben()
            ->whereIn('item_id', $ids)
            ->with(['item.unidadDispensacion', 'almacen'])
            /*
             * El más viejo primero, como en la pantalla de préstamos: de
             * una deuda lo que importa es cuánto lleva sin saldarse.
             */
            ->orderBy('fecha_operacion')
            ->get();
    }

    /**
     * «20 TAB de ACETAMINOFEN 500 MG TABLETA a FARMACIA SAN JOSÉ»
     */
    public function comoSeLee(Prestamo $prestamo): string
    {
        $unidad = $prestamo->item->unidadDispensacion;

        return sprintf(
            '%s%s de %s a %s',
            $prestamo->saldoPendiente()->redondeado(2),
            $unidad instanceof Unidad ? ' '.$unidad->codigo : '',
            $prestamo->item->nombre,
            $prestamo->presta_nombre,
        );
    }

    /**
     * El aviso entero, o null si no se debe nada de lo que está entrando.
     *
     * ⚠️ Devuelve null y no una cadena vacía a propósito: quien llama
     * tiene que poder preguntar «¿hay algo que avisar?» sin comparar
     * contra ''. Un aviso vacío pintado igual que uno lleno es un
     * recuadro que no dice nada y que se aprende a saltear.
     *
     * @param list<int> $itemIds
     */
    public function frase(array $itemIds): ?string
    {
        $deudas = $this->deLosItems($itemIds);

        if ($deudas->isEmpty()) {
            return null;
        }

        $renglones = $deudas
            ->map(fn (Prestamo $prestamo): string => $this->comoSeLee($prestamo))
            ->all();

        return 'De esto hay que devolver: '.implode(' · ', $renglones).'.';
    }

    /**
     * Cuánto se le debe de UN producto, para el renglón de la recepción.
     *
     * Va por producto y no por la lista entera porque el renglón lo
     * pregunta mientras se teclea: lo que hace falta ahí es la deuda de
     * lo que se acaba de elegir, no la de toda la compra.
     */
    public function delItem(int $itemId): ?string
    {
        return $this->frase([$itemId]);
    }
}
