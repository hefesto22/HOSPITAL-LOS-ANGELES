<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipo de ítem facturable — el catálogo es UNO SOLO (ADR-0003).
 *
 * Farmacia, laboratorio, imágenes, quirófano y hospitalización cobran
 * todos contra el mismo catálogo. Un catálogo por módulo obliga a
 * resolver cuatro veces —y de cuatro formas distintas— la unidad, el
 * régimen de ISV, la política de cobro y el mapeo contable; y hace
 * imposible la pregunta que el hospital hace cada mes: qué se le cobró a
 * este paciente y cuánto costó.
 *
 * ⚠️ El tipo NO determina el descuento de adulto mayor. Eso lo decide
 * `CategoriaLegalDeDescuento`, que es un eje propio porque sigue el texto
 * de la ley y no la taxonomía del catálogo: consulta general y consulta
 * especializada son las dos `Honorario` y llevan 25 % y 30 %. El tipo
 * solo PROPONE una categoría en el formulario.
 */
enum TipoItem: string
{
    case Servicio = 'servicio';
    case Procedimiento = 'procedimiento';
    case Medicamento = 'medicamento';
    case Insumo = 'insumo';
    case EstudioLaboratorio = 'estudio_laboratorio';
    case EstudioImagen = 'estudio_imagen';
    case Honorario = 'honorario';
    case Estancia = 'estancia';
    case Paquete = 'paquete';
    case Otro = 'otro';

    /**
     * ¿Este tipo descuenta existencia del inventario al cobrarse?
     *
     * Solo lo que es físico. Un honorario o una estancia se cobran pero
     * no mueven kardex.
     */
    public function mueveInventario(): bool
    {
        return match ($this) {
            self::Medicamento, self::Insumo => true,
            default                         => false,
        };
    }

    /**
     * ¿Su precio se DERIVA del costo promedio por margen (Ruta A), o se
     * fija a mano en el tarifario (Ruta B)?
     *
     * Los dos terminan en una fila de tarifario con vigencia. La
     * diferencia es quién la genera.
     */
    public function precioDerivadoDelCosto(): bool
    {
        return $this->mueveInventario();
    }

    /**
     * ¿Exige lote y fecha de vencimiento? Obligatorio por ARSA.
     */
    public function requiereLote(): bool
    {
        return $this === self::Medicamento;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * QUÉ CÓDIGOS ESTÁNDAR TIENEN SENTIDO PARA ESTE TIPO
     * ─────────────────────────────────────────────────────────────────
     *
     * Los tres códigos sirven para hablar con AFUERA, y cada uno con
     * alguien distinto: CIE-10 con SESAL y las aseguradoras, LOINC con
     * los analizadores del laboratorio, ATC para clasificar el
     * medicamento. Un honorario médico no tiene ninguno de los tres.
     *
     * Mostrarlos igual no es neutral: un formulario con campos que no
     * aplican enseña a saltear campos, y el día que uno de esos campos
     * SÍ importa —el ATC de un controlado— también se saltea.
     */
    public function usaCie10(): bool
    {
        return match ($this) {
            self::Procedimiento, self::EstudioImagen, self::EstudioLaboratorio => true,
            default                                                            => false,
        };
    }

    public function usaLoinc(): bool
    {
        return $this === self::EstudioLaboratorio;
    }

    public function usaAtc(): bool
    {
        return $this === self::Medicamento;
    }

    /**
     * ¿Alguno de los tres aplica? Es lo que decide si la sección de
     * códigos estándar se dibuja o no existe para este tipo.
     */
    public function usaAlgunCodigoEstandar(): bool
    {
        return $this->usaCie10() || $this->usaLoinc() || $this->usaAtc();
    }

    /**
     * ¿Tiene sentido preguntarle en qué unidad se cobra?
     *
     * Un honorario no se mide: es uno, o son dos. Un paquete tampoco —
     * su unidad es «el paquete». Preguntarlo es un campo más que llenar
     * para no decir nada.
     *
     * Lo demás sí: una estancia se cobra por DÍA, una observación por
     * HORA, un quirófano por HORA. Ahí la unidad es lo que hace legible
     * la línea de la cuenta, y por eso se sigue preguntando.
     *
     * ⚠️ Es distinto de la unidad del KARDEX. Lo que se almacena
     * necesita unidad sí o sí, sea del tipo que sea, y eso lo decide
     * `se_almacena` — no este método.
     */
    public function usaUnidadDeCobro(): bool
    {
        return match ($this) {
            self::Honorario, self::Paquete => false,
            default                        => true,
        };
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LOS CUATRO CAJONES DEL HOSPITAL
     * ─────────────────────────────────────────────────────────────────
     *
     * Diez tipos en un desplegable plano son diez decisiones. Pero
     * quien carga el catálogo no piensa en diez cosas: piensa en cuatro
     * —farmacia, honorarios, servicios, procedimientos— y recién adentro
     * de una elige cuál.
     *
     * 🔴 Se agrupan, NO se fusionan, y la diferencia importa:
     *
     *   · un estudio de laboratorio lleva código LOINC y uno de imagen
     *     no;
     *   · un medicamento lleva ATC y exige lote, un insumo no;
     *   · el margen objetivo se fija POR TIPO, y el de un medicamento no
     *     es el de una gasa;
     *   · el numeral del Art. 30 sale del tipo, y de él sale el
     *     descuento del adulto mayor.
     *
     * Fusionarlos borraría las cuatro cosas para ahorrar seis líneas en
     * un desplegable. Agruparlos no borra ninguna.
     */
    public function grupo(): string
    {
        return match ($this) {
            self::Medicamento,
            self::Insumo    => 'Farmacia y bodega',
            self::Honorario => 'Honorarios',
            self::Servicio,
            self::Estancia => 'Servicios',
            self::Procedimiento,
            self::EstudioImagen,
            self::EstudioLaboratorio => 'Procedimientos y estudios',
            self::Paquete,
            self::Otro => 'Otros',
        };
    }

    /**
     * Las opciones del desplegable, con encabezado por cajón.
     *
     * El orden de los grupos es el orden en que se dan de alta las
     * cosas en un hospital, no el alfabético.
     *
     * @return array<string, array<string, string>>
     */
    public static function opcionesAgrupadas(): array
    {
        $opciones = [
            'Farmacia y bodega'         => [],
            'Honorarios'                => [],
            'Servicios'                 => [],
            'Procedimientos y estudios' => [],
            'Otros'                     => [],
        ];

        foreach (self::cases() as $tipo) {
            $opciones[$tipo->grupo()][$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio           => 'Servicio',
            self::Procedimiento      => 'Procedimiento o cirugía',
            self::Medicamento        => 'Medicamento',
            self::Insumo             => 'Insumo o material (jeringas, tubos, gasas)',
            self::EstudioLaboratorio => 'Estudio de laboratorio',
            self::EstudioImagen      => 'Estudio de imagen (rayos X, ultrasonido)',
            self::Honorario          => 'Honorario médico',
            self::Estancia           => 'Estancia (por día o por hora)',
            self::Paquete            => 'Paquete',
            self::Otro               => 'Otro',
        };
    }
}
