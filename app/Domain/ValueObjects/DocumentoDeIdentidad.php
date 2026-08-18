<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Un documento presentado por un paciente, ya normalizado y validado.
 *
 * Existe para que el número NUNCA viaje como string suelto entre el
 * formulario, el detector de duplicados y el registrador. Un string se
 * normaliza en un lado y en el otro no, y el resultado es que el sistema
 * guarda el DNI pero no lo encuentra.
 *
 * ⚠️ EL NÚMERO NO APARECE EN LOS MENSAJES DE ERROR.
 *
 * Un DNI dentro del texto de una excepción termina en el log, en Sentry y
 * en el correo de alerta. Eso es dato personal saliendo del sistema por la
 * puerta de atrás, sin bitácora y sin control de acceso. Por eso el mensaje
 * lleva el número enmascarado: alcanza para que quien depura reconozca cuál
 * era, y no alcanza para identificar a nadie.
 */
final readonly class DocumentoDeIdentidad implements Stringable
{
    public string $valor;

    public function __construct(
        public TipoIdentificador $tipo,
        string $valor,
        public ?string $paisEmision = null,
        public bool $esPrincipal = false,
    ) {
        $this->valor = $tipo->normalizar($valor);

        if ($this->valor === '') {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'documento',
                valor: '(vacío)',
                razon: "El {$tipo->etiqueta()} no puede quedar en blanco.",
            );
        }

        $longitud = $tipo->longitudExacta();

        if ($longitud !== null && mb_strlen($this->valor) !== $longitud) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'documento',
                valor: self::enmascarar($this->valor),
                razon: "El {$tipo->etiqueta()} debe tener exactamente {$longitud} dígitos.",
            );
        }

        if ($tipo->requierePaisEmision() && $paisEmision === null) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'documento',
                valor: self::enmascarar($this->valor),
                razon: "El {$tipo->etiqueta()} necesita el país que lo emitió: el mismo número puede existir en dos países.",
            );
        }
    }

    /**
     * Deja visibles solo los últimos cuatro caracteres.
     */
    public static function enmascarar(string $valor): string
    {
        $largo = mb_strlen($valor);

        if ($largo <= 4) {
            return str_repeat('*', $largo);
        }

        return str_repeat('*', $largo - 4).mb_substr($valor, -4);
    }

    public function enmascarado(): string
    {
        return self::enmascarar($this->valor);
    }

    /**
     * Cómo se imprime: 0801-1990-12345.
     */
    public function __toString(): string
    {
        return $this->tipo->formatear($this->valor);
    }

    /**
     * Atributos para `persona_identificadores`.
     *
     * @return array<string, mixed>
     */
    public function atributos(): array
    {
        return [
            'tipo'           => $this->tipo->value,
            'valor'          => $this->valor,
            'valor_original' => $this->valor,
            'pais_emision'   => $this->paisEmision,
            'es_principal'   => $this->esPrincipal,
        ];
    }
}
