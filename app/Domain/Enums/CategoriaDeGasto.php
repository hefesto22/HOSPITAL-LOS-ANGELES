<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué se gastó.
 *
 * Es la pregunta que contesta el reporte de fin de mes: «¿cuánto se nos
 * fue en medicamentos y cuánto en combustible?». Sin esto, el registro de
 * compras es una lista de números que no se puede leer.
 *
 * Es a propósito una lista CORTA. Una taxonomía de cuarenta categorías se
 * llena mal —quien captura elige la primera que suena parecido— y termina
 * dando peor información que doce bien puestas.
 */
enum CategoriaDeGasto: string
{
    case Medicamentos = 'medicamentos';
    case InsumosMedicos = 'insumos_medicos';
    case MaterialQuirurgico = 'material_quirurgico';
    case Laboratorio = 'laboratorio';
    case Imagenes = 'imagenes';
    case Alimentacion = 'alimentacion';
    case Limpieza = 'limpieza';
    case Combustible = 'combustible';
    case Mantenimiento = 'mantenimiento';
    case ServiciosBasicos = 'servicios_basicos';
    case Papeleria = 'papeleria';
    case Equipo = 'equipo';
    case Honorarios = 'honorarios';
    case Otros = 'otros';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Medicamentos       => 'Medicamentos',
            self::InsumosMedicos     => 'Insumos médicos',
            self::MaterialQuirurgico => 'Material quirúrgico',
            self::Laboratorio        => 'Laboratorio',
            self::Imagenes           => 'Imágenes',
            self::Alimentacion       => 'Alimentación',
            self::Limpieza           => 'Limpieza',
            self::Combustible        => 'Combustible',
            self::Mantenimiento      => 'Mantenimiento',
            self::ServiciosBasicos   => 'Servicios básicos',
            self::Papeleria          => 'Papelería',
            self::Equipo             => 'Equipo',
            self::Honorarios         => 'Honorarios',
            self::Otros              => 'Otros',
        };
    }

    /**
     * ¿Lo comprado bajo esta categoría normalmente entra al estante?
     *
     * Sirve para un aviso, no para una regla: si alguien registra una
     * compra de medicamentos y no hay ninguna recepción cerca, es
     * probable que falte meterla al inventario. El sistema lo sugiere y
     * no lo impone — hay compras de medicamentos que van directo a un
     * servicio y se consumen el mismo día.
     */
    public function sueleEntrarAlInventario(): bool
    {
        return match ($this) {
            self::Medicamentos,
            self::InsumosMedicos,
            self::MaterialQuirurgico,
            self::Laboratorio,
            self::Imagenes => true,
            default        => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $categoria): string => $categoria->value, self::cases());
    }
}
