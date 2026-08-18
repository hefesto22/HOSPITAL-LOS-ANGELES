<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué pasa con un ítem cuando se consume (ADR-0003).
 *
 * Sin este campo pasa una de dos cosas, las dos caras:
 *
 *   - se factura guante por guante y gasa por gasa, y la cuenta del
 *     paciente se vuelve ilegible y agresiva; o
 *   - se regala una prótesis de L 70,000 porque nadie la cargó.
 *
 * La diferencia entre "incluido en la tarifa" y "gasto del servicio" no
 * es cosmética: el primero SÍ se descuenta del inventario y su costo se
 * imputa al procedimiento; el segundo se descuenta y se imputa al centro
 * de costo del área. Los dos salen del kardex; ninguno le llega al
 * paciente como línea.
 */
enum PoliticaCargo: string
{
    case Cobrable = 'cobrable';
    case IncluidoEnTarifa = 'incluido_en_tarifa';
    case GastoDelServicio = 'gasto_del_servicio';

    /**
     * ¿Aparece como línea en la cuenta del paciente?
     */
    public function generaCargoAlPaciente(): bool
    {
        return $this === self::Cobrable;
    }

    /**
     * ¿Descuenta existencia? Los tres lo hacen si el ítem es físico.
     *
     * Que no se le cobre al paciente no significa que no salga de bodega.
     * Confundir esto es exactamente cómo un inventario "cuadra" mientras
     * el faltante crece.
     */
    public function descuentaExistencia(): bool
    {
        return true;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cobrable         => 'Se cobra al paciente',
            self::IncluidoEnTarifa => 'Incluido en la tarifa del procedimiento',
            self::GastoDelServicio => 'Gasto del servicio',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cobrable         => 'success',
            self::IncluidoEnTarifa => 'info',
            self::GastoDelServicio => 'gray',
        };
    }
}
