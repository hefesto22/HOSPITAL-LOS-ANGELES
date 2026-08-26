<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Los mensajes están escritos para quien está en admisión a las 3 de la
 * mañana, no para el log: dicen qué pasó y qué hacer.
 */
final class EncuentroException extends SihlaException
{
    public static function yaEstaInternado(string $paciente, string $numero): self
    {
        return new self(
            "{$paciente} ya tiene un ingreso de hospitalización abierto ({$numero}). "
            .'Abrí la cuenta sobre ese ingreso en vez de crear otro, o cerralo primero. '
            .'Dos ingresos vivos del mismo paciente producen dos cuentas y dos censos.'
        );
    }

    public static function expedienteDeOtraPersona(): self
    {
        return new self(
            'El expediente elegido no es de este paciente. Verificá el número antes de continuar: '
            .'un encuentro atado al expediente equivocado manda los resultados y los cargos a otra historia.'
        );
    }

    public static function noAdmiteCargos(string $numero, string $estado): self
    {
        return new self(
            "El encuentro {$numero} está {$estado} y ya no admite cargos. "
            .'Si el hecho clínico ocurrió de verdad, reabrí el encuentro o registralo en el que corresponda: '
            .'lo que no se puede es dejarlo sin asentar.'
        );
    }

    public static function sinSede(): self
    {
        return new self(
            'No hay sede en el contexto. Elegí la sede antes de abrir el encuentro: '
            .'el número de encuentro, el de cuenta y los precios se resuelven por sede.'
        );
    }

    public static function pacienteFallecido(string $paciente): self
    {
        return new self(
            "{$paciente} figura como fallecido en el índice de pacientes. "
            .'Si es un homónimo, resolvé la identidad antes de abrir el encuentro; '
            .'si es un error de registro, corregilo en el expediente.'
        );
    }
}
