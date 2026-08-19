<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Sobre qué monto se calcula el descuento del adulto mayor cuando la
 * factura NO la paga el paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA LEY NO LO RESUELVE, Y ESO NO SE PUEDE TAPAR CON UN DEFAULT
 * ─────────────────────────────────────────────────────────────────────
 *
 * El Art. 30 del Decreto Legislativo 199-2006 ordena el descuento a favor
 * del adulto mayor, pero está escrito pensando en el paciente que paga en
 * caja. **No dice qué pasa cuando el que paga es un seguro.** Las tres
 * lecturas de abajo son defendibles y llevan a facturas distintas, así
 * que el sistema no elige por su cuenta: la columna es obligatoria y cada
 * convenio la declara junto al fundamento de por qué.
 *
 * ⚠️ Esto NO es asesoría legal. Es el pendiente #16 del §7 de
 * `docs/dominio-inventario-y-precios.md` y necesita criterio de un
 * abogado sobre el texto consolidado de la Gaceta. Lo que el sistema
 * garantiza es que la decisión quede escrita, fechada y firmada por
 * alguien — no aplicada en silencio por un default que nadie eligió.
 */
enum BaseDelDescuentoLegal: string
{
    /**
     * El descuento cae sobre lo que el paciente desembolsa de su bolsillo
     * —deducible, coaseguro, copago— y no sobre lo que cubre el seguro.
     */
    case SobreLoQuePagaElPaciente = 'sobre_lo_que_paga_el_paciente';

    /**
     * El descuento cae sobre el total de la factura, lo pague quien lo
     * pague. Es la lectura más literal del artículo y la más favorable al
     * paciente; también es la que más discute el pagador.
     */
    case SobreElTotalFacturado = 'sobre_el_total_facturado';

    /**
     * Este convenio no aplica el descuento porque su propio esquema ya
     * contempla el beneficio, o porque la cobertura es total y no hay
     * monto del paciente sobre el cual calcularlo.
     */
    case NoAplica = 'no_aplica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SobreLoQuePagaElPaciente => 'Sobre lo que paga el paciente',
            self::SobreElTotalFacturado    => 'Sobre el total facturado',
            self::NoAplica                 => 'No aplica en este convenio',
        };
    }

    public function explicacion(): string
    {
        return match ($this) {
            self::SobreLoQuePagaElPaciente => 'El descuento se calcula solo sobre el deducible, '
                .'coaseguro o copago que el paciente desembolsa. Lo que cubre el pagador queda '
                .'fuera de la base.',
            self::SobreElTotalFacturado => 'El descuento se calcula sobre el total de la '
                .'factura, sin importar quién la paga. Es la lectura más literal del artículo '
                .'y también la que más discute el pagador.',
            self::NoAplica => 'Este convenio no aplica el descuento del Art. 30, sea porque su '
                .'propio esquema ya contempla el beneficio o porque la cobertura es total y no '
                .'queda monto del paciente sobre el cual calcularlo.',
        };
    }

    public function aplica(): bool
    {
        return $this !== self::NoAplica;
    }

    /**
     * Lo que hay que leer antes de elegir. Va tal cual a la pantalla.
     */
    public static function advertencia(): string
    {
        return 'El Art. 30 del Decreto 199-2006 no dice qué pasa cuando la factura la paga un '
            .'tercero. Las tres opciones son defendibles y dan facturas distintas, así que el '
            .'sistema no elige por vos: escribí abajo con qué criterio y con qué respaldo se '
            .'toma la decisión. Esto requiere opinión de un abogado — el pendiente #16 sigue '
            .'abierto.';
    }
}
