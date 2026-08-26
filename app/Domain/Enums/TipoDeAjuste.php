<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué clase de documento es un ajuste.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CUATRO TIPOS, UN SOLO LUGAR DONDE AUDITAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo lo que sale del inventario sin venderse —una diferencia de
 * conteo, un frasco roto, un lote vencido, una recepción mal digitada—
 * entra por la misma puerta y queda en la misma tabla. Que sean cuatro
 * tipos y no cuatro módulos es lo que permite que «¿qué se ajustó este
 * mes y quién lo autorizó?» sea **una** consulta y no un `UNION` de
 * cuatro formas distintas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL VENCIMIENTO ES SU PROPIO TIPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Podría ser una merma más. Va aparte porque una baja por vencimiento
 * tiene consecuencias que las otras no tienen: es lo que se le muestra a
 * ARSA, es lo que se compara contra el reporte de vencimientos a 30/60/90
 * días para saber si el hospital compra de más, y es lo que va a colgar
 * del acta de destrucción cuando la construyamos. Mezclarlo con «se cayó
 * la bandeja» hace que el número de plata vencida al año no se pueda
 * sacar sin filtrar por motivo.
 */
enum TipoDeAjuste: string
{
    /** Lo que el conteo físico encontró de más o de menos. */
    case DiferenciaDeConteo = 'diferencia_de_conteo';

    /** Se rompió, se derramó, se contaminó, se preparó mal. */
    case Merma = 'merma';

    /** Se venció y hay que sacarlo del estante. */
    case Vencimiento = 'vencimiento';

    /** Se digitó mal una entrada y hay que corregir la cantidad. */
    case Correccion = 'correccion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DiferenciaDeConteo => 'Diferencia de conteo',
            self::Merma              => 'Merma',
            self::Vencimiento        => 'Baja por vencimiento',
            self::Correccion         => 'Corrección',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::DiferenciaDeConteo => 'Lo genera el cierre de un conteo físico. No se crea a mano.',
            self::Merma              => 'Producto que se perdió: derrame, rotura, contaminación, cadena de frío, '
                .'dosis parcial o error de preparación.',
            self::Vencimiento => 'Lote vencido que sale del estante. Es lo que mira una inspección.',
            self::Correccion  => 'Un dato mal digitado que hay que arreglar. Siempre con explicación.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DiferenciaDeConteo => 'warning',
            self::Merma              => 'danger',
            self::Vencimiento        => 'danger',
            self::Correccion         => 'info',
        };
    }

    /**
     * ¿Este tipo lo puede crear una persona desde la pantalla de ajustes?
     *
     * La diferencia de conteo no: nace del cierre de un conteo, con su
     * evidencia detrás. Poder crearla a mano sería poder escribir «me
     * faltan 40 ampollas» sin haber contado nada, que es justo lo que el
     * conteo existe para impedir.
     */
    public function seCreaAMano(): bool
    {
        return $this !== self::DiferenciaDeConteo;
    }

    /**
     * Los motivos que admite este tipo de documento.
     *
     * @return list<MotivoDeAjuste>
     */
    public function motivos(): array
    {
        return array_values(array_filter(
            MotivoDeAjuste::cases(),
            fn (MotivoDeAjuste $motivo): bool => $motivo->tipo() === $this,
        ));
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }
}
