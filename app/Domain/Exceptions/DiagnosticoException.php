<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use DomainException;

final class DiagnosticoException extends DomainException
{
    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN SOLO PRINCIPAL POR MOMENTO
     * ─────────────────────────────────────────────────────────────────
     *
     * Dos principales al egreso es una cuenta que la aseguradora no sabe
     * contra cuál evaluar, y un caso contado dos veces en la notificación
     * epidemiológica. Si el médico cambió de opinión, lo que corresponde
     * no es agregar otro: es CORREGIR el que está, y que quede escrito
     * que cambió de idea.
     */
    public static function yaHayPrincipal(string $momento, string $codigo): self
    {
        return new self(
            "Ya hay un diagnóstico principal {$momento}: {$codigo}. ".
            'Si cambió el criterio, corregí ese en vez de agregar otro — así queda escrito qué se pensó antes.'
        );
    }

    public static function yaEstaRegistrado(string $codigo): self
    {
        return new self("{$codigo} ya está en la lista de este momento.");
    }

    /**
     * El diagnóstico se escribe sobre una atención que existe. Sobre un
     * encuentro anulado no hay nada que diagnosticar, y dejarlo pasar
     * llenaría el expediente de atenciones que nunca ocurrieron.
     */
    public static function encuentroAnulado(string $numero): self
    {
        return new self("El encuentro {$numero} está anulado: no admite diagnósticos.");
    }

    /**
     * ⚠️ El motivo de una enmienda es el dato, no el trámite.
     *
     * Un diagnóstico tachado sin explicación es peor que no tacharlo:
     * deja la duda instalada sin la respuesta. Diez caracteres no hacen
     * bueno un motivo, pero descartan «ok» y «error».
     */
    public static function enmiendaSinMotivo(): self
    {
        return new self(
            'Escribí qué pasó, con al menos diez caracteres. Dentro de dos años, «error» no le sirve a nadie — '.
            'y esto es lo que un perito lee para entender por qué cambió el diagnóstico.'
        );
    }

    public static function noEsVigente(): self
    {
        return new self('Ese diagnóstico ya fue corregido o retractado. Trabajá sobre el vigente.');
    }
}
