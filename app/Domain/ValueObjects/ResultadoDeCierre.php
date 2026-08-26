<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\Ajuste;
use App\Models\Conteo;

/**
 * Qué pasó al cerrar un conteo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA CON DEVOLVER EL CONTEO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cerrar tiene tres desenlaces posibles y los tres hay que poder
 * contarlos:
 *
 *   · **todo cuadró** — no se genera ajuste, y esa es la buena noticia
 *     que el sistema tiene que saber decir;
 *   · **hubo diferencias** — se asentaron, y hay un ajuste con su valor;
 *   · **había controlados descuadrados** — y esos NO se asentaron.
 *
 * El tercero es el que obliga a este objeto. Un `Conteo` devuelto a
 * secas no tiene dónde llevar la lista de los controlados que quedaron
 * pendientes de conciliar, y esa lista es lo más importante de todo el
 * cierre: es un hallazgo que alguien tiene que resolver en el libro,
 * a mano y con dos firmas, hoy mismo.
 */
final readonly class ResultadoDeCierre
{
    /**
     * @param list<string> $controladosSinAsentar etiquetas de los productos controlados
     *                                            que quedaron con diferencia
     * @param list<string> $noAsentadasPorExistencia etiquetas de los productos cuya
     *                                               existencia bajó tanto desde el conteo
     *                                               que el ajuste ya no cabía
     */
    public function __construct(
        public Conteo $conteo,
        public ?Ajuste $ajuste,
        public int $lineasAsentadas,
        public array $controladosSinAsentar = [],
        public array $noAsentadasPorExistencia = [],
    ) {}

    public function todoCuadro(): bool
    {
        return $this->lineasAsentadas === 0
            && $this->controladosSinAsentar === []
            && $this->noAsentadasPorExistencia === [];
    }

    public function hayControladosPendientes(): bool
    {
        return $this->controladosSinAsentar !== [];
    }

    public function hayPendientes(): bool
    {
        return $this->hayControladosPendientes() || $this->noAsentadasPorExistencia !== [];
    }

    /**
     * Una frase para la notificación, dicha en el orden en que importa:
     * primero lo que hay que ir a resolver, después lo que ya se resolvió.
     */
    public function resumen(): string
    {
        if ($this->todoCuadro()) {
            return 'El conteo cerró sin diferencias: el estante y el sistema dicen lo mismo.';
        }

        $partes = [];

        if ($this->hayControladosPendientes()) {
            $cuantos = count($this->controladosSinAsentar);

            $partes[] = $cuantos === 1
                ? '⚠️ 1 medicamento controlado quedó con diferencia y NO se ajustó: va por el '
                    .'libro de controlados, con folio y doble firma.'
                : "⚠️ {$cuantos} medicamentos controlados quedaron con diferencia y NO se "
                    .'ajustaron: van por el libro de controlados, con folio y doble firma.';
        }

        if ($this->noAsentadasPorExistencia !== []) {
            $cuantas = count($this->noAsentadasPorExistencia);

            $partes[] = $cuantas === 1
                ? '⚠️ 1 diferencia NO se asentó: la existencia bajó desde que se contó y el '
                    .'ajuste dejaría el estante en negativo. Hay que contar ese producto otra vez.'
                : "⚠️ {$cuantas} diferencias NO se asentaron: la existencia bajó desde que se "
                    .'contaron y el ajuste dejaría el estante en negativo. Hay que contar esos '
                    .'productos otra vez.';
        }

        if ($this->lineasAsentadas > 0) {
            $partes[] = $this->lineasAsentadas === 1
                ? 'Se asentó 1 diferencia en el kardex.'
                : "Se asentaron {$this->lineasAsentadas} diferencias en el kardex.";
        }

        return implode(' ', $partes);
    }
}
