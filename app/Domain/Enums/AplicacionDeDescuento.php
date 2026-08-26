<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * A quién se le aplica un descuento del catálogo del hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ES LO QUE SEPARA UN DESCUENTO DE UNA NOTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un descuento con nombre y porcentaje pero sin decir a quién se le
 * aplica es un número que nadie puede cobrar solo: alguien tendría que
 * acordarse en la caja, con el paciente enfrente, a las once de la
 * noche. Este enum es lo que le permite al sistema dispararlo.
 *
 * `Manual` existe justamente para lo que NO se puede disparar solo, y es
 * honesto que exista: es preferible que «Empleado del hospital» esté en
 * la lista, con su porcentaje escrito y su vigencia, a que viva en la
 * cabeza de la cajera. Lo que no hace es aplicarse automáticamente.
 */
enum AplicacionDeDescuento: string
{
    case Tercera = 'tercera';
    case Cuarta = 'cuarta';
    case Manual = 'manual';

    /**
     * El rango de edad que dispara este descuento, o `null` si no lo
     * dispara ninguna edad.
     *
     * Los `value` coinciden con los de `RangoEdad` por comodidad, pero
     * la traducción pasa igual por acá: el día que uno de los dos enums
     * cambie un valor, esto falla al compilar en vez de resolver
     * descuentos silenciosamente en cero.
     */
    public function rango(): ?RangoEdad
    {
        return match ($this) {
            self::Tercera => RangoEdad::Tercera,
            self::Cuarta  => RangoEdad::Cuarta,
            self::Manual  => null,
        };
    }

    public static function paraRango(RangoEdad $rango): ?self
    {
        return match ($rango) {
            RangoEdad::Tercera => self::Tercera,
            RangoEdad::Cuarta  => self::Cuarta,
            RangoEdad::Normal  => null,
        };
    }

    /**
     * Los que el sistema aplica solo, para el rango de edad de un
     * paciente — subiendo la escalera, igual que el descuento de ley.
     *
     * Un paciente de 80 años también tiene 60: si le falta la fila de la
     * cuarta edad, le corresponde la de la tercera y no cero.
     *
     * @return list<self>
     */
    public static function deLaEscalera(RangoEdad $rango): array
    {
        $aplicaciones = [];

        foreach ($rango->escalera() as $escalon) {
            $aplicacion = self::paraRango($escalon);

            if ($aplicacion instanceof self) {
                $aplicaciones[] = $aplicacion;
            }
        }

        return $aplicaciones;
    }

    /**
     * Los que se disparan solos, sin importar la edad. Lo usa el cálculo
     * del precio de lista, que necesita el peor caso.
     *
     * @return list<self>
     */
    public static function automaticos(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $caso): bool => $caso->esAutomatico(),
        ));
    }

    public function esAutomatico(): bool
    {
        return $this !== self::Manual;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Tercera => 'Pacientes de la tercera edad',
            self::Cuarta  => 'Pacientes de la cuarta edad',
            self::Manual  => 'Se elige a mano al cobrar',
        };
    }

    /**
     * La etiqueta con el tramo de edad, leído de configuración:
     * «Pacientes de la tercera edad (60–79 años)».
     *
     * El tramo NO se escribe acá. La ley ya cambió las edades una vez y
     * va a volver a cambiarlas; una etiqueta con 60 quemado es cómo una
     * pantalla termina diciendo algo distinto de lo que el sistema
     * calcula.
     */
    public function etiquetaConTramo(): string
    {
        $rango = $this->rango();

        if (! $rango instanceof RangoEdad) {
            return $this->etiqueta();
        }

        /** @var array<string, array{desde?: int, hasta?: int|null}> $rangos */
        $rangos = config('sihla.edad.rangos_por_defecto', []);

        $desde = $rangos[$rango->value]['desde'] ?? null;
        $hasta = $rangos[$rango->value]['hasta'] ?? null;

        if (! is_int($desde)) {
            return $this->etiqueta();
        }

        return $this->etiqueta().(is_int($hasta)
            ? " ({$desde}–{$hasta} años)"
            : " ({$desde} años en adelante)");
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Tercera, self::Cuarta => 'Se aplica solo, por la edad que tenía el paciente EL DÍA DEL SERVICIO.',
            self::Manual                => 'Queda en la lista con su porcentaje y su vigencia, pero no se aplica solo: '
                .'alguien lo elige al cobrar. Para lo que depende de una condición que el sistema '
                .'todavía no conoce.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Tercera => 'info',
            self::Cuarta  => 'warning',
            self::Manual  => 'gray',
        };
    }
}
