<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que el presupuesto no deja hacer.
 *
 * Cada negativa explica qué SÍ se puede hacer: quien la lee está con la
 * familia enfrente, armando el número, y un «no se puede» sin salida lo
 * deja parado.
 */
final class PresupuestoException extends SihlaException
{
    /**
     * Medicamentos y materiales salen con el precio de lista (Mauricio,
     * 12-oct-2026): su precio lo deriva el costo, y lo que se negocia con
     * la familia son los servicios, los honorarios y la holgura.
     */
    public static function precioDeLista(string $texto): self
    {
        return new self(
            "«{$texto}» es medicamento o material: va con el precio de lista y no se cambia en el "
            .'presupuesto. La cantidad sí se puede cambiar; para llegar al monto que se le cobra, '
            .'usá la holgura.'
        );
    }

    public static function elComplementoSeCalculaSolo(): self
    {
        return new self(
            'Esta holgura completa el monto que se le cobra y se calcula sola: baja cuando se agregan '
            .'renglones y sube cuando se quitan. Para cambiarla, cambiá el monto que se le cobra.'
        );
    }

    public static function yaNoSeToca(string $estado): self
    {
        return new self(
            "El presupuesto está «{$estado}» y ya no se toca. Para cambiarlo, usá «Revisar»: crea uno "
            .'nuevo y deja este como sustituido.'
        );
    }
}
