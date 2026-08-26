<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Por qué se ajustó — tipificado, nunca texto libre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * «LA MERMA SIN CATEGORÍA ES EL DISFRAZ CONTABLE DEL ROBO» (§9.F13)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un campo de texto donde cada quien escribe lo que se le ocurre produce
 * un reporte anual con doscientas variantes de «se dañó» y ninguna
 * pregunta contestable. Con la lista cerrada, en cambio, salen las
 * preguntas que sí importan:
 *
 *   · ¿cuánta plata se pierde por cadena de frío rota, y en qué almacén?
 *   · ¿la rotura sube siempre en el mismo turno?
 *   · ¿cuánto de lo que se vence es de un solo proveedor?
 *
 * El texto libre sigue existiendo y es **obligatorio** —el motivo dice la
 * categoría, el texto dice el caso—, pero encima de una categoría, no en
 * lugar de ella.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES UN ENUM Y NO UNA TABLA DE CATÁLOGO, A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lista es la misma en cualquier hospital, y ponerla en base de datos
 * permitiría que alguien creara el motivo «otro» —y ahí se acabó el
 * control—. Esta es de las poquísimas cosas del sistema que NO son
 * configuración: agregar un motivo es una decisión de diseño, con su
 * migración del CHECK y su línea en el reporte.
 */
enum MotivoDeAjuste: string
{
    // ── Diferencia de conteo ──────────────────────────────────────────

    /** El estante tenía más de lo que decía el sistema. */
    case SobranteDeConteo = 'sobrante_de_conteo';

    /** El estante tenía menos. Es el motivo que se investiga. */
    case FaltanteDeConteo = 'faltante_de_conteo';

    // ── Merma ─────────────────────────────────────────────────────────

    /** Se derramó al preparar o al trasvasar. */
    case Derrame = 'derrame';

    /** Se cayó, se quebró la ampolla, se rompió el envase. */
    case Rotura = 'rotura';

    /**
     * Sobró parte de la dosis y no se puede devolver.
     *
     * Es el caso de la ampolla de 500 mg de la que se administran 250:
     * el kardex descuenta la ampolla entera y los otros 250 mg son merma
     * (§9.F2). Sin este motivo, esa pérdida se confunde con robo.
     */
    case DosisParcial = 'dosis_parcial';

    /** Se reconstituyó mal, se diluyó mal, se preparó y no se usó. */
    case ErrorDePreparacion = 'error_de_preparacion';

    /** Perdió esterilidad: vial pinchado, envase abierto, campo sucio. */
    case Contaminacion = 'contaminacion';

    /**
     * Excursión térmica.
     *
     * Una sola rotura de cadena de frío invalida el lote completo de
     * vacunas o de insulina (§9.F15). Que tenga motivo propio es lo que
     * permite después cruzar la pérdida contra el registro del
     * refrigerador y contra el proveedor que lo transportó.
     */
    case CadenaDeFrioRota = 'cadena_de_frio_rota';

    // ── Vencimiento ───────────────────────────────────────────────────

    /** Pasó la fecha de vencimiento del lote. */
    case Vencido = 'vencido';

    // ── Corrección ────────────────────────────────────────────────────

    /**
     * Se digitó mal una entrada y hay que arreglar la cantidad.
     *
     * Es el único motivo que va en las dos direcciones: se recibieron 100
     * y se cargaron 1.000, o al revés.
     */
    case ErrorDeRegistro = 'error_de_registro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SobranteDeConteo   => 'Sobrante de conteo',
            self::FaltanteDeConteo   => 'Faltante de conteo',
            self::Derrame            => 'Derrame',
            self::Rotura             => 'Rotura',
            self::DosisParcial       => 'Dosis parcial no aprovechada',
            self::ErrorDePreparacion => 'Error de preparación',
            self::Contaminacion      => 'Contaminación',
            self::CadenaDeFrioRota   => 'Cadena de frío rota',
            self::Vencido            => 'Vencido',
            self::ErrorDeRegistro    => 'Error de registro',
        };
    }

    /**
     * A qué documento pertenece este motivo.
     */
    public function tipo(): TipoDeAjuste
    {
        return match ($this) {
            self::SobranteDeConteo,
            self::FaltanteDeConteo => TipoDeAjuste::DiferenciaDeConteo,

            self::Derrame,
            self::Rotura,
            self::DosisParcial,
            self::ErrorDePreparacion,
            self::Contaminacion,
            self::CadenaDeFrioRota => TipoDeAjuste::Merma,

            self::Vencido => TipoDeAjuste::Vencimiento,

            self::ErrorDeRegistro => TipoDeAjuste::Correccion,
        };
    }

    /**
     * ¿Puede sumar existencia?
     *
     * Casi ninguno: una rotura no puede aparecer como entrada. Dejar que
     * cualquier motivo vaya en cualquier dirección es cómo un faltante se
     * asienta como sobrante y desaparece del reporte.
     */
    public function admiteEntrada(): bool
    {
        return match ($this) {
            self::SobranteDeConteo,
            self::ErrorDeRegistro => true,
            default               => false,
        };
    }

    public function admiteSalida(): bool
    {
        return $this !== self::SobranteDeConteo;
    }

    /**
     * Con qué tipo de movimiento se asienta en el kardex.
     *
     * El signo lo decide quien llama —según sea entrada o salida— y este
     * método traduce el motivo al vocabulario del kardex, que es más
     * grueso: al kardex le basta con saber que fue un ajuste, una merma o
     * una baja por vencimiento.
     */
    public function movimiento(bool $esEntrada): TipoMovimiento
    {
        return match ($this->tipo()) {
            TipoDeAjuste::Merma       => TipoMovimiento::SalidaPorMerma,
            TipoDeAjuste::Vencimiento => TipoMovimiento::SalidaPorVencimiento,
            default                   => $esEntrada
                ? TipoMovimiento::AjustePositivo
                : TipoMovimiento::AjusteNegativo,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SobranteDeConteo => 'success',
            self::ErrorDeRegistro  => 'info',
            self::Vencido          => 'danger',
            default                => 'warning',
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $motivo): string => $motivo->value, self::cases());
    }
}
