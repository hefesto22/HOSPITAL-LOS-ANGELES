<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Carbon\CarbonInterface;

/**
 * Rango de edad del paciente para efectos de descuento legal.
 *
 * ⚠️ El descuento al adulto mayor es OBLIGACIÓN LEGAL en Honduras, no
 * política comercial. Ley Integral de Protección al Adulto Mayor y
 * Jubilados, Decreto Legislativo 199-2006. El incumplimiento se denuncia
 * ante Protección al Consumidor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CORRECCIÓN: LA CUARTA EDAD SÍ LLEGÓ A SALUD
 * ─────────────────────────────────────────────────────────────────────
 *
 * Este bloque decía lo contrario hasta hoy —«en salud hay un solo
 * umbral: 60 años», «sin cuarta edad»— y era **falso**. Estaba escrito
 * mirando un solo decreto de los dos que hay, y queda acá anotado para
 * que nadie lo vuelva a deducir de la misma mitad:
 *
 *   · **Decreto 45-2025** (vigente desde el 19-ene-2026) reformó el
 *     **Artículo 31**, que es la Sección II — Descuento al Pago de
 *     Servicios: energía, agua, telecomunicaciones, cable, bienes
 *     inmuebles y salida aeroportuaria. **No es el de salud.**
 *
 *   · **Decreto 59-2023** —no 59-2025, el número estaba mal acá hasta el
 *     20-ago-2026— reformó los **Artículos 3 y 30**. El 3 agregó la
 *     definición de «Adulto Mayor de la Cuarta Edad» (80 años o más) y el
 *     30, que sí es el de salud, le dio porcentajes propios y más altos
 *     que los de la tercera: servicios médicos, consultas, medicamentos,
 *     intervenciones quirúrgicas y estudios.
 *     Publicado en **La Gaceta el 14-feb-2024**.
 *
 * ⚠️ Los porcentajes NO se escriben acá y este comentario no los repite
 * a propósito. Viven en `descuentos_legales` y en `descuentos`, con
 * vigencia, porque son lo que cambia. Un número en un comentario es un
 * número que se despega del que el sistema cobra, y el que queda mal es
 * quien lee el comentario.
 *
 * ⚠️ Pendiente de contrastar contra La Gaceta antes de la primera
 * facturación real: la fecha exacta de publicación del 59-2025 y el
 * número de la línea de denuncias (la prensa menciona la 114 de la
 * Fiscalía Especial de Protección al Consumidor y Adulto Mayor, y el
 * resto del código dice 115).
 *
 * Detalle y fuentes: `docs/dominio-inventario-y-precios.md` §4.4 — que
 * arrastra el mismo error y hay que corregir ahí también.
 *
 * Dos reglas que este enum hace cumplir:
 *
 *  1. Las EDADES no están escritas acá. Salen de configuración
 *     (`sihla.edad.rangos_por_defecto`) porque la ley ya cambió una vez y
 *     va a volver a cambiar. Quemar 60 y 80 en el código obliga a
 *     desplegar para cumplir con una reforma.
 *
 *  2. El rango se resuelve contra la FECHA DEL SERVICIO, nunca contra
 *     "hoy" ni contra la fecha de facturación. Un paciente que cumple 60
 *     durante la hospitalización cambia de rango a mitad de la cuenta, y
 *     cada cargo tiene que llevar el rango vigente el día que se generó.
 *
 * El PORCENTAJE de descuento NO vive acá: depende de la categoría legal
 * del ítem —el numeral del Art. 30 bajo el que cae, ver
 * `CategoriaLegalDeDescuento`— y tiene vigencia, así que es dato en base
 * de datos (ADR-0003).
 */
enum RangoEdad: string
{
    case Normal = 'normal';
    case Tercera = 'tercera';
    case Cuarta = 'cuarta';

    /**
     * Resuelve el rango a partir de la edad cumplida en años.
     */
    public static function paraEdad(int $anios): self
    {
        /** @var array<string, array{desde: int, hasta: int|null}> $rangos */
        $rangos = config('sihla.edad.rangos_por_defecto', []);

        foreach ([self::Cuarta, self::Tercera] as $rango) {
            $desde = $rangos[$rango->value]['desde'] ?? null;

            if (is_int($desde) && $anios >= $desde) {
                return $rango;
            }
        }

        return self::Normal;
    }

    /**
     * Resuelve el rango de un paciente EN LA FECHA DEL SERVICIO.
     *
     * `$fechaServicio` es obligatoria a propósito: no hay un valor por
     * defecto de "hoy". Dejarlo opcional es la puerta por la que entra el
     * bug de recalcular el rango al reimprimir una factura vieja.
     */
    public static function paraPaciente(
        CarbonInterface $fechaNacimiento,
        CarbonInterface $fechaServicio,
    ): self {
        return self::paraEdad((int) $fechaNacimiento->diffInYears($fechaServicio));
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Normal  => 'Edad normal',
            self::Tercera => 'Tercera edad',
            self::Cuarta  => 'Cuarta edad',
        };
    }

    /**
     * ¿Este rango tiene derecho a descuento legal?
     *
     * Responde SÍ o NO. Cuánto es, lo resuelve el tarifario contra el
     * tipo de ítem y la fecha del servicio.
     */
    public function tieneDescuentoLegal(): bool
    {
        return $this !== self::Normal;
    }

    /**
     * Los rangos a los que este paciente tiene derecho, del más
     * específico al más general.
     *
     * ─────────────────────────────────────────────────────────────────
     * UN PACIENTE DE 80 AÑOS TAMBIÉN TIENE 60
     * ─────────────────────────────────────────────────────────────────
     *
     * La escalera existe para que a un paciente de la cuarta edad al que
     * le falte su fila le toque la de la tercera — **nunca cero**.
     * Buscar solo el rango exacto y rendirse al no encontrarlo sería
     * negarle el descuento a quien más derecho tiene, y con la lógica
     * pareciendo correcta.
     *
     * Sigue haciendo falta aunque el Decreto 59-2025 ya le haya dado
     * porcentajes propios a la cuarta edad: mientras esas filas no estén
     * cargadas —o el día que una reforma agregue un rango más— la
     * escalera es lo único que impide que un paciente de 85 años pague
     * como uno de 40.
     *
     * El resolutor consulta todos estos rangos y se queda con el mayor.
     * Además de cubrir ese caso, protege contra un dato mal cargado: la
     * ley no le puede dar menos a alguien por ser más viejo.
     *
     * @return list<self>
     */
    public function escalera(): array
    {
        return match ($this) {
            self::Normal  => [],
            self::Tercera => [self::Tercera],
            self::Cuarta  => [self::Cuarta, self::Tercera],
        };
    }

    /**
     * Todos los rangos que pueden llevar descuento.
     *
     * Lo usa el cálculo del precio de lista, que necesita el PEOR caso:
     * el descuento más alto que un ítem puede llegar a recibir de
     * cualquier edad (§4.5).
     *
     * @return list<self>
     */
    public static function conDerechoADescuento(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $rango): bool => $rango->tieneDescuentoLegal(),
        ));
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal  => 'gray',
            self::Tercera => 'info',
            self::Cuarta  => 'warning',
        };
    }
}
