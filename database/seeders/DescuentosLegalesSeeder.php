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
     * Publicación de la ley en La Gaceta 31,361.
     */
    private const VIGENTE_DESDE = '2007-07-21';

    public function run(): void
    {
        foreach (CategoriaLegalDeDescuento::cases() as $categoria) {
            if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
                continue;
            }

            DescuentoLegal::query()->updateOrCreate(
                [
                    'categoria_legal' => $categoria,
                    'rango_edad'      => RangoEdad::Tercera,
                    'vigencia_desde'  => self::VIGENTE_DESDE,
                ],
                [
                    /*
                     * El porcentaje sale del enum, que es donde está
                     * escrito el numeral que lo sustenta. Repetirlo acá
                     * sería tener dos fuentes para el mismo número, y
                     * tarde o temprano una queda vieja.
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

        $this->command?->info('✓ Descuentos del Art. 30 sembrados (tercera edad, desde el 21-jul-2007).');
        $this->command?->comment('  Sin filas de cuarta edad: el Decreto 45-2025 reformó el Art. 31, no el 30.');
    }
}
