<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Coincidencia;
use Illuminate\Support\Collection;

/**
 * El paciente que se está registrando ya parece existir, y la coincidencia
 * es lo bastante fuerte como para no dejar crear otro.
 *
 * Lleva los candidatos adentro para que quien la atrape pueda mostrarlos.
 * Una excepción que solo dice "duplicado" obliga a volver a buscar para
 * saber contra quién chocó, y en admisión eso es una consulta más con el
 * paciente esperando.
 */
final class PosibleDuplicadoException extends SihlaException
{
    /**
     * @param Collection<int, Coincidencia> $coincidencias
     */
    public function __construct(public readonly Collection $coincidencias)
    {
        $razones = $coincidencias->map(
            static fn (Coincidencia $c): string => $c->resumen()
        )->implode(' | ');

        parent::__construct(
            'El paciente ya parece estar registrado. Candidatos: '.$razones
        );
    }
}
