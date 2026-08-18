<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué tan confiable es la fecha de nacimiento que está guardada.
 *
 * ⚠️ Este enum existe por un caso concreto y frecuente:
 *
 *   Entra un NN inconsciente. Admisión estima "como 40 años" y alguien
 *   guarda 1-ene-1986 para poder abrir el expediente. Si el sistema no
 *   marca que ese dato es una ESTIMACIÓN, dos cosas pasan solas:
 *
 *     · Farmacia calcula una dosis pediátrica o geriátrica sobre una edad
 *       inventada.
 *     · Facturación decide el rango de edad (§4.3) sobre una adivinanza.
 *
 * Guardar NULL tampoco sirve: sin ninguna fecha no se puede ni ordenar por
 * edad ni priorizar. Lo correcto es guardar la mejor estimación disponible
 * Y decir que es estimación.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ HACE CADA MÓDULO CON UNA FECHA NO EXACTA
 * ─────────────────────────────────────────────────────────────────────
 *
 *  · CLÍNICO (dosis, protocolos, rangos de laboratorio): exige `Exacta`.
 *    Ante la duda no calcula: pide que un médico documente la edad.
 *
 *  · FACTURACIÓN (descuento de tercera y cuarta edad): SÍ aplica el
 *    beneficio sobre una estimación, y lo deja marcado para revisión.
 *    El razonamiento es asimétrico a propósito — negarle a un adulto
 *    mayor un descuento que la ley le obliga al hospital es una
 *    infracción sancionable; concedérselo de más es un costo menor y
 *    reversible.
 */
enum PrecisionFechaNacimiento: string
{
    case Exacta = 'exacta';
    case MesYAnio = 'mes_y_anio';
    case SoloAnio = 'solo_anio';
    case Estimada = 'estimada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Exacta   => 'Fecha exacta (documento a la vista)',
            self::MesYAnio => 'Mes y año conocidos',
            self::SoloAnio => 'Solo el año',
            self::Estimada => 'Estimada a criterio del personal',
        };
    }

    /**
     * ¿Se puede usar para una decisión clínica?
     */
    public function sirveParaCalculoClinico(): bool
    {
        return $this === self::Exacta;
    }

    /**
     * ¿Hay que dejar constancia de que la edad no está confirmada?
     */
    public function requiereRevision(): bool
    {
        return $this !== self::Exacta;
    }

    public function color(): string
    {
        return match ($this) {
            self::Exacta   => 'success',
            self::MesYAnio => 'info',
            self::SoloAnio => 'warning',
            self::Estimada => 'danger',
        };
    }
}
