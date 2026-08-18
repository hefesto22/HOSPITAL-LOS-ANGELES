<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Support\NormalizadorDeTexto;
use Carbon\CarbonInterface;

/**
 * Lo que hace falta para registrar a un paciente, en un solo objeto.
 *
 * El registrador recibe esto y no un arreglo suelto. Con un arreglo, un
 * `primer_apelldo` mal escrito no falla: se guarda `null` y nadie se
 * entera hasta que el paciente no aparece en las búsquedas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CONSTRUCTOR `nn()` NO PIDE NADA, Y ESO ES LA DECISIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Entra un politraumatizado inconsciente. Cada campo obligatorio en esa
 * pantalla son segundos de alguien que debería estar poniendo una vía. El
 * sistema no puede ser el que frene.
 *
 * Lo que sí hace es dejarlo marcado: `es_nn = true` lo pone en la bandeja
 * de "identificar antes del alta", y la precisión de fecha queda en
 * `Estimada` para que ningún módulo clínico calcule una dosis sobre una
 * edad que nadie confirmó.
 */
final readonly class DatosDePaciente
{
    /**
     * @param array<int, DocumentoDeIdentidad> $documentos
     */
    public function __construct(
        public string $primerNombre,
        public ?string $primerApellido = null,
        public ?string $segundoNombre = null,
        public ?string $segundoApellido = null,
        public ?string $apellidoCasada = null,
        public SexoBiologico $sexoBiologico = SexoBiologico::Desconocido,
        public ?Genero $genero = null,
        public ?CarbonInterface $fechaNacimiento = null,
        public PrecisionFechaNacimiento $precisionFechaNacimiento = PrecisionFechaNacimiento::Exacta,
        public bool $esNn = false,
        public array $documentos = [],
        public ?string $notaIdentificacion = null,
        public ?string $nacionalidad = 'HN',
        public ?string $departamento = null,
        public ?string $municipio = null,
        public ?string $direccion = null,
        public ?string $telefono = null,
        public ?string $telefonoAlterno = null,
        public ?string $email = null,
    ) {}

    /**
     * El paciente sin identificar de emergencia.
     *
     * No pide un solo dato. El `$rasgos` opcional es texto libre para lo
     * que sirva a identificarlo después ("varón, ~40 años, tatuaje en
     * antebrazo derecho, lo trajo la ambulancia de bomberos").
     */
    public static function nn(?string $rasgos = null): self
    {
        return new self(
            primerNombre: 'NN',
            esNn: true,
            sexoBiologico: SexoBiologico::Desconocido,
            precisionFechaNacimiento: PrecisionFechaNacimiento::Estimada,
            notaIdentificacion: $rasgos,
            nacionalidad: null,
        );
    }

    /**
     * Clave de búsqueda del nombre, calculada igual que la columna
     * generada de `personas`. La usa el detector de duplicados ANTES de
     * que la persona exista.
     */
    public function claveDeNombre(): string
    {
        return NormalizadorDeTexto::claveDeNombre([
            $this->primerNombre,
            $this->segundoNombre,
            $this->primerApellido,
            $this->segundoApellido,
            $this->apellidoCasada,
        ]);
    }

    /**
     * Atributos para `personas`.
     *
     * `nombre_busqueda` NO aparece acá a propósito: la calcula PostgreSQL
     * y la base rechaza cualquier intento de escribirla.
     *
     * @return array<string, mixed>
     */
    public function atributosDePersona(): array
    {
        return [
            'primer_nombre'              => $this->primerNombre,
            'segundo_nombre'             => $this->segundoNombre,
            'primer_apellido'            => $this->primerApellido,
            'segundo_apellido'           => $this->segundoApellido,
            'apellido_casada'            => $this->apellidoCasada,
            'sexo_biologico'             => $this->sexoBiologico,
            'genero'                     => $this->genero,
            'fecha_nacimiento'           => $this->fechaNacimiento,
            'precision_fecha_nacimiento' => $this->precisionFechaNacimiento,
            'es_nn'                      => $this->esNn,
            'nota_identificacion'        => $this->notaIdentificacion,
            'nacionalidad'               => $this->nacionalidad,
            'departamento'               => $this->departamento,
            'municipio'                  => $this->municipio,
            'direccion'                  => $this->direccion,
            'telefono'                   => $this->telefono,
            'telefono_alterno'           => $this->telefonoAlterno,
            'email'                      => $this->email,
        ];
    }
}
