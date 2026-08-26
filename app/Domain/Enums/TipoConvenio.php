<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué clase de pagador es este convenio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES UNA ETIQUETA DECORATIVA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El tipo decide una cosa concreta: **si el que paga es el paciente o un
 * tercero**. Y de ahí cuelga la pregunta que el Art. 30 del Decreto
 * 199-2006 no resuelve —sobre qué monto se calcula el descuento del
 * adulto mayor cuando la factura la paga un seguro— que es justamente
 * por lo que cada convenio tiene que declararlo a mano.
 *
 * También decide si tiene sentido hablar de crédito: al contado no se
 * fían treinta días, y la base lo impide con un CHECK.
 */
enum TipoConvenio: string
{
    /** El paciente paga de su bolsillo. Siempre existe, y es el único que no se negocia. */
    case Contado = 'contado';

    /** Seguros médicos privados con tarifario negociado. */
    case AseguradoraPrivada = 'aseguradora_privada';

    /** IHSS y cualquier otro régimen público de seguridad social. */
    case SeguridadSocial = 'seguridad_social';

    /** Hospital Militar, empresas con contrato de salud ocupacional, ONG. */
    case Institucional = 'institucional';

    /**
     * ─────────────────────────────────────────────────────────────────
     * SEGURO EXTERNO: SE ANOTA, NO SE LE COBRA
     * ─────────────────────────────────────────────────────────────────
     *
     * La aseguradora con la que el hospital NO tiene convenio. El
     * paciente paga todo en caja al precio de lista y después reclama él
     * con la factura en la mano.
     *
     * 🔴 No es un pagador, y llamarlo así es el error caro: el sistema
     * creería que hay algo que cobrarle y quedaría una cuenta por cobrar
     * contra una aseguradora que nunca recibió una factura ni sabe que
     * existe. Está acá para que la aseguradora se dé de alta UNA vez
     * —nombre, RTN, contacto— y deje de escribirse de veinte formas
     * distintas en un campo libre, y para que salga impresa junto a la
     * póliza, que es lo que el paciente necesita para reclamar.
     */
    case Reembolso = 'reembolso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contado            => 'Contado',
            self::AseguradoraPrivada => 'Aseguradora privada',
            self::SeguridadSocial    => 'Seguridad social',
            self::Institucional      => 'Institucional',
            self::Reembolso          => 'Seguro externo · reembolso',
        };
    }

    /**
     * ¿La factura la paga alguien que no es el paciente?
     *
     * De acá sale la duda legal del descuento de adulto mayor: mientras
     * paga el paciente no hay nada que discutir, el descuento cae sobre
     * lo que él desembolsa.
     */
    public function pagaUnTercero(): bool
    {
        /*
         * El reembolso está de este lado junto al contado: el hospital le
         * cobra al paciente. Que después él le reclame a su seguro es
         * asunto suyo y no genera cuenta por cobrar acá.
         */
        return ! in_array($this, [self::Contado, self::Reembolso], true);
    }

    /**
     * ¿Tiene sentido pactarle días de crédito?
     */
    public function admiteCredito(): bool
    {
        return $this->pagaUnTercero();
    }

    public function explicacion(): string
    {
        return match ($this) {
            self::Contado => 'El paciente paga en caja. Usa el precio de lista y no lleva '
                .'tarifario propio ni días de crédito.',
            self::AseguradoraPrivada => 'Cobra contra una póliza. Suele traer tarifario '
                .'negociado, autorización previa y deducible a cargo del paciente.',
            self::SeguridadSocial => 'Régimen público. El tarifario y las reglas los fija la '
                .'institución, no el hospital.',
            self::Institucional => 'Contrato con una entidad —militar, empresa, ONG— que '
                .'responde por las atenciones de su gente.',
            self::Reembolso => 'El hospital NO tiene convenio con esta aseguradora. El paciente '
                .'paga en caja al precio de lista y reclama él con la factura. Se registra para '
                .'que salga impresa junto a la póliza.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Contado            => 'success',
            self::AseguradoraPrivada => 'info',
            self::SeguridadSocial    => 'warning',
            self::Institucional      => 'gray',
            self::Reembolso          => 'success',
        };
    }
}
