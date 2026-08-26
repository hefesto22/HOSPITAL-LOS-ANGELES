<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Cuánto sale de una existencia concreta, y si eso destapó un frasco.
 *
 * `destapa` no cambia el kardex —los mililitros son los mismos— pero es
 * lo que permite avisar «quedó un frasco de 120 abierto con 55 ml» y, más
 * adelante, contarle los días de vida útil desde que se abrió.
 */
final readonly class TomaDeEnvase
{
    public function __construct(
        public int $clave,
        public Decimal $cantidad,
        public bool $destapa = false,
    ) {}
}
