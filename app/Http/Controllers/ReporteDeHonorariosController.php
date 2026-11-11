<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Pages\HonorariosPorMedico;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Sede;
use App\Models\User;
use App\Services\ReporteDeHonorarios;
use App\Support\BitacoraDeLectura;
use App\Support\ContextoSede;
use App\Support\Periodo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Los honorarios del período, para papel (Mauricio, 11-nov-2026): el
 * reporte de UN médico —lo que se le entrega al liquidarle—, el de un
 * turno o área del personal, y el general, con todos de la A a la Z y, si
 * se pide, una hoja por médico y por turno.
 *
 * Como la factura: una página que se abre en otra pestaña y se imprime
 * con Ctrl+P. Nada de PDF armado en el servidor (§13.4).
 *
 * 🔴 Con el mismo candado que la pantalla. El reporte dice cuánto cobró
 * cada médico y a qué pacientes atendió; una ruta de impresión sin
 * permiso es ese listado servido por URL a quien la adivine.
 *
 * 🔴 Cada impresión queda en la bitácora de lectura, con el período: el
 * papel lleva nombres de pacientes y es por donde sale casi todo lo que
 * se filtra (§9.E8, §9.L12).
 */
final class ReporteDeHonorariosController extends Controller
{
    public function __construct(private readonly ReporteDeHonorarios $reporte) {}

    public function general(Request $peticion): View
    {
        [$desde, $hasta] = $this->periodoDe($peticion);
        $conDetalle = $peticion->boolean('detalle');

        BitacoraDeLectura::registrar(
            recursoTipo: Medico::class,
            accion: AccionDeLectura::Imprimir,
            motivoTexto: 'REPORTE GENERAL DE HONORARIOS · '.mb_strtoupper(Periodo::etiqueta($desde, $hasta))
                .($conDetalle ? ' · CON EL DETALLE DE CADA MÉDICO' : ''),
        );

        return $this->hoja($peticion, $desde, $hasta, null, $conDetalle);
    }

    public function medico(Request $peticion, string $medico): View
    {
        [$desde, $hasta] = $this->periodoDe($peticion);

        /*
         * `withTrashed()`: al médico que se dio de baja también se le
         * liquida lo que facturó antes de irse.
         */
        $ficha = Medico::withTrashed()->find((int) $medico);

        abort_unless($ficha instanceof Medico, 404);

        BitacoraDeLectura::registrar(
            recursoTipo: Medico::class,
            recursoId: (int) $ficha->getKey(),
            accion: AccionDeLectura::Imprimir,
            motivoTexto: 'REPORTE DE HONORARIOS · '.mb_strtoupper(Periodo::etiqueta($desde, $hasta)),
        );

        return $this->hoja($peticion, $desde, $hasta, $ficha, false);
    }

    /**
     * El de un turno o área del personal: los honorarios de enfermería, el
     * circulante, el instrumentista (Mauricio, 11-nov-2026: «en el reporte
     * pertenece a X turno, sin enfermeras»). `{item}` es el ítem del
     * honorario, que es el que dice el turno.
     */
    public function turno(Request $peticion, string $item): View
    {
        [$desde, $hasta] = $this->periodoDe($peticion);

        $turno = Item::withTrashed()->find((int) $item);

        abort_unless($turno instanceof Item && $turno->esHonorarioDelPersonal(), 404);

        BitacoraDeLectura::registrar(
            recursoTipo: Item::class,
            recursoId: (int) $turno->getKey(),
            accion: AccionDeLectura::Imprimir,
            motivoTexto: 'REPORTE DE HONORARIOS DEL TURNO · '.ReporteDeHonorarios::nombreDelGrupo($turno->nombre)
                .' · '.mb_strtoupper(Periodo::etiqueta($desde, $hasta)),
        );

        return $this->hoja($peticion, $desde, $hasta, null, false, $turno);
    }

    /**
     * Las dos fechas de la URL, con la misma regla que la pantalla.
     *
     * ⚠️ El permiso se mira ACÁ, antes de leer nada: las dos rutas pasan
     * por este método primero.
     *
     * @return array{0: string, 1: string}
     */
    private function periodoDe(Request $peticion): array
    {
        abort_unless(Gate::allows(HonorariosPorMedico::PERMISO), 403);

        [$desde, $hasta] = Periodo::de($peticion->query('desde'), $peticion->query('hasta'));

        abort_if(
            Periodo::dias($desde, $hasta) > ReporteDeHonorarios::DIAS_MAXIMOS,
            422,
            'El reporte cubre como máximo un año. Elegí un período más corto.',
        );

        return [$desde, $hasta];
    }

    private function hoja(
        Request $peticion,
        string $desde,
        string $hasta,
        ?Medico $medico,
        bool $conDetalle,
        ?Item $turno = null,
    ): View {
        $usuario = $peticion->user();
        $deUnaHoja = $medico instanceof Medico || $turno instanceof Item;

        return view('honorarios.imprimir', [
            'reporte'   => $this->reporte->paraImprimir($desde, $hasta, $medico, $conDetalle, $turno),
            'sede'      => $this->sede(),
            'deUnaHoja' => $deUnaHoja,
            'titulo'    => match (true) {
                $medico instanceof Medico => 'Reporte del médico',
                $turno instanceof Item    => 'Reporte del turno o área',
                default                   => 'Reporte general',
            },
            'conDetalle' => $conDetalle,

            /*
             * Adentro del modal de la pantalla (11-nov-2026): la hoja sale
             * sin su barra de botones, que los pone el modal.
             */
            'incrustada' => $peticion->boolean('incrustada'),
            'impresoPor' => $usuario instanceof User ? (string) $usuario->name : '',
            'impresoEl'  => now()->format('d/m/Y H:i'),
            'volver'     => HonorariosPorMedico::getUrl(array_filter([
                'desde'  => $desde,
                'hasta'  => $hasta,
                'medico' => $medico instanceof Medico ? (string) $medico->getKey() : null,
                'turno'  => $turno instanceof Item ? (string) $turno->getKey() : null,
            ])),

            /*
             * El general se puede pedir con o sin detalle desde la misma
             * hoja: la barra de arriba lleva el enlace al otro.
             */
            'otraVersion' => $deUnaHoja
                ? null
                : route('honorarios.reporte', array_filter([
                    'desde'   => $desde,
                    'hasta'   => $hasta,
                    'detalle' => $conDetalle ? null : '1',
                ])),
        ]);
    }

    /**
     * El membrete: la sede en la que está parado el usuario. Dirección
     * cruza sedes y puede no tener una propia; con una sola sede vigente
     * `ContextoSede` ya devuelve esa, y si no hay ninguna el papel sale
     * con el nombre de la aplicación.
     */
    private function sede(): ?Sede
    {
        $id = ContextoSede::actualId();

        return $id === null ? Sede::query()->orderBy('id')->first() : Sede::query()->find($id);
    }
}
