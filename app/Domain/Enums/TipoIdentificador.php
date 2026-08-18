<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipos de documento con los que una persona puede identificarse.
 *
 * ⚠️ REGLA DURA DEL §8.2: el documento NO es la identidad.
 *
 * Una persona puede llegar sin ningún documento (NN de emergencia), con
 * uno que todavía no existe (recién nacido), con dos que se contradicen, o
 * con el de otra persona. Por eso los identificadores viven en su propia
 * tabla, en relación 1..N con `personas`, y NUNCA como una columna
 * `dni UNIQUE NOT NULL` en la tabla de personas: esa columna es la que
 * obliga a admisión a inventarse un número a las 3 de la mañana para poder
 * registrar a un politraumatizado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ `CarnetJubilado` ES UN TIPO Y NO UN CAMPO BOOLEANO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La ley hondureña que obliga los descuentos protege a "adultos mayores
 * **y jubilados**". La edad la deduce el sistema de la fecha de
 * nacimiento; la condición de jubilado, NO — un jubilado por invalidez
 * puede tener 48 años y tener derecho igual. La única forma de saberlo es
 * que presente el carné del instituto de previsión (INJUPEMP, IMPREMA,
 * IPM, IHSS). Por eso es un documento acreditable y no una casilla que
 * alguien marca a ojo.
 */
enum TipoIdentificador: string
{
    case Dni = 'dni';
    case Rtn = 'rtn';
    case Pasaporte = 'pasaporte';
    case CarnetResidencia = 'carnet_residencia';
    case CertificadoNacimiento = 'certificado_nacimiento';
    case CarnetIhss = 'carnet_ihss';
    case CarnetJubilado = 'carnet_jubilado';
    case PolizaSeguro = 'poliza_seguro';
    case ExpedienteExterno = 'expediente_externo';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Dni                   => 'DNI',
            self::Rtn                   => 'RTN',
            self::Pasaporte             => 'Pasaporte',
            self::CarnetResidencia      => 'Carné de residencia',
            self::CertificadoNacimiento => 'Certificado de nacimiento',
            self::CarnetIhss            => 'Carné del IHSS',
            self::CarnetJubilado        => 'Carné de jubilado o pensionado',
            self::PolizaSeguro          => 'Póliza de seguro',
            self::ExpedienteExterno     => 'Expediente de otro establecimiento',
            self::Otro                  => 'Otro documento',
        };
    }

    /**
     * Longitud exacta en dígitos, cuando el documento la tiene fija.
     *
     * El DNI hondureño son 13 dígitos y el RTN 14. Validar la longitud
     * atrapa el error de digitación más común (uno de menos), pero NO
     * valida que el número exista: eso solo lo sabe el RNP.
     */
    public function longitudExacta(): ?int
    {
        return match ($this) {
            self::Dni => 13,
            self::Rtn => 14,
            default   => null,
        };
    }

    /**
     * ¿El valor se guarda como solo dígitos?
     *
     * El DNI se escribe con guiones (0801-1990-12345) y sin ellos según
     * quién lo digite. Guardar las dos formas produce dos personas. Se
     * normaliza a dígitos y se formatea al mostrar.
     */
    public function soloDigitos(): bool
    {
        return match ($this) {
            self::Dni, self::Rtn => true,
            default              => false,
        };
    }

    /**
     * ¿El número solo es único dentro del país que lo emitió?
     *
     * Dos pasaportes distintos pueden compartir número si los emitieron
     * países distintos. Sin el país en la llave, el segundo turista choca
     * contra el índice único del primero.
     */
    public function requierePaisEmision(): bool
    {
        return match ($this) {
            self::Pasaporte, self::CarnetResidencia => true,
            default                                 => false,
        };
    }

    /**
     * ¿Sirve para identificar legalmente a la persona en una factura?
     *
     * El carné del IHSS y la póliza acreditan cobertura, no identidad: son
     * llaves de un tercero pagador. Confundirlos es cómo se emite una
     * factura a nombre de la aseguradora cuando el obligado es el paciente.
     */
    public function identificaLegalmente(): bool
    {
        return match ($this) {
            self::Dni, self::Rtn, self::Pasaporte, self::CarnetResidencia => true,
            default                                                       => false,
        };
    }

    /**
     * ¿Acredita por sí mismo el beneficio de adulto mayor o jubilado,
     * aunque la edad no llegue al umbral?
     */
    public function acreditaBeneficioLegal(): bool
    {
        return $this === self::CarnetJubilado;
    }

    /**
     * Deja el valor en su forma canónica antes de guardarlo o de buscarlo.
     *
     * Se usa en los DOS lados: al escribir y al consultar. Si la búsqueda
     * no normaliza igual que la escritura, el sistema tiene el DNI pero no
     * lo encuentra, y admisión crea el duplicado.
     */
    public function normalizar(string $valor): string
    {
        if ($this->soloDigitos()) {
            return preg_replace('/\D+/', '', $valor) ?? '';
        }

        return mb_strtoupper(
            preg_replace('/\s+/u', '', trim($valor)) ?? '',
            'UTF-8'
        );
    }

    /**
     * Formato de presentación: 0801-1990-12345.
     *
     * Solo para mostrar. Lo que se guarda y se compara es `normalizar()`.
     */
    public function formatear(string $valorNormalizado): string
    {
        if ($this === self::Dni && mb_strlen($valorNormalizado) === 13) {
            return mb_substr($valorNormalizado, 0, 4)
                .'-'.mb_substr($valorNormalizado, 4, 4)
                .'-'.mb_substr($valorNormalizado, 8);
        }

        return $valorNormalizado;
    }
}
