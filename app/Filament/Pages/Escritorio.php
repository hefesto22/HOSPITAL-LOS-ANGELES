<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AccesosDelEscritorio;
use App\Filament\Widgets\CajaDelEscritorio;
use App\Filament\Widgets\InventarioDelEscritorio;
use App\Filament\Widgets\LoQueCargueHoy;
use App\Filament\Widgets\ResumenDelDia;
use Filament\Pages\Dashboard;
use Filament\Widgets\Widget;

/**
 * El escritorio, según quién entra (Mauricio, 21-oct-2026).
 *
 * Antes era el de Filament: «Bienvenida/o · Administrador · Salir», lo
 * mismo para todos. Ahora cada rol ve sus botones grandes y sus números
 * —caja su turno, laboratorio lo que cargó, farmacia lo que se vence,
 * dirección el día entero—. Qué sección ve cada rol está en
 * `sihla.escritorio.secciones_por_rol`; cada cuadro vuelve a pedir su
 * permiso antes de leer nada.
 *
 * La lista es explícita y en orden, y no «todos los widgets del panel»:
 * un widget nuevo de otra pantalla no aparece acá por accidente.
 *
 * Una columna: cada cuadro ocupa el ancho entero y sus números se
 * acomodan solos —cuatro por fila en la computadora, uno en el celular—.
 */
class Escritorio extends Dashboard
{
    protected static ?string $title = 'Escritorio';

    protected static ?string $navigationLabel = 'Escritorio';

    /**
     * @return list<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            AccesosDelEscritorio::class,
            ResumenDelDia::class,
            CajaDelEscritorio::class,
            LoQueCargueHoy::class,
            InventarioDelEscritorio::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
