{{--
    La hoja de UN médico: lo que le toca en el período, de qué pagadores,
    renglón por renglón, y la firma de recibido.

    Es la que se le entrega al liquidarle. Por eso lleva la línea de
    «Recibí conforme» con su nombre: el papel firmado es la constancia de
    que se le entregó su liquidación (Mauricio, 11-nov-2026).

    La de un turno o área del personal (Mauricio, 11-nov-2026) es la misma
    hoja: dice el turno en vez del médico, no repite el honorario en cada
    renglón —es siempre el mismo ítem— y el «Recibí conforme» queda en
    blanco para quien lo recibe por el turno.

    Recibe: $hoja (ver `ReporteDeHonorarios::paraImprimir()`), $reporte,
    y lo que pide el membrete.
--}}
@include('honorarios.partials.membrete', ['titulo' => $hoja['tipo'] === 'personal' ? 'Reporte del turno o área' : 'Reporte del médico'])

<section class="medico">
    <div>
        <span class="rotulo">{{ $hoja['tipo'] === 'personal' ? 'Turno o área · enfermería y personal' : 'Médico' }}</span>
        <div class="medico-nombre">{{ $hoja['nombre'] }}</div>
        <div class="medico-datos">
            @if ($hoja['especialidad'] !== '')
                <span>{{ $hoja['especialidad'] }}</span>
            @endif
            @if ($hoja['colegiacion'] !== '')
                <span>Colegiación <b>{{ $hoja['colegiacion'] }}</b></span>
            @endif
        </div>
    </div>

    <div class="medico-total">
        <span class="rotulo">{{ $hoja['tipo'] === 'personal' ? 'Le corresponde al turno' : 'Le corresponde' }} · {{ $reporte['etiqueta'] }}</span>
        <div class="medico-monto">{{ $hoja['total'] }}</div>
        <div class="medico-cuantos">
            {{ $hoja['cuantos'] }} {{ $hoja['cuantos'] === 1 ? 'honorario facturado' : 'honorarios facturados' }}
        </div>
    </div>
</section>

@if ($hoja['renglones'] === [])
    <p class="vacio">Sin honorarios facturados en el período.</p>
@else
    @if (count($hoja['pagadores']) > 1)
        <table class="tabla pagadores">
            <colgroup>
                <col>
                <col style="width: 24mm">
                <col style="width: 32mm">
            </colgroup>
            <thead>
                <tr>
                    <th>Por pagador</th>
                    <th class="num">Honorarios</th>
                    <th class="num">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($hoja['pagadores'] as $pagador)
                    <tr>
                        <td>{{ $pagador['nombre'] }}</td>
                        <td class="num">{{ $pagador['cuantos'] }}</td>
                        <td class="num">{{ $pagador['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="tabla detalle">
        <colgroup>
            <col style="width: 7mm">
            <col style="width: 17mm">
            <col style="width: 33mm">
            <col>
            @if ($hoja['tipo'] !== 'personal')
                <col>
            @endif
            <col style="width: 26mm">
            <col style="width: 25mm">
        </colgroup>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Fecha</th>
                <th>Factura</th>
                <th>Paciente</th>
                @if ($hoja['tipo'] !== 'personal')
                    <th>Honorario</th>
                @endif
                <th>Pagador</th>
                <th class="num">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($hoja['renglones'] as $renglon)
                <tr @class(['par' => $loop->even])>
                    <td class="num tenue">{{ $loop->iteration }}</td>
                    <td class="num-izq">{{ $renglon['fecha'] }}</td>
                    <td class="num-izq">{{ $renglon['factura'] }}</td>
                    <td>
                        {{ $renglon['paciente'] }}
                        @if ($renglon['cuenta'] !== '')
                            <span class="debajo">{{ $renglon['cuenta'] }}</span>
                        @endif
                    </td>
                    @if ($hoja['tipo'] !== 'personal')
                        <td>
                            {{ $renglon['honorario'] }}
                            @if ($renglon['cantidad'] !== '1')
                                <span class="debajo">× {{ $renglon['cantidad'] }}</span>
                            @endif
                        </td>
                    @endif
                    <td>
                        {{ $renglon['pagador'] }}
                        @if ($hoja['tipo'] === 'personal' && $renglon['cantidad'] !== '1')
                            <span class="debajo">× {{ $renglon['cantidad'] }}</span>
                        @endif
                    </td>
                    <td class="num fuerte">{{ $renglon['importe'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="{{ $hoja['tipo'] === 'personal' ? 5 : 6 }}">Total · {{ $hoja['cuantos'] }} {{ $hoja['cuantos'] === 1 ? 'honorario' : 'honorarios' }}</td>
                <td class="num">{{ $hoja['total'] }}</td>
            </tr>
        </tfoot>
    </table>
@endif

<div class="cierre">
    <p class="nota">
        Son los honorarios de las facturas emitidas en el período, por la fecha de la factura. Una factura
        anulada no cuenta. Es lo facturado: no descuenta nada de lo que el hospital retenga ni dice qué ya
        pagó el seguro.
    </p>

    <div class="firmas">
        <div>
            <div class="firma"></div>
            <div class="firma-rotulo">
                {{ $hoja['tipo'] === 'personal' ? 'Recibí conforme por el turno · nombre y firma' : 'Recibí conforme · '.$hoja['nombre'] }}
            </div>
        </div>
        <div>
            <div class="firma"></div>
            <div class="firma-rotulo">Entregado por · nombre y firma</div>
        </div>
    </div>
</div>
