<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Por qué se movió una existencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL SIGNO LO PONE EL TIPO, NO QUIEN LLAMA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Al registrador se le pasa siempre una cantidad **positiva**; el tipo
 * decide si suma o resta. Es la misma regla de `Monto`: el signo lo pone
 * el movimiento, no la cantidad. Dejar que quien llama mande un número
 * negativo es cómo aparece una dispensación que suma existencias.
 *
 * La base lo vuelve a exigir con un CHECK que ata el tipo al signo.
 */
enum TipoMovimiento: string
{
    // ── Entradas ──────────────────────────────────────────────────────

    /** Llegó del proveedor. */
    case EntradaPorCompra = 'entrada_por_compra';

    /** Volvió del paciente o del servicio sin consumirse. */
    case EntradaPorDevolucion = 'entrada_por_devolucion';

    /** Llegó de otro almacén del hospital. */
    case EntradaPorTraslado = 'entrada_por_traslado';

    /** El conteo físico encontró más de lo que decía el sistema. */
    /**
     * Lo que el hospital NO tenía y alguien le prestó.
     *
     * Entra al kardex de verdad —sube la existencia— porque la caja de
     * tabletas está físicamente en el estante y se va a dispensar. No
     * registrarla dejaría el conteo físico con una diferencia que nadie
     * puede explicar y un cobro sobre existencia que el sistema cree que
     * no existe.
     *
     * Lo que se debe por eso NO vive acá: vive en `prestamos`. El kardex
     * dice qué hay; el préstamo dice a quién hay que devolvérselo.
     */
    case EntradaPorPrestamo = 'entrada_por_prestamo';

    case AjustePositivo = 'ajuste_positivo';

    // ── Salidas ───────────────────────────────────────────────────────

    /** Se le entregó a un paciente. */
    case SalidaPorDispensacion = 'salida_por_dispensacion';

    /** Se rompió, se derramó, se abrió y no se usó. */
    case SalidaPorMerma = 'salida_por_merma';

    /** Se fue a otro almacén del hospital. */
    case SalidaPorTraslado = 'salida_por_traslado';

    /** Se venció y hay que darlo de baja. */
    case SalidaPorVencimiento = 'salida_por_vencimiento';

    /** El conteo físico encontró menos de lo que decía el sistema. */
    /** Se le devolvió al que había prestado. */
    case SalidaPorDevolucionDePrestamo = 'salida_por_devolucion_de_prestamo';

    case AjusteNegativo = 'ajuste_negativo';

    public function esEntrada(): bool
    {
        return match ($this) {
            self::EntradaPorCompra,
            self::EntradaPorDevolucion,
            self::EntradaPorTraslado,
            self::EntradaPorPrestamo,
            self::AjustePositivo => true,
            default              => false,
        };
    }

    public function esSalida(): bool
    {
        return ! $this->esEntrada();
    }

    /**
     * ¿Hay que escribir por qué?
     *
     * Los ajustes y las mermas sí, y la base lo exige con un CHECK. Un
     * ajuste sin explicación es la forma más limpia de tapar un faltante:
     * el número cuadra y nadie sabe qué pasó. Una compra o una
     * dispensación, en cambio, ya traen su documento.
     */
    public function exigeMotivo(): bool
    {
        return match ($this) {
            self::AjustePositivo,
            self::AjusteNegativo,
            self::SalidaPorMerma => true,
            default              => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::EntradaPorCompra      => 'Entrada por compra',
            self::EntradaPorDevolucion  => 'Devolución',
            self::EntradaPorTraslado    => 'Traslado recibido',
            self::EntradaPorPrestamo    => 'Entrada por préstamo',
            self::AjustePositivo        => 'Ajuste positivo',
            self::SalidaPorDispensacion => 'Dispensación',
            self::SalidaPorMerma        => 'Merma',
            self::SalidaPorTraslado     => 'Traslado enviado',

            self::SalidaPorDevolucionDePrestamo => 'Devolución de préstamo',
            self::SalidaPorVencimiento          => 'Baja por vencimiento',
            self::AjusteNegativo                => 'Ajuste negativo',
        };
    }

    public function color(): string
    {
        return match (true) {
            $this === self::SalidaPorMerma,
            $this === self::SalidaPorVencimiento => 'danger',
            $this === self::AjustePositivo,
            $this === self::AjusteNegativo => 'warning',

            /*
             * El préstamo se pinta distinto de una compra a propósito: en
             * la pantalla de kardex, «entrada» en verde se lee como
             * mercadería del hospital, y esta no lo es todavía.
             */
            $this === self::EntradaPorPrestamo,
            $this === self::SalidaPorDevolucionDePrestamo => 'info',
            $this->esEntrada()                            => 'success',
            default                                       => 'gray',
        };
    }

    /**
     * Los valores que la base acepta, para el CHECK de la migración.
     *
     * @return list<string>
     */
    public static function entradas(): array
    {
        return self::valoresDe(static fn (self $tipo): bool => $tipo->esEntrada());
    }

    /**
     * @return list<string>
     */
    public static function salidas(): array
    {
        return self::valoresDe(static fn (self $tipo): bool => $tipo->esSalida());
    }

    /**
     * `array_values` no es adorno: `array_filter` conserva las claves
     * originales, así que sin él lo que sale es un arreglo con huecos y
     * no una lista.
     *
     * @param callable(self): bool $condicion
     *
     * @return list<string>
     */
    private static function valoresDe(callable $condicion): array
    {
        return array_values(array_map(
            static fn (self $tipo): string => $tipo->value,
            array_filter(self::cases(), $condicion),
        ));
    }
}
