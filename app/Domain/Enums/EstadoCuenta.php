<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * El ciclo de vida de la cuenta.
 *
 *   abierta → congelada → cerrada
 *
 * `congelada` es el cutoff del §8.6.3: el alta médica congela la cuenta
 * y abre una ventana de N minutos para que cada servicio suba lo suyo.
 * Durante ese rato la cuenta ya no admite cargos rutinarios pero SÍ
 * admite los tardíos — que es exactamente la diferencia entre un
 * sistema que ordena el egreso y uno que obliga a mentir.
 */
enum EstadoCuenta: string
{
    case Abierta = 'abierta';
    case Congelada = 'congelada';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierta   => 'Abierta',
            self::Congelada => 'Congelada (cutoff de egreso)',
            self::Cerrada   => 'Cerrada',
            self::Anulada   => 'Anulada',
        };
    }

    public function admiteCargos(): bool
    {
        return $this === self::Abierta || $this === self::Congelada;
    }

    /**
     * ¿Un cargo nuevo nace marcado como tardío?
     *
     * En la cuenta congelada, sí: el hecho se registra igual —siempre—
     * pero queda señalado para que la liquidación sepa que llegó después
     * del corte y para que el reporte de demora del egreso lo cuente.
     */
    public function marcaCargosComoTardios(): bool
    {
        return $this === self::Congelada;
    }

    public function estaViva(): bool
    {
        return $this === self::Abierta || $this === self::Congelada;
    }

    /**
     * @return list<string>
     */
    public static function valoresVivos(): array
    {
        return [self::Abierta->value, self::Congelada->value];
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierta   => 'success',
            self::Congelada => 'warning',
            self::Cerrada   => 'gray',
            self::Anulada   => 'danger',
        };
    }
}
