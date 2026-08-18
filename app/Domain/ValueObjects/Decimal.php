<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Número decimal exacto. La base aritmética de todo el dinero del sistema.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE, Y POR QUÉ NO ES UN FLOAT
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.6.2, sin excepciones: **bcmath sobre strings, escala interna 12,
 * redondeo half-up solo al exponer. `float` y `double` prohibidos en PHP
 * y en la base.**
 *
 * No es purismo. En punto flotante `0.1 + 0.2` no da `0.3`, y eso no se
 * nota en una operación: se nota a los seis meses, cuando el valor del
 * inventario no cuadra con contabilidad por ochenta lempiras que nadie
 * puede rastrear. El error no está en ningún lado en particular — está
 * repartido en cien mil movimientos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESCALA 12 ADENTRO, 2 AFUERA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo el cálculo intermedio se hace con doce decimales y **no se
 * redondea hasta el final**. Redondear en cada paso acumula sesgo: es la
 * diferencia entre el centavo que sobra y el centavo que falta,
 * multiplicada por la cantidad de pasos.
 *
 * Redondear al exponer es half-up **alejándose del cero**, que es la
 * convención contable: 2.345 → 2.35, y −2.345 → −2.35.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES INMUTABLE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada operación devuelve una instancia nueva. Un monto que se puede
 * mutar es un monto que alguien mutó desde otra parte del código, y
 * después hay que averiguar quién.
 */
final readonly class Decimal implements Stringable
{
    /**
     * Escala interna. §8.6.2.
     */
    public const ESCALA = 12;

    /** @var numeric-string */
    private string $valor;

    /**
     * @param numeric-string $valor ya normalizado a la escala interna
     */
    private function __construct(string $valor)
    {
        $this->valor = $valor;
    }

    /**
     * Único constructor público.
     *
     * ⚠️ NO acepta `float`, y es deliberado. Un float que entra acá ya
     * perdió precisión antes de llegar; aceptarlo sería fingir que el
     * problema se resuelve adentro. Para el caso legítimo —un dato que
     * viene en float de una fuente externa que no controlamos— está
     * `deFloat()`, que se puede buscar en el código y revisar una por una.
     */
    public static function de(string|int|self $valor): self
    {
        if ($valor instanceof self) {
            return $valor;
        }

        $texto = is_int($valor) ? (string) $valor : trim($valor);

        /*
         * Se valida con las DOS cosas a propósito.
         *
         * `is_numeric` acepta notación científica —"1e5"— y bcmath no la
         * entiende: en PHP 8 tira un ValueError con un mensaje que no
         * dice nada del dominio. La expresión regular exige la forma que
         * bcmath sí sabe leer.
         *
         * `is_numeric` se queda igual porque es lo que le permite al
         * analizador estático saber que a partir de acá el texto es un
         * `numeric-string`, que es el tipo que exigen bcadd y compañía.
         */
        if (! is_numeric($texto) || preg_match('/^-?\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'decimal',
                valor: $texto,
                razon: 'No es un número decimal en forma simple (ej. -12.3400).',
            );
        }

        return new self(bcadd($texto, '0', self::ESCALA));
    }

    /**
     * Escotilla explícita para datos que ya vienen en float.
     *
     * Se llama a propósito y se ve en el código de quien la usa. Si
     * aparece en un cálculo de dinero nuestro, es un bug: el valor
     * debería haber sido string desde el origen.
     */
    public static function deFloat(float $valor): self
    {
        return self::de(sprintf('%.'.self::ESCALA.'F', $valor));
    }

    public static function cero(): self
    {
        return new self(bcadd('0', '0', self::ESCALA));
    }

    // ── Aritmética ────────────────────────────────────────────────────

    public function sumar(string|int|self $otro): self
    {
        return new self(bcadd($this->valor, self::de($otro)->valor, self::ESCALA));
    }

    public function restar(string|int|self $otro): self
    {
        return new self(bcsub($this->valor, self::de($otro)->valor, self::ESCALA));
    }

    public function por(string|int|self $otro): self
    {
        return new self(bcmul($this->valor, self::de($otro)->valor, self::ESCALA));
    }

    /**
     * @throws ValueObjectInvalidoException si el divisor es cero
     */
    public function entre(string|int|self $otro): self
    {
        $divisor = self::de($otro);

        /*
         * Alcanza con esta guarda y no hace falta atrapar el
         * DivisionByZeroError de bcdiv: `de()` ya normalizó el divisor a
         * la escala interna, así que un valor más chico que 1e-12
         * —0.0000000000001, por ejemplo— llega acá siendo exactamente
         * cero y lo agarra este `if`. bcdiv nunca ve un cero.
         */
        if ($divisor->esCero()) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'divisor',
                valor: $divisor->exacto(),
                razon: 'Es cero, o tan chico que a la escala interna equivale a cero.',
            );
        }

        return new self(bcdiv($this->valor, $divisor->valor, self::ESCALA));
    }

    /**
     * Aplica un porcentaje expresado como número entero o decimal:
     * `porcentaje('15')` es el 15 %, no el 1500 %.
     */
    public function porcentaje(string|int|self $porcentaje): self
    {
        return $this->por(self::de($porcentaje)->entre('100'));
    }

    /**
     * Le RESTA un porcentaje. Es lo que hace un descuento.
     */
    public function menosPorcentaje(string|int|self $porcentaje): self
    {
        return $this->restar($this->porcentaje($porcentaje));
    }

    // ── Comparación ───────────────────────────────────────────────────

    /**
     * -1 si es menor, 0 si es igual, 1 si es mayor.
     */
    public function comparar(string|int|self $otro): int
    {
        return bccomp($this->valor, self::de($otro)->valor, self::ESCALA);
    }

    public function igualA(string|int|self $otro): bool
    {
        return $this->comparar($otro) === 0;
    }

    public function mayorQue(string|int|self $otro): bool
    {
        return $this->comparar($otro) === 1;
    }

    public function menorQue(string|int|self $otro): bool
    {
        return $this->comparar($otro) === -1;
    }

    public function esCero(): bool
    {
        return bccomp($this->valor, '0', self::ESCALA) === 0;
    }

    public function esNegativo(): bool
    {
        return bccomp($this->valor, '0', self::ESCALA) === -1;
    }

    // ── Salida ────────────────────────────────────────────────────────

    /**
     * El valor completo, sin redondear. Para encadenar cálculos y para
     * guardar en columnas de costo, que llevan más decimales que el
     * dinero (§8.6.2).
     *
     * @return numeric-string
     */
    public function exacto(): string
    {
        return $this->valor;
    }

    /**
     * Redondeo half-up alejándose del cero: 2.345 → 2.35, −2.345 → −2.35.
     *
     * ⚠️ Esto es lo ÚNICO que redondea, y se llama al final. El truco es
     * sumar medio dígito de la posición siguiente y dejar que bcmath
     * trunque: es exacto y no depende de la implementación de `round()`.
     *
     * @return numeric-string
     */
    public function redondeado(int $decimales = 2): string
    {
        if ($decimales < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'decimales',
                valor: (string) $decimales,
                razon: 'No puede ser negativo.',
            );
        }

        /*
         * El medio dígito se calcula con bcmath, no armando el string
         * "0.005" con `str_repeat`.
         *
         * Concatenar produce un texto que resulta ser numérico, pero eso
         * el analizador no lo puede probar: ve un `literal-string` y
         * bcadd exige un `numeric-string`. Y tiene razón en desconfiar —
         * es exactamente el tipo de valor que alguien rompe editando la
         * expresión sin darse cuenta.
         *
         * `5 / 10^(decimales+1)` da lo mismo y nunca deja de ser un
         * número: 0.5 · 0.005 · 0.00005 para 0, 2 y 4 decimales.
         */
        $medio = bcdiv('5', bcpow('10', (string) ($decimales + 1)), $decimales + 1);

        if (! $this->esNegativo()) {
            return bcadd($this->valor, $medio, $decimales);
        }

        /*
         * Se redondea el valor absoluto y se le devuelve el signo con
         * `bcsub`, no concatenando un guion. Dos razones: concatenar
         * produce "-0.00" cuando el redondeo da cero, y además rompe la
         * garantía de que lo que sale de acá es un numeric-string.
         */
        $absoluto = bcsub('0', $this->valor, self::ESCALA);

        return bcsub('0', bcadd($absoluto, $medio, $decimales), $decimales);
    }

    /**
     * Para meter en un `where` o un `insert` contra una columna
     * NUMERIC(14,4). Postgres redondea solo, pero redondear acá deja el
     * valor guardado igual al que se mostró.
     *
     * @return numeric-string
     */
    public function paraBase(int $decimales = 4): string
    {
        return $this->redondeado($decimales);
    }

    public function __toString(): string
    {
        return $this->exacto();
    }
}
