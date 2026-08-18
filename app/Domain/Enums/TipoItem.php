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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio           => 'Servicio',
            self::Procedimiento      => 'Procedimiento',
            self::Medicamento        => 'Medicamento',
            self::Insumo             => 'Insumo',
            self::EstudioLaboratorio => 'Estudio de laboratorio',
            self::EstudioImagen      => 'Estudio de imagen',
            self::Honorario          => 'Honorario médico',
            self::Estancia           => 'Estancia',
            self::Paquete            => 'Paquete',
            self::Otro               => 'Otro',
        };
    }
}
