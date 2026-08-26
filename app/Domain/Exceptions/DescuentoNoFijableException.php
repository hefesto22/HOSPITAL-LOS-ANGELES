<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use Carbon\CarbonInterface;
use DomainException;

/**
 * Lo que impide cargar un porcentaje de descuento.
 *
 * Existe para que el error llegue en castellano a quien está cargando la
 * reforma, y no como una violación de la restricción de exclusión de
 * PostgreSQL — que es correcta, pero ilegible.
 *
 * Sirve a las dos tablas: `descuentos_legales`, indexada por numeral del
 * Art. 30, y `descuentos`, la lista con nombres del hospital. Los dos
 * fijadores tropiezan con las mismas piedras.
 */
final class DescuentoNoFijableException extends DomainException
{
    public static function porcentajeFueraDeRango(string $porcentaje): self
    {
        return new self(
            "El porcentaje {$porcentaje} no es una fracción entre 0 y 1. "
            .'Un 25 % se guarda como 0.25.'
        );
    }

    /**
     * El caso que la restricción de exclusión atajaría igual, pero sin
     * explicar nada: ya hay un porcentaje cargado que empieza DESPUÉS del
     * día que se está queriendo usar.
     */
    public static function yaHayUnoPosterior(
        CategoriaLegalDeDescuento $categoria,
        RangoEdad $rango,
        CarbonInterface $desde,
    ): self {
        return new self(sprintf(
            'Ya hay un porcentaje de %s para la %s que empieza el %s. '
            .'Cargar uno con fecha anterior partiría la vigencia en dos. '
            .'Corregí el que ya está cargado.',
            mb_strtolower($categoria->etiqueta()),
            mb_strtolower($rango->etiqueta()),
            $desde->format('d/m/Y'),
        ));
    }

    /**
     * Lo mismo, para la lista con nombres del hospital.
     */
    public static function yaHayUnoPosteriorLlamado(string $nombre, CarbonInterface $desde): self
    {
        return new self(sprintf(
            '«%s» ya tiene un porcentaje que empieza el %s. Cargar uno con fecha anterior '
            .'partiría la vigencia en dos. Corregí el que ya está cargado, o poné una fecha '
            .'posterior.',
            $nombre,
            $desde->format('d/m/Y'),
        ));
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL NOMBRE ES LA IDENTIDAD
     * ─────────────────────────────────────────────────────────────────
     *
     * Los ítems no se marcan contra el `id` de la fila sino contra su
     * NOMBRE, así que dos filas llamadas «Tercera edad» que apliquen a
     * cosas distintas serían el mismo descuento cambiando de destinatario
     * a mitad del año — y a todos los ítems que lo tenían marcado les
     * cambiaría solo. Ver el encabezado de `Descuento`.
     */
    public static function elNombreYaAplicaAOtraCosa(
        string $nombre,
        AplicacionDeDescuento $actual,
    ): self {
        return new self(sprintf(
            '«%s» ya existe y se aplica a: %s. Un descuento no puede cambiar de destinatario '
            .'sin cambiar de nombre, porque los ítems lo tienen marcado por el nombre. '
            .'Si querés otra cosa, usale otro nombre.',
            $nombre,
            mb_strtolower($actual->etiqueta()),
        ));
    }

    public static function elNombreEsMuyCorto(string $nombre): self
    {
        return new self(sprintf(
            '«%s» no alcanza como nombre: hacen falta al menos tres letras. El nombre es lo que '
            .'identifica al descuento en cada ítem y en cada factura.',
            trim($nombre),
        ));
    }
}
