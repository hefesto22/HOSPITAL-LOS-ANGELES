{{--
    Reporte del mes (Mauricio, 6-nov-2026).

    Tres preguntas y el detalle, con el mismo lenguaje visual que
    Honorarios por médico (estilos en `filament.partials.estilos-de-reporte`):

    1. ¿Cuánto se facturó? Y de eso, cuánto les tocó a los pacientes y
       cuánto quedó a crédito de los seguros. Las anuladas, aparte.
    2. ¿Cuánto entró a caja? Por forma de pago y, en la transferencia, por
       banco. Va por la fecha del recibo, no de la factura: no tiene por
       qué coincidir con lo facturado (ver `ResumenDeFacturacion`).
    3. ¿Cuánto se le dio a cada seguro? Ningún seguro tiene plazo fijo:
       la cobranza se sigue desde la fecha de la factura.

    Los números llegan en `$resumen` desde `getViewData()`, no por un
    método público de la página.
--}}
<x-filament-panels::page>
    <div class="sh">
        <div class="sh-barra">
            <div class="sh-seg" role="group" aria-label="Período">
                @foreach ($this->periodos() as $atajo)
                    <button
                        type="button"
                        wire:key="atajo-{{ $atajo['clave'] }}"
                        wire:click="usarPeriodo('{{ $atajo['clave'] }}')"
                        title="{{ $atajo['rango'] }}"
                        @class(['sh-seg-boton', 'sh-seg-activo' => $atajo['activo']])
                        @if ($atajo['activo']) aria-pressed="true" @endif
                    >{{ $atajo['etiqueta'] }}</button>
                @endforeach
            </div>

            <div class="sh-rango">
                <input type="date" wire:model.live.change="desde" aria-label="Desde">
                <span class="sh-rango-flecha" aria-hidden="true">→</span>
                <input type="date" wire:model.live.change="hasta" aria-label="Hasta">
            </div>
        </div>

        <div class="rm-dos">
            {{-- 1. Lo facturado --}}
            <div class="sh-panel">
                <div class="sh-cabeza">
                    <span class="sh-etiqueta">Facturado · {{ $this->periodo() }}</span>
                    <span class="sh-valor">{{ $lempiras($resumen['facturado']['total']) }}</span>
                    <span class="sh-nota">
                        {{ $resumen['facturado']['cuantas'] }} {{ $resumen['facturado']['cuantas'] === 1 ? 'factura' : 'facturas' }}
                        · descuentos {{ $lempiras($resumen['facturado']['descuentos']) }}
                    </span>
                </div>

                <table class="sh-tabla">
                    <tbody>
                        <tr>
                            <td><span class="sh-tabla-nombre">A cargo de los pacientes</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($resumen['facturado']['pacientes']) }}</td>
                        </tr>
                        <tr>
                            <td><span class="sh-tabla-nombre">A crédito de los seguros</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($resumen['facturado']['seguros']) }}</td>
                        </tr>
                        <tr>
                            <td>Importe exento</td>
                            <td class="sh-num">{{ $lempiras($resumen['facturado']['exento']) }}</td>
                        </tr>
                        <tr>
                            <td>Importe gravado</td>
                            <td class="sh-num">{{ $lempiras($resumen['facturado']['gravado']) }}</td>
                        </tr>
                        <tr>
                            <td>ISV</td>
                            <td class="sh-num">{{ $lempiras($resumen['facturado']['isv']) }}</td>
                        </tr>
                        @if ($resumen['anuladas']['cuantas'] > 0)
                            <tr>
                                <td>
                                    <span class="sh-tabla-nombre">Anuladas</span>
                                    <span class="sh-tabla-detalle">
                                        {{ $resumen['anuladas']['cuantas'] }} {{ $resumen['anuladas']['cuantas'] === 1 ? 'factura' : 'facturas' }} · no suman
                                    </span>
                                </td>
                                <td class="sh-num rm-tachado">{{ $lempiras($resumen['anuladas']['total']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            {{-- 2. Lo cobrado en caja --}}
            <div class="sh-panel">
                <div class="sh-cabeza">
                    <span class="sh-etiqueta">Cobrado en caja · {{ $this->periodo() }}</span>
                    <span class="sh-valor">{{ $lempiras($resumen['cobrado']['total']) }}</span>
                    <span class="sh-nota">
                        {{ $resumen['cobrado']['recibos'] }} {{ $resumen['cobrado']['recibos'] === 1 ? 'recibo' : 'recibos' }}
                        · por la fecha del recibo
                    </span>
                </div>

                <table class="sh-tabla">
                    <thead>
                        <tr>
                            <th>Forma de pago</th>
                            <th class="sh-num">Recibos</th>
                            <th class="sh-num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resumen['cobrado']['formas'] as $forma)
                            <tr wire:key="forma-{{ $forma['forma'] }}">
                                <td>
                                    <span class="sh-tabla-nombre">{{ $forma['etiqueta'] }}</span>
                                    @if (count($forma['bancos']) > 0)
                                        <span class="sh-tabla-detalle">
                                            @foreach ($forma['bancos'] as $banco)
                                                {{ $banco['banco'] }} {{ $lempiras($banco['monto']) }}@if (! $loop->last) · @endif
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                                <td class="sh-num">{{ $forma['recibos'] }}</td>
                                <td class="sh-num sh-total">{{ $lempiras($forma['monto']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 3. Lo que quedó a crédito de cada seguro --}}
        <div class="sh-panel">
            <div class="sh-cabeza">
                <span class="sh-etiqueta">A crédito de los seguros · {{ $this->periodo() }}</span>
                <span class="sh-valor">{{ $lempiras($resumen['facturado']['seguros']) }}</span>
                <span class="sh-nota">
                    Lo que cada seguro tiene que pagar por las facturas del período. Sin plazo fijo, se le da
                    seguimiento desde la fecha de cada factura.
                </span>
            </div>

            @if (count($resumen['seguros']) > 0)
                <table class="sh-tabla">
                    <thead>
                        <tr>
                            <th>Seguro</th>
                            <th class="sh-num sh-ocultable">Facturas</th>
                            <th class="sh-num sh-ocultable">Facturado</th>
                            <th class="sh-num sh-ocultable">Copago</th>
                            <th class="sh-num">A crédito</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resumen['seguros'] as $seguro)
                            <tr wire:key="seguro-{{ $seguro['convenio_id'] }}">
                                <td>
                                    <span class="sh-tabla-nombre">{{ $seguro['nombre'] }}</span>
                                    <span class="sh-tabla-detalle">
                                        {{ $seguro['plazo'] }}
                                        <span class="sh-solo-cel">
                                            · {{ $seguro['facturas'] }} {{ $seguro['facturas'] === 1 ? 'factura' : 'facturas' }}
                                        </span>
                                    </span>
                                </td>
                                <td class="sh-num sh-ocultable">{{ $seguro['facturas'] }}</td>
                                <td class="sh-num sh-ocultable">{{ $lempiras($seguro['facturado']) }}</td>
                                <td class="sh-num sh-ocultable">{{ $lempiras($seguro['copago']) }}</td>
                                <td class="sh-num sh-total">{{ $lempiras($seguro['a_credito']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- 4. El detalle --}}
        <div class="sh-detalle">
            <div class="sh-detalle-cabeza">
                <h3 class="sh-detalle-titulo">Facturas del período</h3>
            </div>

            {{ $this->table }}
        </div>
    </div>

    @include('filament.partials.estilos-de-reporte')

    <style>
        .rm-dos { display: grid; gap: 1rem; }
        @media (min-width: 1024px) {
            .rm-dos { grid-template-columns: 1fr 1fr; align-items: start; }
        }
        .rm-tachado { text-decoration: line-through; opacity: .6; }
    </style>
</x-filament-panels::page>
