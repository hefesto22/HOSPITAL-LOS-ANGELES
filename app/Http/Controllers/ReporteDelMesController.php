<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Pages\ReporteDelMes;
use App\Models\Factura;
use App\Models\Sede;
use App\Models\User;
use App\Services\ResumenDeFacturacion;
use App\Support\BitacoraDeLectura;
use App\Support\ContextoSede;
use App\Support\Periodo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * El reporte del mes, para papel (Mauricio, 11-nov-2026: «que se pueda
 * imprimir»). Lo mismo que la pantalla —lo facturado, cómo pagaron los
 * pacientes, lo que queda después de medicamentos y honorarios y lo que
 * deben los seguros— y, si se pide, las facturas una por una.
 *
 * Como la factura y los honorarios: una página que se imprime con Ctrl+P.
 * Nada de PDF armado en el servidor (§13.4).
 *
 * 🔴 Con el mismo candado que la pantalla: dice cuánto vende el hospital y
 * cuánto le queda, y con las facturas lleva nombres de pacientes. Por eso
 * también cada impresión queda en la bitácora de lectura (§9.E8, §9.L12).
 */
final class ReporteDelMesController extends Controller
{
    public function __construct(private readonly ResumenDeFacturacion $resumen) {}

    public function __invoke(Request $peticion): View
    {
        /* El permiso, ANTES de leer nada. */
        abort_unless(Gate::allows(ReporteDelMes::PERMISO), 403);

        [$desde, $hasta] = Periodo::de($peticion->query('desde'), $peticion->query('hasta'));

        abort_if(
            Periodo::dias($desde, $hasta) > ResumenDeFacturacion::DIAS_MAXIMOS,
            422,
            'El reporte cubre como máximo un año. Elegí un período más corto.',
        );

        $conFacturas = $peticion->boolean('facturas');
        $etiqueta = Periodo::etiqueta($desde, $hasta);
        $usuario = $peticion->user();
        $sedeId = ResumenDeFacturacion::sedeDe($usuario);

        BitacoraDeLectura::registrar(
            recursoTipo: Factura::class,
            accion: AccionDeLectura::Imprimir,
            motivoTexto: 'REPORTE DEL MES · '.mb_strtoupper($etiqueta).($conFacturas ? ' · CON LAS FACTURAS' : ''),
        );

        return view('reportes.mes', [
            'resumen'     => $this->resumen->delPeriodo($desde, $hasta, $sedeId),
            'facturas'    => $conFacturas ? $this->resumen->facturasDelPeriodo($desde, $hasta, $sedeId) : null,
            'etiqueta'    => $etiqueta,
            'sede'        => $this->membrete($sedeId),
            'incrustada'  => $peticion->boolean('incrustada'),
            'impresoPor'  => $usuario instanceof User ? (string) $usuario->name : '',
            'impresoEl'   => now()->format('d/m/Y H:i'),
            'volver'      => ReporteDelMes::getUrl(['desde' => $desde, 'hasta' => $hasta]),
            'otraVersion' => route('reportes.mes', array_filter([
                'desde'    => $desde,
                'hasta'    => $hasta,
                'facturas' => $conFacturas ? null : '1',
            ])),
        ]);
    }

    /**
     * El membrete: la sede de los números o, para quien cruza sedes, la
     * que tiene en pantalla. Sin ninguna, la primera; y si no hay, el papel
     * sale con el nombre de la aplicación.
     */
    private function membrete(?int $sedeId): ?Sede
    {
        $id = $sedeId ?? ContextoSede::actualId();

        return $id === null ? Sede::query()->orderBy('id')->first() : Sede::query()->find($id);
    }
}
