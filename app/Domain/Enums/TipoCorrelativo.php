<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Secuencias NO fiscales del sistema.
 *
 * ⚠️ Acá NO va la factura ni la nota de crédito.
 *
 * El correlativo fiscal del SAR es otra cosa: tiene CAI, rango autorizado,
 * fecha límite y un formato NNN-NNN-NN-NNNNNNNN definido por el Acuerdo
 * 481-2017. Además hoy **está bloqueado** por las preguntas #1 y #2 de
 * `docs/dominio.md` — la vigencia del CAI y el trámite de autoimpresor no
 * están confirmados con el SAR. Se construye en el bloque 7, no acá.
 *
 * Estas secuencias, en cambio, son internas del hospital: nadie fuera las
 * audita y su formato lo decidimos nosotros. Lo que sí comparten con el
 * correlativo fiscal es la regla dura: **un número nunca se repite y nunca
 * se reutiliza**, ni aunque el registro que lo consumió se anule.
 */
enum TipoCorrelativo: string
{
    case Expediente = 'expediente';
    case Encuentro = 'encuentro';
    case Cuenta = 'cuenta';
    case OrdenLaboratorio = 'orden_laboratorio';
    case OrdenImagen = 'orden_imagen';
    case Muestra = 'muestra';
    case Accession = 'accession';
    case Traslado = 'traslado';
    case AjusteInventario = 'ajuste_inventario';

    /**
     * Prefijo visible del número generado.
     */
    public function prefijo(): string
    {
        return match ($this) {
            self::Expediente       => 'EXP',
            self::Encuentro        => 'ENC',
            self::Cuenta           => 'CTA',
            self::OrdenLaboratorio => 'LAB',
            self::OrdenImagen      => 'IMG',
            self::Muestra          => 'MUE',
            self::Accession        => 'ACC',
            self::Traslado         => 'TRA',
            self::AjusteInventario => 'AJU',
        };
    }

    /**
     * ¿La secuencia vuelve a 1 cada año?
     *
     * El EXPEDIENTE no reinicia nunca: es la identidad del paciente en el
     * hospital y debe ser único de por vida. Reiniciarlo produciría dos
     * pacientes con el mismo número de expediente en años distintos, que
     * es exactamente el error de identidad que el §8.2 existe para evitar.
     *
     * El accession number tampoco: DICOM exige unicidad global y el PACS
     * lo usa como llave.
     *
     * El resto sí reinicia, porque son operativos y el año da contexto.
     */
    public function reiniciaAnualmente(): bool
    {
        return match ($this) {
            self::Expediente, self::Accession => false,
            default                           => true,
        };
    }

    /**
     * Dígitos del número, con relleno de ceros.
     */
    public function longitud(): int
    {
        return match ($this) {
            self::Expediente => 8,
            self::Accession  => 10,
            default          => 6,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Expediente       => 'Expediente',
            self::Encuentro        => 'Encuentro',
            self::Cuenta           => 'Cuenta del paciente',
            self::OrdenLaboratorio => 'Orden de laboratorio',
            self::OrdenImagen      => 'Orden de imagen',
            self::Muestra          => 'Muestra',
            self::Accession        => 'Accession number (DICOM)',
            self::Traslado         => 'Traslado de inventario',
            self::AjusteInventario => 'Ajuste de inventario',
        };
    }
}
