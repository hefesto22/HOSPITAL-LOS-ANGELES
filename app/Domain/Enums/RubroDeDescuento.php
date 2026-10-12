<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * A qué renglones de la cuenta alcanza un descuento del hospital, además
 * del de farmacia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ HONORARIOS VA APARTE (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * El 11-oct-2026 los honorarios quedaron afuera del descuento en
 * servicios: son plata del médico. Mauricio, al día siguiente: «según lo
 * que me dijeron también hacían descuentos en honorarios». Se hacen, pero
 * no en la misma bolsa que la habitación y el laboratorio: rebajar el
 * honorario es rebajarle al médico, y eso se decide aparte, con su propio
 * motivo. Por eso cada rubro tiene su campo, su motivo y sus columnas.
 *
 * Los dos se topan igual: por la categoría del Art. 30 de cada renglón
 * (`PoliticaDeDescuentoComercial::topeDeLaCategoria()`).
 */
enum RubroDeDescuento: string
{
    /** Hospitalización, consultas, laboratorio, rayos X, cafetería: lo que no es farmacia ni médico. */
    case Servicios = 'servicios';

    /** Lo que cobra el médico. */
    case Honorarios = 'honorarios';

    public function columnaFraccion(): string
    {
        return 'descuento_'.$this->value;
    }

    public function columnaMonto(): string
    {
        return 'descuento_'.$this->value.'_monto';
    }

    public function columnaMotivo(): string
    {
        return 'motivo_descuento_'.$this->value;
    }

    public function columnaPor(): string
    {
        return 'descuento_'.$this->value.'_por';
    }

    public function columnaEn(): string
    {
        return 'descuento_'.$this->value.'_en';
    }

    /**
     * Como se nombra en una oración: «no hay servicios entre los que…».
     */
    public function enPlural(): string
    {
        return $this->value;
    }

    public function enSingular(): string
    {
        return match ($this) {
            self::Servicios  => 'servicio',
            self::Honorarios => 'honorario',
        };
    }

    /**
     * Las categorías del Art. 30 en que suele caer, para decir los topes
     * en pantalla. El tope de cada renglón sale de SU categoría; esto es
     * solo lo que se muestra antes de teclear.
     *
     * @return list<CategoriaLegalDeDescuento>
     */
    public function categorias(): array
    {
        return match ($this) {
            self::Servicios => [
                CategoriaLegalDeDescuento::ServicioHospitalario,
                CategoriaLegalDeDescuento::ConsultaGeneral,
                CategoriaLegalDeDescuento::ConsultaEspecializada,
                CategoriaLegalDeDescuento::IntervencionQuirurgica,
                CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                CategoriaLegalDeDescuento::SinDescuentoLegal,
            ],
            self::Honorarios => [
                CategoriaLegalDeDescuento::ConsultaGeneral,
                CategoriaLegalDeDescuento::ConsultaEspecializada,
                CategoriaLegalDeDescuento::IntervencionQuirurgica,
            ],
        };
    }
}
