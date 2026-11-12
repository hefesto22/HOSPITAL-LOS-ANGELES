<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Schemas;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;

/**
 * La ficha de la cuenta, en una sola hoja (11-nov-2026).
 *
 * Antes eran cinco pestañas —Identificación, Pagador, Dinero, Estado e
 * Historial— y lo que se viene a buscar quedaba repartido: el total en
 * una, quién paga en otra, la bitácora en la última. Mauricio: «que tenga
 * un acceso a ver bitácora y mejorar el diseño».
 *
 * Ahora, de arriba abajo y sin un clic:
 *
 *   1. La ficha (`FichaDeLaCuenta`): de quién es, en qué etapa va, quién
 *      paga, las cinco cifras —total, seguro, paciente, abonado, falta—
 *      con el exento, el gravado y el ISV separados (§8.6.1-3), y las
 *      facturas con su enlace para imprimir.
 *   2. La bitácora, abierta: quién agregó, quitó, cambió, cobró, facturó
 *      o anuló, cuándo y con qué motivo.
 *   3. Los cargos renglón por renglón, debajo (el RelationManager, que
 *      pagina y filtra: una hospitalización larga tiene cientos).
 */
final class CuentaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('ficha')
                ->hiddenLabel()
                ->view('filament.infolists.ficha-de-la-cuenta')
                ->columnSpanFull(),

            ViewEntry::make('historial')
                ->hiddenLabel()
                ->view('filament.infolists.historial-de-la-cuenta')
                ->columnSpanFull(),
        ]);
    }
}
