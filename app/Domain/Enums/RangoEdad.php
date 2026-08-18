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
 * EN SALUD HAY UN SOLO UMBRAL: 60 AÑOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Corregido el 18-ago-2026 contra la fuente primaria. El Decreto 45-2025
 * (La Gaceta 37,047, 19-ene-2026) reformó el **Artículo 31**, que es la
 * Sección II — Descuento al Pago de Servicios: energía, agua,
 * telecomunicaciones, cable, bienes inmuebles y salida aeroportuaria. Ahí
 * es donde vive la cuarta edad con 35 %.
 *
 * El **Artículo 30**, que es el de salud, quedó intacto: 25 % en
 * hospitales y clínicas privadas, medicamentos y consulta general; 30 %
 * en consulta especializada, cirugía, odontología, oftalmología,
 * radiología, laboratorio y medicina computarizada. **Sin cuarta edad.**
 *
 * `Cuarta` se conserva de todos modos, y es deliberado: el día que el
 * Congreso la extienda a servicios médicos —que es lo que la prensa ya
 * daba por hecho en enero— tiene que ser una fila de configuración, no un
 * despliegue.
 *
 * Detalle y fuentes: `docs/dominio-inventario-y-precios.md` §4.4.
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
     * Hoy la ley no le da nada específico a la cuarta edad en salud, así
     * que un paciente de 80 tiene que recibir lo de la tercera — **no
     * cero**. Buscar solo el rango exacto y rendirse al no encontrarlo
     * sería negarle el descuento a quien más derecho tiene, y con la
     * lógica pareciendo correcta.
     *
     * El resolutor consulta todos estos rangos y se queda con el mayor.
     * Además de cubrir el caso de hoy, protege contra un dato mal
     * cargado: la ley no le puede dar menos a alguien por ser más viejo.
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
