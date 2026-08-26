<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * De dónde salió el precio que se cobró.
 *
 * No es metadato: es la respuesta a «¿por qué esta línea de la factura
 * dice L 29.33?». Sin esto, contestar exige reconstruir a mano qué filas
 * existían ese día — y para entonces alguien ya editó alguna.
 */
enum OrigenDelPrecio: string
{
    /** Una fila del tarifario firmada con ese pagador para ese ítem. */
    case PrecioNegociado = 'precio_negociado';

    /**
     * No hay precio propio para este ítem, pero sí un porcentaje pactado
     * con el pagador: la lista multiplicada por ese factor.
     */
    case PorcentajePactado = 'porcentaje_pactado';

    /** La fila sin convenio: el precio que ve cualquiera. */
    case PrecioDeLista = 'precio_de_lista';

    /**
     * El número salió de un PRESUPUESTO, no de ninguna lista de precios
     * (ADR-0009).
     *
     * Es el cargo del paquete quirúrgico: la familia acordó L 40,000 por
     * la apendicectomía completa y ese monto no está —ni puede estar— en
     * el tarifario, porque cada caso cotiza distinto. La factura tiene
     * que poder explicar de dónde vino, y esto es lo que lo explica.
     */
    case Presupuestado = 'presupuestado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PrecioNegociado   => 'Precio negociado',
            self::PorcentajePactado => 'Porcentaje pactado',
            self::PrecioDeLista     => 'Precio de lista',
            self::Presupuestado     => 'Acordado en el presupuesto',
        };
    }

    /**
     * ¿El número sale de una fila de tarifario tal cual, o se calculó?
     *
     * Importa para la factura: un precio derivado hay que poder
     * recalcularlo igual dentro de dos años, así que además de la fila de
     * lista tiene que quedar guardada la condición que lo multiplicó.
     */
    public function esDerivado(): bool
    {
        return $this === self::PorcentajePactado;
    }

    public function color(): string
    {
        return match ($this) {
            self::PrecioNegociado   => 'info',
            self::PorcentajePactado => 'warning',
            self::PrecioDeLista     => 'gray',
            self::Presupuestado     => 'success',
        };
    }
}
