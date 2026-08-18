<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Carbon\CarbonInterface;

/**
 * Rango de edad del paciente para efectos de descuento legal.
 *
 * ⚠️ Los descuentos de tercera y cuarta edad son OBLIGACIÓN LEGAL en
 * Honduras, no política comercial. Ley Integral de Protección al Adulto
 * Mayor y Jubilados, reformada por el Decreto 45-2025 (Art. 31), vigente
 * desde el 19-ene-2026. El incumplimiento se sanciona con 1 a 10,000
 * salarios mínimos vía Protección al Consumidor.
 *
 * Dos reglas que este enum hace cumplir:
 *
 *  1. Las EDADES no están escritas acá. Salen de configuración
 *     (`sihla.edad.rangos_por_defecto`) porque la ley ya cambió una vez y
 *     va a volver a cambiar. Quemar 60 y 80 en el código obliga a
 *     desplegar para cumplir con una reforma.
 *
 *  2. El rango se resuelve contra la FECHA DEL SERVICIO, nunca contra
 *     "hoy" ni contra la fecha de facturación. Un paciente que cumple 60
 *     durante la hospitalización cambia de rango a mitad de la cuenta, y
 *     cada cargo tiene que llevar el rango vigente el día que se generó.
 *
 * El PORCENTAJE de descuento NO vive acá: depende del tipo de ítem
 * (medicamento, honorario de especialista, habitación) y tiene vigencia,
 * así que es dato en base de datos (ADR-0003).
 */
enum RangoEdad: string
{
    case Normal = 'normal';
    case Tercera = 'tercera';
    case Cuarta = 'cuarta';

    /**
     * Resuelve el rango a partir de la edad cumplida en años.
     */
    public static function paraEdad(int $anios): self
    {
        /** @var array<string, array{desde: int, hasta: int|null}> $rangos */
        $rangos = config('sihla.edad.rangos_por_defecto', []);

        foreach ([self::Cuarta, self::Tercera] as $rango) {
            $desde = $rangos[$rango->value]['desde'] ?? null;

            if (is_int($desde) && $anios >= $desde) {
                return $rango;
            }
        }

        return self::Normal;
    }

    /**
     * Resuelve el rango de un paciente EN LA FECHA DEL SERVICIO.
     *
     * `$fechaServicio` es obligatoria a propósito: no hay un valor por
     * defecto de "hoy". Dejarlo opcional es la puerta por la que entra el
     * bug de recalcular el rango al reimprimir una factura vieja.
     */
    public static function paraPaciente(
        CarbonInterface $fechaNacimiento,
        CarbonInterface $fechaServicio,
    ): self {
        return self::paraEdad((int) $fechaNacimiento->diffInYears($fechaServicio));
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Normal  => 'Edad normal',
            self::Tercera => 'Tercera edad',
            self::Cuarta  => 'Cuarta edad',
        };
    }

    /**
     * ¿Este rango tiene derecho a descuento legal?
     *
     * Responde SÍ o NO. Cuánto es, lo resuelve el tarifario contra el
     * tipo de ítem y la fecha del servicio.
     */
    public function tieneDescuentoLegal(): bool
    {
        return $this !== self::Normal;
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal  => 'gray',
            self::Tercera => 'info',
            self::Cuarta  => 'warning',
        };
    }
}
