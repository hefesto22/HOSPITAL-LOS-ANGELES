<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\DescuentoAplicable;
use App\Models\Descuento;
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
 * DOS FUENTES, Y GANA EL MAYOR
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · `descuentos_legales` — el Artículo 30, indexado por numeral. Es
 *     obligación legal y aplica por la CATEGORÍA del ítem.
 *   · `descuentos` — la lista con nombres que arma el hospital
 *     («Tercera edad», «Cuarta edad»), marcada ítem por ítem.
 *
 * Se consultan las dos y se toma la mayor. 🔴 No es que una reemplace a
 * la otra: **la ley es piso, nunca techo**. El hospital puede dar más
 * —es su plata— pero marcar un descuento propio no puede dejar a un
 * adulto mayor por debajo de lo que el Art. 30 le garantiza, ni siquiera
 * por error de carga.
 *
 * Consecuencia práctica: un ítem sin ningún descuento marcado se
 * comporta exactamente como antes de que existiera el módulo.
 *
 * ⚠️ Los descuentos del catálogo se resuelven POR NOMBRE, no por el `id`
 * que guarda el pivote. Ver el encabezado de `Descuento`: el pivote
 * apunta a la fila que estaba vigente el día que alguien marcó la
 * casilla, y esa fila envejece.
 *
 * ⚠️ Deuda declarada: la clase se sigue llamando «Legal» y ya devuelve
 * también lo que no lo es. El nombre correcto es `ResolutorDeDescuento`;
 * renombrarlo toca `CalculadoraDePrecioDeLista`, `RegistradorDeCargo` y
 * dos archivos de prueba, y no se hizo hoy para no mover código que
 * funciona la víspera de una demostración.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA ESCALERA DE RANGOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un paciente de 80 años también tiene 60. Si no hay nada específico
 * para la cuarta edad, le corresponde lo de la tercera, no cero.
 *
 * Por eso se consultan TODOS los rangos de la escalera y se toma el
 * mejor, en vez de buscar el más específico y rendirse. Además de
 * resolver ese caso, protege contra un dato mal cargado: si alguien
 * registrara una cuarta edad con MENOS porcentaje que la tercera, el
 * paciente igual recibe el mayor. La ley no le puede dar menos a alguien
 * por ser más viejo.
 */
final class ResolutorDeDescuentoLegal
{
    /**
     * El descuento de un ítem para un paciente, en la fecha del servicio.
     */
    public function para(Item $item, RangoEdad $rango, CarbonInterface $fechaServicio): DescuentoAplicable
    {
        $deLaLey = $this->paraCategoria($item->categoria_legal_descuento, $rango, $fechaServicio);

        /*
         * El de la ley va primero: empatados gana él, que es el que trae
         * el numeral del Art. 30 para mostrar ante un reclamo.
         */
        return $deLaLey->oElMejorDe($this->delCatalogo($item, $rango, $fechaServicio));
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
     * El descuento que le marcaron a ESTE ítem y que dispara esta edad.
     *
     * Devuelve «ninguno» si no le marcaron nada, que es el caso de todo
     * el catálogo mientras nadie use la pantalla nueva.
     */
    public function delCatalogo(
        Item $item,
        RangoEdad $rango,
        CarbonInterface $fechaServicio,
    ): DescuentoAplicable {
        if ($rango->escalera() === []) {
            return DescuentoAplicable::ninguno();
        }

        $mejor = Descuento::query()
            ->asignadosA($item)
            ->queAplicanA($rango)
            ->vigentesEn($fechaServicio)
            ->orderByDesc('porcentaje')
            ->first();

        return $mejor instanceof Descuento
            ? DescuentoAplicable::desdeElCatalogo($mejor, $rango)
            : DescuentoAplicable::ninguno();
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
     *
     * 🔴 Tiene que mirar TAMBIÉN los descuentos marcados en el ítem. Un
     * «Cuarta edad 40 %» marcado a mano y un precio calculado con el
     * 25 % de la ley es el hospital regalando quince puntos de margen en
     * cada paciente de 80 años — que es exactamente el agujero que la
     * división existe para tapar.
     *
     * ⚠️ Los descuentos manuales NO cuentan acá. No se disparan solos, y
     * subir el precio de todo el catálogo para cubrir un descuento que
     * solo reciben los empleados sería cobrarle a los pacientes la
     * política de personal del hospital. Cuando la caja aplica uno
     * manual, sale del margen a sabiendas.
     */
    public function maximoParaItem(Item $item, CarbonInterface $fechaServicio): DescuentoAplicable
    {
        $mejor = $this->maximoPara($item->categoria_legal_descuento, $fechaServicio);

        $delCatalogo = Descuento::query()
            ->asignadosA($item)
            ->automaticos()
            ->vigentesEn($fechaServicio)
            ->orderByDesc('porcentaje')
            ->first();

        if (! $delCatalogo instanceof Descuento) {
            return $mejor;
        }

        $rango = $delCatalogo->aplica_a->rango();

        if (! $rango instanceof RangoEdad) {
            return $mejor;
        }

        return $mejor->oElMejorDe(DescuentoAplicable::desdeElCatalogo($delCatalogo, $rango));
    }

    /**
     * El peor caso mirando SOLO el Artículo 30. Se conserva porque hay
     * pantallas que preguntan por la categoría sin tener un ítem
     * delante; para calcular un precio se usa `maximoParaItem()`.
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
