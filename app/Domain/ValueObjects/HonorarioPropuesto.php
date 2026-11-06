<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * El precio con el que un honorario se ofrece al cargarlo en la cuenta, y
 * si quien cobra lo puede cambiar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS CASOS QUE NO SON EL MISMO (Mauricio, 6-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * - FIJO: la cuenta es de un seguro y el honorario tiene precio pactado
 *   con él. «Lo que cobran al Militar y a PALIG ya está fijado»: no se
 *   toca en el mostrador.
 * - LIBRE: el particular, o un seguro que no pactó ese honorario. Se
 *   propone un número si lo hay y quien cobra escribe el que corresponde.
 *
 * `precio` nulo solo existe en el libre, y significa «hay que escribirlo»:
 * nunca un cero, que regalaría el honorario.
 */
final readonly class HonorarioPropuesto
{
    private function __construct(
        public ?Monto $precio,
        public bool $fijo,
        public bool $delMedico,
        public string $explicacion,
    ) {}

    /**
     * Lo pactado con el seguro. `delMedico` cuando sale de la lista de ese
     * médico PARA ese seguro, que el tarifario no conoce y por eso tiene
     * que viajar en el cargo.
     */
    public static function pactado(Monto $precio, bool $delMedico, string $explicacion): self
    {
        return new self($precio, true, $delMedico, $explicacion);
    }

    public static function propuesto(Monto $precio, bool $delMedico, string $explicacion): self
    {
        return new self($precio, false, $delMedico, $explicacion);
    }

    public static function sinPrecio(string $explicacion): self
    {
        return new self(null, false, false, $explicacion);
    }

    /**
     * Lo que va en el campo: «850.00», o nulo para que quede vacío.
     */
    public function valor(): ?string
    {
        return $this->precio?->valor();
    }
}
