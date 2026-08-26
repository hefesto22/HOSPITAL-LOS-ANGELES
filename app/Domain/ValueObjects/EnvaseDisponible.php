<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Lo que hay de un producto en un lugar, leído como FRASCOS y no como
 * mililitros.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL FRASCO ABIERTO NO SE GUARDA: SE DEDUCE
 * ─────────────────────────────────────────────────────────────────────
 *
 * El kardex lleva mililitros. Si hay 545 ml en frascos de 60, eso son
 * nueve frascos sellados y uno destapado con 5 — y esos 5 son
 * exactamente el resto de la división. No hace falta una columna que
 * diga cuál está abierto.
 *
 * Eso vale porque la regla de despacho garantiza que **nunca hay dos
 * frascos abiertos del mismo lote**: siempre se agota el destapado antes
 * de destapar otro. El día que esa regla se rompa, este cálculo empieza a
 * mentir — está anotado a propósito.
 *
 * `tamano` en nulo es lo que llegó a granel, sin envase declarado: todo
 * el saldo se considera destapado, porque no hay frasco que romper.
 */
final readonly class EnvaseDisponible
{
    public function __construct(
        public int $clave,
        public Decimal $saldo,
        public ?Decimal $tamano = null,
        public ?string $vence = null,
    ) {}

    /**
     * Cuántos frascos cerrados hay. Se TRUNCA, nunca se redondea: 545 ml
     * en frascos de 60 son nueve sellados, no diez.
     */
    public function sellados(): int
    {
        if (! $this->tieneEnvase()) {
            return 0;
        }

        /** @var Decimal $tamano */
        $tamano = $this->tamano;

        return (int) explode('.', $this->saldo->entre($tamano)->exacto())[0];
    }

    /**
     * Lo que hay en el frasco destapado. Cero si están todos cerrados.
     */
    public function abierto(): Decimal
    {
        $sellados = $this->sellados();

        if ($sellados === 0) {
            return $this->saldo;
        }

        /** @var Decimal $tamano */
        $tamano = $this->tamano;

        return $this->saldo->restar($tamano->por($sellados));
    }

    public function tieneEnvase(): bool
    {
        return $this->tamano instanceof Decimal
            && ! $this->tamano->esCero()
            && ! $this->tamano->esNegativo();
    }
}
