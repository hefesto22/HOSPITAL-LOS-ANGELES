<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Una cantidad de dinero, con su moneda.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIÓ EL 18-AGO-2026, Y POR QUÉ IMPORTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La versión anterior guardaba el valor en un `float` y hacía la
 * aritmética con `round($valor * 100)`. Funcionaba en los tests porque
 * los tests usaban números redondos, y el §8.6.2 lo prohíbe sin
 * excepciones: **float y double están vetados para dinero.**
 *
 * No era teórico. El piso de margen del §4.5 se toca exactamente en
 * `29.33 × 0.75 = 21.9975`. Con float y `round()` eso puede caer en
 * 21.99, el margen queda en 119.9 % y el piso que Mauricio fijó se
 * incumple **en cada venta a un adulto mayor** — sin que ningún test
 * falle y sin que nadie lo note hasta que alguien saque la cuenta a mano.
 *
 * Ahora todo pasa por `Decimal`: bcmath sobre strings, escala interna 12,
 * y redondeo half-up una sola vez, al exponer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN MONTO NO ES NEGATIVO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El signo lo pone el MOVIMIENTO, no la cantidad. Una nota de crédito no
 * es "una factura de menos ochocientos": es un documento de otro tipo por
 * ochocientos. Una devolución no es una venta negativa.
 *
 * Modelarlo al revés —permitir montos negativos— hace que un error de
 * signo pase inadvertido por todo el sistema y aparezca recién en el
 * cierre de caja, cuando el efectivo no cuadra y nadie sabe cuál de los
 * cuarenta movimientos del turno tiene el signo cambiado.
 */
final readonly class Monto implements Stringable
{
    /**
     * Decimales con los que se expone y se guarda. §8.6.2: los MONTOS van
     * a NUMERIC(14,2); los COSTOS llevan 4 y no son esta clase.
     */
    public const DECIMALES = 2;

    private Decimal $cantidad;

    public string $moneda;

    private function __construct(Decimal $cantidad, string $moneda)
    {
        if ($cantidad->esNegativo()) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: $cantidad->redondeado(self::DECIMALES),
                razon: 'No puede ser negativo. El signo lo pone el tipo de movimiento.',
            );
        }

        if (strlen($moneda) !== 3) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'moneda',
                valor: $moneda,
                razon: 'Debe ser código ISO-4217 de 3 letras (ej: HNL, USD).',
            );
        }

        $this->cantidad = $cantidad;
        $this->moneda = mb_strtoupper($moneda);
    }

    /**
     * ⚠️ NO acepta `float`. Ver el docblock de `Decimal::de()`.
     */
    public static function de(string|int|Decimal $valor, string $moneda = 'HNL'): self
    {
        return new self(Decimal::de($valor), $moneda);
    }

    /**
     * Escotilla explícita para datos que ya vienen en float. Se busca en
     * el código y se revisa una por una.
     */
    public static function deFloat(float $valor, string $moneda = 'HNL'): self
    {
        return new self(Decimal::deFloat($valor), $moneda);
    }

    public static function cero(string $moneda = 'HNL'): self
    {
        return new self(Decimal::cero(), $moneda);
    }

    public static function deCentavos(int $centavos, string $moneda = 'HNL'): self
    {
        return new self(Decimal::de($centavos)->entre('100'), $moneda);
    }

    // ── Aritmética ────────────────────────────────────────────────────

    public function sumar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        return new self($this->cantidad->sumar($otro->cantidad), $this->moneda);
    }

    /**
     * @throws ValueObjectInvalidoException si el resultado sería negativo
     */
    public function restar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        return new self($this->cantidad->restar($otro->cantidad), $this->moneda);
    }

    public function multiplicarPor(string|int|Decimal $factor): self
    {
        return new self($this->cantidad->por($factor), $this->moneda);
    }

    /**
     * `aplicarPorcentaje('15')` devuelve el 15 % de este monto.
     */
    public function aplicarPorcentaje(string|int|Decimal $porcentaje): self
    {
        return new self($this->cantidad->porcentaje($porcentaje), $this->moneda);
    }

    /**
     * Le descuenta un porcentaje: es lo que hace el descuento de adulto
     * mayor sobre el precio de lista.
     */
    public function menosPorcentaje(string|int|Decimal $porcentaje): self
    {
        return new self($this->cantidad->menosPorcentaje($porcentaje), $this->moneda);
    }

    // ── Comparación ───────────────────────────────────────────────────

    public function esCero(): bool
    {
        return $this->cantidad->esCero();
    }

    public function mayorQue(self $otro): bool
    {
        $this->verificarMismaMoneda($otro);

        return $this->cantidad->mayorQue($otro->cantidad);
    }

    public function menorQue(self $otro): bool
    {
        $this->verificarMismaMoneda($otro);

        return $this->cantidad->menorQue($otro->cantidad);
    }

    /**
     * Compara el valor REDONDEADO, que es lo que se cobra y lo que se
     * guarda. Dos montos que difieren en la doceava cifra decimal son el
     * mismo dinero.
     */
    public function igualA(self $otro): bool
    {
        return $this->moneda === $otro->moneda
            && $this->valor() === $otro->valor();
    }

    // ── Salida ────────────────────────────────────────────────────────

    /**
     * Lo que se cobra y lo que se guarda: redondeado a dos decimales.
     *
     * @return numeric-string
     */
    public function valor(): string
    {
        return $this->cantidad->redondeado(self::DECIMALES);
    }

    /**
     * El valor sin redondear, escala 12. Para seguir calculando sin
     * arrastrar el sesgo de redondear en cada paso.
     *
     * @return numeric-string
     */
    public function exacto(): string
    {
        return $this->cantidad->exacto();
    }

    /**
     * El `Decimal` de adentro, para operar contra costos y porcentajes
     * sin volver a parsear.
     */
    public function cantidad(): Decimal
    {
        return $this->cantidad;
    }

    public function centavos(): int
    {
        return (int) $this->cantidad->por('100')->redondeado(0);
    }

    public function formateado(?string $simbolo = null): string
    {
        /*
         * Sin símbolo y con Laravel arriba, sale de config. La clase sigue
         * siendo usable sin bootear el framework —los tests Unit no lo
         * levantan— así que hay un default.
         */
        $simbolo ??= function_exists('app') && app()->bound('config')
            ? (string) config('honduras.moneda.simbolo', 'L.')
            : 'L.';

        /*
         * `number_format` toma float, y acá ya no queda nada que perder:
         * el valor viene redondeado a dos decimales y solo se le agregan
         * las comas de los miles. Es la última línea antes de la pantalla.
         */
        return $simbolo.' '.number_format((float) $this->valor(), self::DECIMALES, '.', ',');
    }

    public function __toString(): string
    {
        return $this->formateado();
    }

    private function verificarMismaMoneda(self $otro): void
    {
        if ($this->moneda !== $otro->moneda) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'moneda',
                valor: "{$this->moneda} vs {$otro->moneda}",
                razon: 'No se pueden operar montos de monedas distintas sin conversión explícita.',
            );
        }
    }
}
