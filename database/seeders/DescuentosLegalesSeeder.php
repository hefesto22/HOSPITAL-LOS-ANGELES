<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Models\DescuentoLegal;
use Illuminate\Database\Seeder;

/**
 * Los porcentajes del Artículo 30, tal como están hoy.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTOS NÚMEROS SON LEY Y DEFINEN EL PRECIO DE TODO EL CATÁLOGO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ley Integral de Protección al Adulto Mayor y Jubilados, **Decreto
 * Legislativo 199-2006, Capítulo VI, Sección I, Artículo 30**,
 * numerales 5 a 9. Verificado contra la fuente el 18-ago-2026.
 *
 * La vigencia arranca el **21-jul-2007**, que es la publicación en La
 * Gaceta edición 31,361. No arranca "hoy" a propósito: una factura de
 * cualquier fecha posterior a esa tiene que poder resolver su descuento.
 *
 * ⚠️ **No hay filas de cuarta edad, y no es un olvido.** El Decreto
 * 45-2025 reformó el Artículo 31 —Sección II, servicios básicos:
 * energía, agua, telecomunicaciones, cable— y NO el 30. En salud el
 * único umbral es 60 años y el techo es 30 %. El paciente de 80 recibe
 * lo de la tercera edad porque el resolutor sube por la escalera de
 * rangos. El día que el Congreso lo extienda a salud, se agrega la fila
 * con su vigencia y no se toca una línea de código.
 *
 * Es idempotente sobre `(categoría, rango, vigencia_desde)`: se puede
 * volver a correr sin duplicar. Para registrar una REFORMA no se edita
 * este archivo — se le pone `vigencia_hasta` a la fila vieja y se inserta
 * la nueva. La historia es el punto.
 */
class DescuentosLegalesSeeder extends Seeder
{
    /**
     * Publicación de la ley original en La Gaceta 31,361.
     */
    private const VIGENTE_DESDE = '2007-07-21';

    /**
     * Publicación del Decreto 59-2023 en La Gaceta.
     *
     * Reformó los Artículos 3 y 30 del Decreto 199-2006: el 3 agregó la
     * definición de «Adulto Mayor de la Cuarta Edad» (80 años o más) y el
     * 30 le dio porcentajes propios, más altos que los de la tercera.
     */
    private const CUARTA_EDAD_DESDE = '2024-02-14';

    /**
     * 🔴🔴 PORCENTAJES DE LA CUARTA EDAD — PENDIENTES DE VERIFICAR CONTRA
     * EL TEXTO DE LA GACETA ANTES DE LA PRIMERA FACTURACIÓN REAL.
     *
     * Se cargaron desde prensa hondureña que coincide entre sí (Dinero HN,
     * SEDESOL vía COHEP, La Tribuna del 11-feb-2026), NO desde el texto
     * oficial: el decreto completo está tras suscripción en vLex y no se
     * pudo leer.
     *
     * Se siembran igual y no se dejan vacíos a propósito. Sin estas filas
     * la escalera de rangos hace que un paciente de 85 años reciba lo de
     * la tercera edad —25 % en medicamentos donde la ley manda 40 %— y eso
     * es incumplimiento silencioso. Un número a verificar es mejor que un
     * hueco: el hueco no avisa.
     *
     * ⚠️ Si el texto oficial dice otra cosa, se corrige en la pantalla de
     * «Descuentos de ley», sin tocar este archivo: por eso los porcentajes
     * viven en una tabla con vigencia.
     *
     * @var array<string, numeric-string>
     */
    private const CUARTA_EDAD = [
        // 30 % — la factura de servicios generales del hospital
        'servicio_hospitalario' => '0.3000',
        'consulta_general'      => '0.3000',

        // 35 % — honorarios de especialista
        'consulta_especializada' => '0.3500',

        // 40 % — lo que se compra y los procedimientos
        'medicamento_material_quirurgico' => '0.4000',
        'intervencion_quirurgica'         => '0.4000',
        'odontologia_oftalmologia'        => '0.4000',
        'radiologia_laboratorio'          => '0.4000',
        'medicina_computarizada'          => '0.4000',
    ];

    public function run(): void
    {
        foreach (CategoriaLegalDeDescuento::cases() as $categoria) {
            if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
                continue;
            }

            $this->sembrarTerceraEdad($categoria);
            $this->sembrarCuartaEdad($categoria);
        }

        $this->command?->info('✓ Descuentos del Art. 30 sembrados para tercera y cuarta edad.');
        $this->command?->warn(
            '  🔴 Los de CUARTA EDAD salen de prensa, no de La Gaceta. Verificar el texto del '
            .'Decreto 59-2023 antes de facturar; se corrigen desde la pantalla, sin tocar código.'
        );
    }

    private function sembrarTerceraEdad(CategoriaLegalDeDescuento $categoria): void
    {
        DescuentoLegal::query()->updateOrCreate(
            [
                'categoria_legal' => $categoria,
                'rango_edad'      => RangoEdad::Tercera,
                'vigencia_desde'  => self::VIGENTE_DESDE,
            ],
            [
                /*
                 * El porcentaje sale del enum, que es donde está escrito
                 * el numeral que lo sustenta. Repetirlo acá sería tener
                 * dos fuentes para el mismo número, y tarde o temprano
                 * una queda vieja.
                 */
                'porcentaje'     => number_format($categoria->porcentajeDeReferencia(), 4, '.', ''),
                'fundamento'     => $categoria->numeral().', Decreto Legislativo 199-2006',
                'exige_receta'   => $categoria->exigeReceta(),
                'vigencia_hasta' => null,
                'nota'           => $categoria->exigeReceta()
                    ? 'El Art. 34 exige receta original firmada y sellada por médico colegiado.'
                    : null,
            ],
        );
    }

    private function sembrarCuartaEdad(CategoriaLegalDeDescuento $categoria): void
    {
        $porcentaje = self::CUARTA_EDAD[$categoria->value] ?? null;

        if ($porcentaje === null) {
            return;
        }

        DescuentoLegal::query()->updateOrCreate(
            [
                'categoria_legal' => $categoria,
                'rango_edad'      => RangoEdad::Cuarta,
                'vigencia_desde'  => self::CUARTA_EDAD_DESDE,
            ],
            [
                'porcentaje' => $porcentaje,
                'fundamento' => $categoria->numeral()
                    .', reformado por el Decreto Legislativo 59-2023',
                'exige_receta'   => $categoria->exigeReceta(),
                'vigencia_hasta' => null,
                'nota'           => '🔴 PENDIENTE DE VERIFICAR contra el texto del Decreto 59-2023 '
                    .'en La Gaceta. Cargado desde prensa coincidente, no desde la fuente oficial.'
                    .($categoria->exigeReceta()
                        ? ' El Art. 34 exige receta original firmada y sellada.'
                        : ''),
            ],
        );
    }
}
