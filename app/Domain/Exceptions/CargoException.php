<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class CargoException extends SihlaException
{
    public static function cantidadInvalida(string $item): self
    {
        return new self(
            "La cantidad de «{$item}» tiene que ser mayor que cero. "
            .'Para quitar algo que se cargó de más, anulá el cargo: así queda el rastro de los dos hechos.'
        );
    }

    public static function sinClaveDeIdempotencia(): self
    {
        return new self(
            'Falta la clave que distingue «lo volví a hacer» de «apreté dos veces». '
            .'Es un defecto del código que llama, no del usuario.'
        );
    }

    /**
     * ⚠️ El mensaje NO pide autorización de nadie, y eso es a propósito.
     *
     * Decía «y quién lo autoriza», y quien lo leía entendía que había que
     * ir a buscar a un médico. No hay tal cosa: el descuento lo da quien
     * cobra. Lo único obligatorio es la razón, y el sistema guarda solo
     * quién la escribió. Un mensaje que manda a pedir un permiso que no
     * existe frena la atención y termina en que nadie da el descuento.
     */
    public static function descuentoSinMotivo(): self
    {
        return new self(
            'Escribí por qué se da el descuento, en al menos diez caracteres. '
            .'Lo puede dar cualquiera que cobre —no hace falta buscar al médico—, pero la razón queda '
            .'guardada con tu nombre, y es la única respuesta cuando alguien pregunte dentro de seis meses.'
        );
    }

    public static function descuentoEnDosFormas(string $item): self
    {
        return new self(
            "El descuento sobre «{$item}» viene como monto y como porcentaje a la vez. "
            .'Es uno u otro: con los dos, cuál manda lo decidiría el orden del código.'
        );
    }

    public static function porcentajeDeDescuentoInvalido(string $item): self
    {
        return new self(
            "El porcentaje de descuento sobre «{$item}» tiene que estar entre 0 y 100 %."
        );
    }

    /**
     * 🔴 El tope que mantiene el esquema del lado legal.
     *
     * El descuento total de una línea —el de ley más el del hospital— no
     * puede pasar del descuento de ley MÁXIMO de esa categoría. Dos
     * cosas dependen de eso, y las dos importan:
     *
     *   · **Legal.** Si un paciente sin derecho recibiera más que el
     *     adulto mayor de cuarta edad, el beneficio del Artículo 30 se
     *     invertiría: el que la ley protege pagaría más que el resto.
     *   · **Económica.** El precio de lista se calculó dividiendo por
     *     ese mismo máximo, así que cualquier descuento por debajo del
     *     tope respeta el piso de margen, y cualquiera por encima lo
     *     rompe. Es la misma cuenta vista al revés.
     */
    public static function descuentoSuperaElTopeDeLey(
        string $item,
        string $total,
        string $tope,
    ): self {
        return new self(
            "El descuento sobre «{$item}» llega a {$total} y el máximo es {$tope}. "
            .'Ese tope es el descuento de ley más alto de esta categoría: pasarlo dejaría al '
            .'paciente sin derecho pagando menos que el adulto mayor, y rompería el piso de margen.'
        );
    }

    /**
     * El tope de adentro: el que puso la dirección, no la ley.
     *
     * Se verifica ANTES que el techo legal porque es el más estricto de
     * los dos y el que el mostrador puede entender: «para este paciente
     * el máximo es 10 %» es accionable, «el descuento total no puede
     * pasar del máximo de la categoría» no lo es.
     */
    public static function descuentoComercialSuperaLaPolitica(
        string $item,
        string $aplicado,
        string $tope,
        string $aQuien,
    ): self {
        return new self(
            "El descuento del hospital sobre «{$item}» es de {$aplicado}, y para {$aQuien} el máximo es {$tope}. "
            .'Ese límite lo puso la dirección, no la ley: cambiarlo se decide arriba, no en el mostrador.'
        );
    }

    public static function sinMargenParaDescuentoComercial(string $item, string $aQuien): self
    {
        return new self(
            "Sobre «{$item}» no se le agrega descuento del hospital a {$aQuien}: ya lleva el descuento de "
            .'ley más alto de la categoría, que es el mismo techo con el que se calculó el precio de lista. '
            .'Un punto más no saldría del precio, saldría del margen.'
        );
    }

    public static function descuentoMayorQueElCargo(string $item): self
    {
        return new self(
            "El descuento sobre «{$item}» es mayor que el cargo. "
            .'Revisá el monto: un descuento que deja el total en negativo sería el hospital pagándole al paciente.'
        );
    }

    public static function sinPrecio(string $item, string $convenio, string $fecha): self
    {
        return new self(
            "«{$item}» no tiene precio definido para {$convenio} al {$fecha}. "
            .'Cargalo en el tarifario antes de cobrarlo — o si es una cortesía, dale precio cero y dejalo escrito.'
        );
    }

    /**
     * @param numeric-string $pedido
     * @param numeric-string $hay
     */
    public static function sinExistenciaSuficiente(
        string $item,
        string $almacen,
        string $pedido,
        string $hay,
    ): self {
        return new self(
            "No hay suficiente «{$item}» en {$almacen}: se piden {$pedido} y hay {$hay}. "
            .'No se cargó nada. Buscalo en otro almacén, pedí un traslado, o si de verdad se '
            .'consumió lo que no estaba registrado, hay que ajustar el inventario primero — con motivo.'
        );
    }

    public static function necesitaAlmacen(string $item): self
    {
        return new self(
            "«{$item}» descuenta existencia, así que hay que decir de qué almacén sale. "
            .'Un consumo sin almacén es inventario que desaparece sin responsable.'
        );
    }

    public static function yaNoSeAnula(string $estado): self
    {
        return new self(
            "Este cargo está {$estado} y ya no se anula directo. "
            .'Un cargo facturado se corrige con nota de crédito, que consume su propio CAI (Acuerdo 481-2017).'
        );
    }

    public static function motivoDeAnulacionCorto(): self
    {
        return new self(
            'El motivo de la anulación tiene que explicar qué pasó, en al menos diez caracteres. '
            .'«Error» no le sirve a nadie dentro de seis meses.'
        );
    }

    public static function itemNoVigente(string $item, string $fecha): self
    {
        return new self(
            "«{$item}» no estaba vigente al {$fecha}. "
            .'Si el servicio se prestó igual, reabrí su vigencia: el catálogo tiene historia, no un interruptor.'
        );
    }
}
