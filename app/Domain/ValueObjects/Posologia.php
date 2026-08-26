<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\PosologiaException;

/**
 * La receta como la dice el médico, convertida en cuánto hay que entregar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * «15 ML CADA 6 HORAS POR 2 DÍAS» SON 120 ML
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hoy alguien hace esa multiplicación de cabeza en el mostrador y teclea
 * el resultado. Cuatro por dos por quince, a las tres de la mañana, con
 * un paciente enfrente. Se hace mal alguna vez, y cuando se hace mal no
 * se nota: el número que queda escrito es plausible.
 *
 * Acá se teclea la receta y el sistema hace la cuenta. Y además la
 * GUARDA, que es la mitad del valor: dentro de seis meses, «2 frascos»
 * sin la receta al lado no explica nada, y con ella se lee sola.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ LAS TOMAS SE REDONDEAN HACIA ARRIBA
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cada 5 horas por 2 días» da 48 ÷ 5 = 9,6 tomas. No existe media toma:
 * son 10. Se redondea hacia arriba a propósito y no hacia el más
 * cercano, porque los dos errores no cuestan lo mismo — que sobre una
 * dosis es un gasto; que falte es un paciente sin su medicamento a la
 * hora que le tocaba.
 *
 * En los horarios reales —cada 4, 6, 8, 12 o 24 horas— la división es
 * exacta y esto no se nota nunca. Existe para cuando no lo es.
 */
final readonly class Posologia
{
    private const HORAS_DEL_DIA = 24;

    /**
     * @throws PosologiaException
     */
    public function __construct(
        public Decimal $dosis,
        public int $cadaCuantasHoras,
        public int $dias,
    ) {
        if (! $dosis->mayorQue('0')) {
            throw PosologiaException::dosisEnCero();
        }

        if ($cadaCuantasHoras < 1) {
            throw PosologiaException::frecuenciaInvalida();
        }

        if ($dias < 1) {
            throw PosologiaException::duracionInvalida();
        }
    }

    /**
     * Lo que se entrega una sola vez: una ampolla ahora, una dosis de
     * carga. Se modela como un día con una toma para que el resto del
     * sistema no tenga que preguntarse si hay posología o no.
     */
    public static function unaSolaVez(Decimal $dosis): self
    {
        return new self($dosis, self::HORAS_DEL_DIA, 1);
    }

    public function tomas(): int
    {
        return (int) ceil(($this->dias * self::HORAS_DEL_DIA) / $this->cadaCuantasHoras);
    }

    /**
     * Cuánto hay que tener disponible en total. NO es lo que se factura:
     * lo que se factura son los envases que cubren esto, que es otra
     * cuenta y la hace `RepartidorDeEnvases`.
     */
    public function total(): Decimal
    {
        return $this->dosis->por($this->tomas());
    }

    /**
     * «15 ML c/6h × 2 días». Va en la línea de la cuenta al lado de los
     * frascos: sin la receta, «2 frascos» no explica de dónde salió el
     * número, y esa es justo la pregunta que hace el paciente.
     */
    public function comoSeLee(string $unidad = ''): string
    {
        $dosis = rtrim(rtrim($this->dosis->redondeado(4), '0'), '.');
        $cada = $this->cadaCuantasHoras === self::HORAS_DEL_DIA && $this->dias === 1
            ? 'dosis única'
            : 'c/'.$this->cadaCuantasHoras.'h × '.$this->dias.' '.($this->dias === 1 ? 'día' : 'días');

        return trim($dosis.' '.$unidad).' '.$cada;
    }
}
