<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * De dónde salió lo que el hospital no tenía.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ES UN TIPO Y NO SOLO UN NOMBRE LIBRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * El nombre de quien prestó se escribe igual —hace falta para poder
 * devolverle—, pero el TIPO cambia lo que el hospital tiene que hacer
 * después, y por eso se pregunta aparte:
 *
 *   · a una farmacia o a otro hospital se le devuelve o se le paga, y
 *     mientras tanto hay una deuda que alguien tiene que ver;
 *   · a un proveedor que adelantó mercadería se le regulariza con la
 *     factura de compra, no con una devolución;
 *   · 🔴 lo que trae el médico o la familia del paciente NO ES UNA DEUDA
 *     DEL HOSPITAL. Es del paciente, y lo normal es que no se le cobre.
 *     Meterlo en la misma bolsa que los otros tres produce una lista de
 *     «lo que debemos» inflada con cosas que nadie va a devolver.
 *
 * Ese último caso es el que obliga a distinguir. Sin el tipo, la pantalla
 * de préstamos pendientes se llena de ruido y deja de mirarse.
 */
enum QuienPresta: string
{
    case Farmacia = 'farmacia';

    case HospitalOClinica = 'hospital_o_clinica';

    case MedicoOFamiliar = 'medico_o_familiar';

    case Proveedor = 'proveedor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Farmacia         => 'Otra farmacia',
            self::HospitalOClinica => 'Otro hospital o clínica',
            self::MedicoOFamiliar  => 'El médico o un familiar del paciente',
            self::Proveedor        => 'Un proveedor, adelantado',
        };
    }

    public function ayuda(): string
    {
        return match ($this) {
            self::Farmacia         => 'Un negocio de afuera. Hay que devolverle o pagarle.',
            self::HospitalOClinica => 'Normalmente con relación de ida y vuelta. Hay que devolverle o pagarle.',
            self::MedicoOFamiliar  => 'Lo trajo el paciente. NO es deuda del hospital y no aparece en lo que se debe.',
            self::Proveedor        => 'Mandó la mercadería antes de la factura. Se regulariza con la compra.',
        };
    }

    /**
     * ¿Le queda debiendo el hospital?
     *
     * Solo esto entra a la lista de préstamos pendientes. Lo que trajo la
     * familia del paciente se registra —el kardex tiene que cuadrar y la
     * trazabilidad del medicamento administrado es obligatoria— pero no
     * genera deuda.
     */
    public function generaDeuda(): bool
    {
        return $this !== self::MedicoOFamiliar;
    }

    public function color(): string
    {
        return match ($this) {
            self::MedicoOFamiliar => 'gray',
            self::Proveedor       => 'info',
            default               => 'warning',
        };
    }

    /** @return array<string, string> */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}
