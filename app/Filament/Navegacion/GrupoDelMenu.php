<?php

declare(strict_types=1);

namespace App\Filament\Navegacion;

use Filament\Navigation\NavigationGroup;

/**
 * Los grupos del menú lateral, en el orden en que se pintan.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA FUENTE (Mauricio, 11-nov-2026: «más práctico, compacto,
 * entendible, y sobre todo identificar cosas rápido»)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Antes cada Resource escribía el nombre de su grupo a mano: un acento
 * de más y la opción se iba a un grupo nuevo, al final del menú, donde
 * nadie mira. Ahora el nombre sale de acá y el panel registra los grupos
 * desde acá, en este orden: el de la frecuencia con que se abre cada
 * cosa —la ventanilla primero, la configuración al final—.
 *
 * Cada grupo tiene su color: el cuadrito junto al título y los íconos
 * de sus opciones. Con el color se encuentra la zona del menú antes de
 * leer; el nombre sigue estando, así que nadie depende solo del color.
 * Los ocho son la paleta categórica de referencia, oscurecidos lo justo
 * para que el ícono se lea (≥ 3:1) sobre el fondo del menú, y validados
 * en orden para daltonismo en los dos modos.
 *
 * ⚠️ El texto (`value`) es lo que ve la gente y es también la llave con
 * la que Filament junta las opciones: cambiarlo mueve todo el grupo a la
 * vez, que es justo lo que se busca.
 */
enum GrupoDelMenu: string
{
    case CuentasYCaja = 'Cuentas y caja';
    case Pacientes = 'Pacientes';
    case FarmaciaEInventario = 'Farmacia e inventario';
    case Reportes = 'Reportes';
    case Catalogo = 'Catálogo';
    case PreciosYSeguros = 'Precios y seguros';
    case Hospital = 'Hospital';
    case Seguridad = 'Seguridad';

    /**
     * El color sobre el fondo claro del menú.
     */
    public function colorClaro(): string
    {
        return match ($this) {
            self::CuentasYCaja        => '#2a78d6',
            self::Pacientes           => '#df5d27',
            self::FarmaciaEInventario => '#009965',
            self::Reportes            => '#be7500',
            self::Catalogo            => '#cc628c',
            self::PreciosYSeguros     => '#008300',
            self::Hospital            => '#6250d6',
            self::Seguridad           => '#e34948',
        };
    }

    /**
     * El mismo tono, escalonado para el modo oscuro.
     */
    public function colorOscuro(): string
    {
        return match ($this) {
            self::CuentasYCaja        => '#3987e5',
            self::Pacientes           => '#d95926',
            self::FarmaciaEInventario => '#199e70',
            self::Reportes            => '#c98500',
            self::Catalogo            => '#d55181',
            self::PreciosYSeguros     => '#1f911c',
            self::Hospital            => '#9085e9',
            self::Seguridad           => '#e66767',
        };
    }

    /**
     * Lo que se toca una vez y se deja quieto empieza recogido.
     *
     * Solo la primera vez: después cada quien abre y cierra los grupos a
     * su gusto y el navegador se acuerda (ver `MenuLateral::VERSION`).
     */
    public function empiezaRecogido(): bool
    {
        return match ($this) {
            self::Hospital, self::Seguridad => true,
            default                         => false,
        };
    }

    /**
     * Los grupos tal como los registra el panel.
     *
     * @return list<NavigationGroup>
     */
    public static function grupos(): array
    {
        return array_map(
            fn (self $grupo): NavigationGroup => NavigationGroup::make($grupo->value)
                ->collapsed($grupo->empiezaRecogido())
                ->extraSidebarAttributes([
                    'data-grupo' => $grupo->name,
                    'style'      => "--grupo-claro:{$grupo->colorClaro()};--grupo-oscuro:{$grupo->colorOscuro()}",
                ]),
            self::cases(),
        );
    }
}
