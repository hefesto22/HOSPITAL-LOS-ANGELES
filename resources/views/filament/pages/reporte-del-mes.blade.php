{{--
    Reporte del mes (Mauricio, 6-nov-2026).

    Cuatro preguntas y el detalle, todas sobre las facturas emitidas en el
    período —sin cuentas abiertas—, con el mismo lenguaje visual que
    Honorarios por médico (estilos en `filament.partials.estilos-de-reporte`):

    1. ¿Cuánto se facturó? Y de eso, cuánto les tocó a los pacientes y
       cuánto quedó a crédito de los seguros. Las anuladas, aparte.
    2. ¿Cómo pagaron los pacientes esas facturas? Por forma de pago y, en
       la transferencia, por banco: los recibos de esas cuentas, del día
       que sean (11-nov-2026, ver `ResumenDeFacturacion`).
    3. ¿Qué queda? Ingresos sin ISV, menos el costo de los medicamentos,
       menos los honorarios de los médicos (Mauricio, 11-nov-2026).
    4. ¿Cuánto se le dio a cada seguro? Ningún seguro tiene plazo fijo:
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

            {{-- 2. Cómo pagaron los pacientes esas facturas --}}
            <div class="sh-panel">
                <div class="sh-cabeza">
                    <span class="sh-etiqueta">Pagado por los pacientes · {{ $this->periodo() }}</span>
                    <span class="sh-valor">{{ $lempiras($resumen['cobrado']['total']) }}</span>
                    <span class="sh-nota">
                        {{ $resumen['cobrado']['recibos'] }} {{ $resumen['cobrado']['recibos'] === 1 ? 'recibo' : 'recibos' }}
                        de las cuentas facturadas, del día que se hayan recibido
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
                        @php
                            $pendiente = \App\Domain\ValueObjects\Decimal::de($resumen['cobrado']['pendiente']);
                        @endphp
                        @if (! $pendiente->esCero())
                            <tr>
                                <td>
                                    <span class="sh-tabla-nombre">{{ $pendiente->esNegativo() ? 'Cobrado de más' : 'Falta por cobrar' }}</span>
                                    <span class="sh-tabla-detalle">A cargo de los pacientes menos lo pagado</span>
                                </td>
                                <td class="sh-num"></td>
                                <td class="sh-num sh-total rm-ojo">{{ $lempiras($resumen['cobrado']['pendiente']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 3. Lo que queda: ingresos, menos medicamentos, menos honorarios --}}
        @php
            $resultado = $resumen['resultado'];
            $medicamentos = $resultado['medicamentos'];
            $honorarios = $resultado['honorarios'];
        @endphp
        <div class="sh-panel">
            <div class="sh-cabeza">
                <span class="sh-etiqueta">Después de medicamentos y honorarios · {{ $this->periodo() }}</span>
                <span class="sh-valor">{{ $lempiras($resultado['despues_de_honorarios']) }}</span>
                <span class="sh-nota">
                    De las facturas del período. Los honorarios del personal y los salarios no se restan todavía.
                </span>
            </div>

            <table class="sh-tabla rm-cascada">
                <tbody>
                    <tr>
                        <td><span class="sh-tabla-nombre">Facturado</span></td>
                        <td class="sh-num">{{ $lempiras($resumen['facturado']['total']) }}</td>
                    </tr>
                    <tr>
                        <td>
                            <span class="sh-tabla-nombre">ISV</span>
                            <span class="sh-tabla-detalle">Se cobra para el SAR, no es del hospital</span>
                        </td>
                        <td class="sh-num rm-resta">{{ $lempiras($resultado['isv']) }}</td>
                    </tr>
                    <tr class="rm-subtotal">
                        <td><span class="sh-tabla-nombre">Ingresos</span></td>
                        <td class="sh-num sh-total">{{ $lempiras($resultado['ingresos']) }}</td>
                    </tr>
                    <tr>
                        <td>
                            <span class="sh-tabla-nombre">Costo de medicamentos y materiales</span>
                            <span class="sh-tabla-detalle">
                                {{ $medicamentos['renglones'] }} {{ $medicamentos['renglones'] === 1 ? 'renglón' : 'renglones' }}
                                · se vendieron en {{ $lempiras($medicamentos['vendido']) }}
                            </span>
                        </td>
                        <td class="sh-num rm-resta">{{ $lempiras($medicamentos['costo']) }}</td>
                    </tr>
                    <tr class="rm-subtotal">
                        <td><span class="sh-tabla-nombre">Después de medicamentos</span></td>
                        <td class="sh-num sh-total">{{ $lempiras($resultado['despues_de_medicamentos']) }}</td>
                    </tr>
                    <tr>
                        <td>
                            <span class="sh-tabla-nombre">Honorarios de médicos</span>
                            <span class="sh-tabla-detalle">
                                {{ $honorarios['renglones'] }} {{ $honorarios['renglones'] === 1 ? 'renglón' : 'renglones' }}
                                · completos al médico
                            </span>
                        </td>
                        <td class="sh-num rm-resta">{{ $lempiras($honorarios['total']) }}</td>
                    </tr>
                    <tr class="rm-subtotal rm-final">
                        <td><span class="sh-tabla-nombre">Después de honorarios</span></td>
                        <td class="sh-num sh-total">{{ $lempiras($resultado['despues_de_honorarios']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($medicamentos['sin_costo'] > 0)
            <div class="sh-aviso">
                <span class="sh-aviso-signo" aria-hidden="true">!</span>
                <span class="sh-aviso-texto">
                    <strong>{{ $medicamentos['sin_costo'] }} {{ $medicamentos['sin_costo'] === 1 ? 'renglón' : 'renglones' }} de medicamentos sin costo</strong>
                    —se vendieron en {{ $lempiras($medicamentos['vendido_sin_costo']) }}—.
                    Entraron sin costo a bodega y no restan nada: lo que queda de verdad es menos.
                </span>
            </div>
        @endif

        {{-- 4. Lo que quedó a crédito de cada seguro --}}
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

        {{-- 5. El detalle --}}
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
        .rm-ojo { color: var(--sh-acento); }
        .rm-resta { color: var(--sh-tenue); }
        .rm-resta::before { content: '− '; }
        .rm-cascada .rm-subtotal td { background: var(--sh-hover); }
        .rm-cascada .rm-final td { font-size: 1rem; }
    </style>
</x-filament-panels::page>
