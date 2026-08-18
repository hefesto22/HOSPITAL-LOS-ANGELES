<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Bajo qué numeral del Artículo 30 cae este ítem.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO ES `TipoItem`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Consulta general y consulta especializada son las dos honorarios —el
 * mismo `TipoItem`— y llevan 25 % y 30 % respectivamente. Y al revés: una
 * radiografía (`estudio_imagen`) y una extracción dental
 * (`procedimiento`) son tipos distintos con el mismo 30 %, porque la ley
 * los metió en el mismo numeral.
 *
 * El eje del descuento legal es el TEXTO DE LA LEY, no la taxonomía
 * interna del catálogo. Separarlos permite responder la única pregunta
 * que importa cuando llega una denuncia a la línea 115: *"¿por qué a este
 * ítem le aplicaron 30 %?"* — porque cae en el numeral 8, y acá está
 * escrito.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS PORCENTAJES NO ESTÁN ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Este enum dice a qué CATEGORÍA pertenece un ítem; cuánto se le
 * descuenta vive en base de datos con vigencia, porque cambia por ley y
 * hay que poder reconstruir cuál regía el día del servicio de una factura
 * de hace dos años. `porcentajeDeReferencia()` existe solo para sembrar
 * esa tabla y para que el código sea legible — **nadie factura con él**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * FUENTE — verificada el 18-ago-2026
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ley Integral de Protección al Adulto Mayor y Jubilados, Decreto
 * Legislativo 199-2006, Capítulo VI, Sección I, Artículo 30, numerales 5
 * a 9.
 *
 * ⚠️ El Decreto 45-2025 (La Gaceta 37,047, 19-ene-2026) NO reformó este
 * artículo. Reformó el **Artículo 31 — Sección II, Descuento al Pago de
 * Servicios**: energía, agua, telecomunicaciones, cable, bienes inmuebles
 * y salida aeroportuaria. La "cuarta edad" con 35 % vive ahí, no en
 * salud. **En salud el único umbral es 60 años y el techo es 30 %.**
 *
 * El enum `RangoEdad` conserva `Cuarta` de todos modos: el día que el
 * Congreso extienda la cuarta edad a servicios médicos —que es lo que la
 * prensa ya daba por hecho en enero— tiene que ser una fila de
 * configuración, no un despliegue.
 */
enum CategoriaLegalDeDescuento: string
{
    /** Art. 30 num. 5 — servicios de salud en hospitales y clínicas privadas. */
    case ServicioHospitalario = 'servicio_hospitalario';

    /** Art. 30 num. 6 — medicamentos y material quirúrgico. Exige receta (Art. 34). */
    case MedicamentoYMaterialQuirurgico = 'medicamento_material_quirurgico';

    /** Art. 30 num. 7 — honorarios por consulta médica general. */
    case ConsultaGeneral = 'consulta_general';

    /** Art. 30 num. 7 — honorarios por consulta médica especializada. */
    case ConsultaEspecializada = 'consulta_especializada';

    /** Art. 30 num. 8 — intervención quirúrgica. */
    case IntervencionQuirurgica = 'intervencion_quirurgica';

    /** Art. 30 num. 8 — odontología, optometría y oftalmología. */
    case OdontologiaYOftalmologia = 'odontologia_oftalmologia';

    /** Art. 30 num. 8 y 9 — radiología y laboratorio. */
    case RadiologiaYLaboratorio = 'radiologia_laboratorio';

    /** Art. 30 num. 9 — exámenes y pruebas de medicina computarizada. */
    case MedicinaComputarizada = 'medicina_computarizada';

    /**
     * Fuera del Artículo 30: cafetería, parqueo, alquiler a terceros,
     * tratamiento de belleza estética.
     *
     * No es "todavía no lo clasifiqué" — es una afirmación: este ítem no
     * lleva descuento de adulto mayor. Por eso hay que elegirla a
     * propósito y no es el valor por defecto de nada.
     */
    case SinDescuentoLegal = 'sin_descuento_legal';

    /**
     * Numeral del Art. 30 que sustenta la categoría.
     *
     * Se muestra en pantalla al lado del porcentaje. Un descuento que no
     * puede citar su fundamento es un descuento que no se puede defender.
     */
    public function numeral(): ?string
    {
        return match ($this) {
            self::ServicioHospitalario           => 'Art. 30, numeral 5',
            self::MedicamentoYMaterialQuirurgico => 'Art. 30, numeral 6',
            self::ConsultaGeneral,
            self::ConsultaEspecializada => 'Art. 30, numeral 7',
            self::IntervencionQuirurgica,
            self::OdontologiaYOftalmologia => 'Art. 30, numeral 8',
            self::RadiologiaYLaboratorio   => 'Art. 30, numerales 8 y 9',
            self::MedicinaComputarizada    => 'Art. 30, numeral 9',
            self::SinDescuentoLegal        => null,
        };
    }

    /**
     * Porcentaje de la ley vigente al 18-ago-2026, como fracción.
     *
     * ⚠️ SOLO para sembrar la tabla de descuentos y para explicarse en
     * pantalla. El motor de precios lee la tabla con vigencia, nunca esto:
     * una factura de 2027 tiene que poder reimprimirse con el porcentaje
     * que regía el día del servicio, y una constante no tiene fecha.
     */
    public function porcentajeDeReferencia(): float
    {
        return match ($this) {
            self::ServicioHospitalario,
            self::MedicamentoYMaterialQuirurgico,
            self::ConsultaGeneral => 0.25,
            self::ConsultaEspecializada,
            self::IntervencionQuirurgica,
            self::OdontologiaYOftalmologia,
            self::RadiologiaYLaboratorio,
            self::MedicinaComputarizada => 0.30,
            self::SinDescuentoLegal     => 0.0,
        };
    }

    /**
     * ¿El descuento de esta categoría exige receta original firmada y
     * sellada?
     *
     * Art. 34: para medicamentos, sí. En dispensación a paciente internado
     * sale de la orden médica y es automático; **en venta de mostrador hay
     * que capturarla**, y si no la hay el descuento no procede — y el
     * sistema tiene que decir por qué en vez de omitirlo en silencio.
     */
    public function exigeReceta(): bool
    {
        return $this === self::MedicamentoYMaterialQuirurgico;
    }

    /**
     * Sugerencia a partir del tipo de ítem, para que el formulario
     * proponga y no obligue a saberse la ley de memoria.
     *
     * Es una SUGERENCIA: honorario cae en consulta general, pero el
     * honorario de un cardiólogo es especializada y lleva 30 %. Quien
     * carga el catálogo decide; esto solo evita empezar en blanco.
     */
    public static function sugeridaPara(TipoItem $tipo): self
    {
        return match ($tipo) {
            TipoItem::Medicamento,
            TipoItem::Insumo        => self::MedicamentoYMaterialQuirurgico,
            TipoItem::Honorario     => self::ConsultaGeneral,
            TipoItem::Procedimiento => self::IntervencionQuirurgica,
            TipoItem::EstudioLaboratorio,
            TipoItem::EstudioImagen => self::RadiologiaYLaboratorio,
            TipoItem::Servicio,
            TipoItem::Estancia,
            TipoItem::Paquete => self::ServicioHospitalario,
            TipoItem::Otro    => self::SinDescuentoLegal,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::ServicioHospitalario           => 'Servicio hospitalario',
            self::MedicamentoYMaterialQuirurgico => 'Medicamento o material quirúrgico',
            self::ConsultaGeneral                => 'Consulta médica general',
            self::ConsultaEspecializada          => 'Consulta médica especializada',
            self::IntervencionQuirurgica         => 'Intervención quirúrgica',
            self::OdontologiaYOftalmologia       => 'Odontología, optometría y oftalmología',
            self::RadiologiaYLaboratorio         => 'Radiología y laboratorio',
            self::MedicinaComputarizada          => 'Medicina computarizada',
            self::SinDescuentoLegal              => 'Sin descuento legal',
        };
    }

    /**
     * Texto para la pantalla del catálogo: qué le toca y por qué.
     */
    public function explicacion(): string
    {
        if ($this === self::SinDescuentoLegal) {
            return 'No está en el Artículo 30: no lleva descuento de adulto mayor.';
        }

        $porcentaje = (int) round($this->porcentajeDeReferencia() * 100);

        return "{$porcentaje} % desde los 60 años · {$this->numeral()}";
    }

    public function color(): string
    {
        return match ($this) {
            self::SinDescuentoLegal => 'gray',
            default                 => 'info',
        };
    }
}
