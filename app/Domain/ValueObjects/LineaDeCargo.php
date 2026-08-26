<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\PoliticaCargo;
use App\Domain\Exceptions\CargoException;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use Carbon\CarbonInterface;

/**
 * Lo que alguien quiere agregarle a la cuenta.
 *
 * Cantidad SIEMPRE positiva: la reversa no se pide con un número
 * negativo, se pide anulando el cargo original. Un motor que aceptara
 * cantidades negativas convertiría cada error de dedo en una nota de
 * crédito silenciosa.
 *
 * La clave de idempotencia no tiene default a propósito. Quien llama
 * tiene que decidir qué es «el mismo hecho»: para la pantalla es el
 * token del formulario, para una interfaz HL7 es el control ID del
 * mensaje (§13.7). Un default generado acá adentro haría que dos clics
 * fueran dos hechos distintos, que es justo lo que hay que impedir.
 */
final readonly class LineaDeCargo
{
    public function __construct(
        public Item $item,
        public Decimal $cantidad,
        public string $claveIdempotencia,
        public ?Almacen $almacen = null,
        public ?Lote $lote = null,
        public ?Monto $descuentoComercial = null,

        /**
         * El descuento del hospital expresado en FRACCIÓN: 0.30 = 30 %.
         *
         * ─────────────────────────────────────────────────────────────
         * POR QUÉ PORCENTAJE Y NO SOLO MONTO
         * ─────────────────────────────────────────────────────────────
         *
         * Quien atiende piensa en «hacele un 30 %», no en «quitale L
         * 11.00». Y la pantalla no puede hacer esa cuenta: NO resuelve el
         * precio —lo hace `CalculadoraDeCargo` por dentro—, así que un
         * porcentaje convertido en la pantalla obligaría a duplicar la
         * resolución de precios ahí, con el riesgo de que las dos
         * versiones se separen.
         *
         * El porcentaje viaja hasta donde el bruto existe y se aplica
         * ahí. El monto sigue aceptándose para el llamador programático
         * que ya sabe el importe exacto.
         */
        public ?Decimal $descuentoComercialPorcentaje = null,
        public ?string $motivoDescuento = null,
        public ?int $autorizadoPor = null,
        public ?int $servicioId = null,
        public ?CarbonInterface $ocurridoEn = null,

        /*
         * ─────────────────────────────────────────────────────────────
         * EL PAQUETE PRESUPUESTADO (ADR-0009)
         * ─────────────────────────────────────────────────────────────
         *
         * `precioAcordado` es el ÚNICO camino por el que un cargo lleva
         * un precio que no salió del tarifario: el monto que la familia
         * acordó por la cirugía completa. Sin esto habría que inventarle
         * una fila de tarifario a cada caso.
         *
         * `presupuestoId` sin `presupuestoLineaId` = es el cargo del
         * paquete. Con los dos = un consumo que ya estaba previsto, y
         * entonces `politica` viene en `IncluidoEnTarifa`: sale de
         * bodega, congela costo, y NO se le vuelve a cobrar al paciente.
         */
        public ?Monto $precioAcordado = null,
        public ?string $referenciaAcordada = null,
        public ?int $presupuestoId = null,
        public ?int $presupuestoLineaId = null,
        public ?PoliticaCargo $politica = null,

        /*
         * Lo que va a decir el renglón de la cuenta, si no es el nombre
         * del ítem. Existe para el paquete: la familia tiene que leer
         * «APENDICECTOMIA», no el nombre del ítem técnico que hace
         * posible el cargo.
         */
        public ?string $textoDelCargo = null,
    ) {
        /*
         * Se valida contra la cantidad REDONDEADA a los cuatro decimales
         * de la columna, no contra la escala interna del `Decimal`.
         *
         * Un `0.00005` que llegue de un llamador programático —el bloque
         * 6, una interfaz HL7— pasaría un `> 0` a escala 12, se guardaría
         * como `0.0000` y moriría en el CHECK `cargos_cantidad_no_cero`
         * con un error crudo de PostgreSQL, después de haber creado ya el
         * movimiento de kardex.
         */
        $enLaColumna = Decimal::de($cantidad->redondeado(4));

        if ($enLaColumna->esCero() || $enLaColumna->esNegativo()) {
            throw CargoException::cantidadInvalida($item->nombre);
        }

        if (trim($claveIdempotencia) === '') {
            throw CargoException::sinClaveDeIdempotencia();
        }

        /*
         * Monto y porcentaje juntos no es «más preciso»: es una
         * ambigüedad sobre cuánto se descuenta, y el que la resuelva
         * eligiendo uno de los dos va a elegir mal alguna vez.
         */
        if ($descuentoComercial instanceof Monto && $descuentoComercialPorcentaje instanceof Decimal) {
            throw CargoException::descuentoEnDosFormas($item->nombre);
        }

        if ($descuentoComercialPorcentaje instanceof Decimal
            && ($descuentoComercialPorcentaje->esNegativo()
                || $descuentoComercialPorcentaje->mayorQue('1'))) {
            throw CargoException::porcentajeDeDescuentoInvalido($item->nombre);
        }

        /*
         * 🔴 Un descuento sin motivo es una fuga sin nombre. Dentro de
         * seis meses, la pregunta «¿por qué esta línea salió más barata?»
         * tiene que tener respuesta escrita, no una conjetura sobre quién
         * atendía ese día (§8.6.2-4).
         */
        $hayDescuento = ($descuentoComercial instanceof Monto && ! $descuentoComercial->esCero())
            || ($descuentoComercialPorcentaje instanceof Decimal && ! $descuentoComercialPorcentaje->esCero());

        /*
         * §8.6.2-4: el descuento libre en el mostrador es la fuga de caja
         * número uno de todo hospital privado. Diez caracteres mínimo —
         * «ok» y «autorizado» no explican nada dentro de seis meses.
         *
         * La regla vale igual para el monto y para el porcentaje: son dos
         * formas de escribir lo mismo, y una de las dos no puede tener
         * menos control que la otra.
         */
        if ($hayDescuento
            && ($motivoDescuento === null || mb_strlen(trim($motivoDescuento)) < 10)) {
            throw CargoException::descuentoSinMotivo();
        }
    }

    /**
     * ¿Este cargo tiene que mover el kardex?
     *
     * Lo decide el ítem —un servicio no tiene existencia que descontar—
     * y la presencia de almacén. Que la política de cargo sea «incluido
     * en la tarifa» o «gasto del servicio» NO lo exime: esos también
     * salen de bodega, solo que no le llegan al paciente como línea
     * (§8.4, `PoliticaCargo::descuentaExistencia`).
     */
    public function mueveInventario(): bool
    {
        return $this->almacen instanceof Almacen && $this->item->mueveInventario();
    }

    public function descuentoComercialODefecto(): Monto
    {
        return $this->descuentoComercial ?? Monto::cero();
    }
}
