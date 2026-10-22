<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Por qué se anula una cuenta (Mauricio, 21-oct-2026).
 *
 * Anular es para la cuenta que NO DEBIÓ EXISTIR: se abrió por error, al
 * paciente equivocado, con el pagador o el tipo de atención equivocados,
 * o el paciente se fue sin que se le hiciera nada. Todo lo cargado se
 * quita y lo que salió de farmacia vuelve al estante.
 *
 * ⚠️ NO es para el paciente que SÍ se atendió y no va a pagar —se fue,
 * cortesía, defunción—. Ahí lo que se le dio ya no está en el estante, y
 * devolverlo al kardex sería inventar existencia (§9.H12: la fuga es un
 * evento registrado con su saldo, no una cuenta que desaparece).
 *
 * Es enum y no texto libre para poder contarlas: una cajera que anula
 * tres cuentas a la semana «por error», todas con medicamentos, es un
 * hallazgo que solo se ve agrupando.
 */
enum MotivoDeAnulacionDeCuenta: string
{
    case AbiertaPorError = 'abierta_por_error';
    case PacienteEquivocado = 'paciente_equivocado';
    case PagadorOTipoEquivocado = 'pagador_o_tipo';
    case NoSeAtendio = 'no_se_atendio';
    case Otro = 'otro';

    /**
     * Cómo queda escrito en el motivo de la cuenta y de cada cargo.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::AbiertaPorError        => 'SE ABRIÓ POR ERROR',
            self::PacienteEquivocado     => 'SE ABRIÓ AL PACIENTE EQUIVOCADO',
            self::PagadorOTipoEquivocado => 'PAGADOR O TIPO DE ATENCIÓN EQUIVOCADO',
            self::NoSeAtendio            => 'EL PACIENTE SE RETIRÓ SIN SER ATENDIDO',
            self::Otro                   => 'OTRO MOTIVO',
        };
    }

    /**
     * Lo que ve quien elige, en minúsculas y con lo que hay que hacer
     * después.
     */
    public function opcion(): string
    {
        return match ($this) {
            self::AbiertaPorError        => 'Se abrió por error',
            self::PacienteEquivocado     => 'Era otro paciente — abrile la cuenta al correcto',
            self::PagadorOTipoEquivocado => 'Pagador o tipo de atención equivocado — volvé a abrirla bien',
            self::NoSeAtendio            => 'El paciente se fue sin que se le hiciera nada',
            self::Otro                   => 'Otro motivo (escribilo abajo)',
        };
    }

    /**
     * «Otro» no dice nada por sí solo: sin el detalle, la bitácora queda
     * con un motivo que no explica la anulación.
     */
    public function pideDetalle(): bool
    {
        return $this === self::Otro;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $motivo): string => $motivo->value, self::cases());
    }
}
