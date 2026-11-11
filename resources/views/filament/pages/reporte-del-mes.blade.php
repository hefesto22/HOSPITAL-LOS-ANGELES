{{--
    Reporte del mes (Mauricio, 6-nov-2026; 11-nov-2026).

    Todo sobre las facturas emitidas en el período —sin cuentas abiertas—,
    con el mismo lenguaje visual que Honorarios por médico (estilos en
    `filament.partials.estilos-de-reporte`):

    1. Cuatro cifras arriba: lo facturado, lo que pagaron los pacientes, lo
       que quedó a crédito de los seguros y lo que queda después de
       medicamentos y honorarios. Se leen de un vistazo; el resto es el
       porqué de cada una.
    2. Dos por dos, el detalle: lo facturado; lo que ENTRÓ de verdad
       (efectivo, tarjeta y transferencia, con el banco) separado de lo que
       falta cobrar a los seguros (Mauricio, 11-nov-2026: «tiene que haber
       separación del dinero que sí entró»); lo que queda —con la barra de
       a dónde se fueron los ingresos, y partido en lo que sale de lo que
       ya entró y lo que está por cobrar— y lo que debe cada seguro.
    3. Las facturas, una por una.

    «Imprimir» va arriba a la derecha (acción de la página): el papel es
    `reportes.mes`, con los mismos números.

    Los números llegan en `$resumen` desde `getViewData()`, no por un
    método público de la página (§9.M30). Los colores de la barra son los
    tres primeros de la paleta validada (contraste y daltonismo), con su
    versión oscura; el aqua claro queda bajo 3:1 contra el fondo, así que
    las partes llevan leyenda y la cascada de abajo dice cada monto.

    ⚠️ Lo de PHP va en bloques, nunca en una línea, y esas directivas no se
    nombran en un comentario (§9.M18).
--}}
@php
    $facturado = $resumen['facturado'];
    $anuladas = $resumen['anuladas'];
    $cobrado = $resumen['cobrado'];
    $seguros = $resumen['seguros'];
    $resultado = $resumen['resultado'];
    $medicamentos = $resultado['medicamentos'];
    $honorarios = $resultado['honorarios'];

    $dinero = $resumen['dinero'];
    $pendiente = \App\Domain\ValueObjects\Decimal::de($cobrado['pendiente']);
    $reparto = \App\Services\ResumenDeFacturacion::repartoDeLosIngresos($resultado);
    $repartoDelDinero = \App\Services\ResumenDeFacturacion::repartoDeLoFacturado($resumen);
    $leidoDelDinero = collect($repartoDelDinero)
        ->map(fn (array $parte): string => $parte['nombre'].' '.$parte['parte'].' %')
        ->implode(', ');
    $leidoDelReparto = collect($reparto)
        ->map(fn (array $parte): string => $parte['nombre'].' '.$parte['parte'].' %')
        ->implode(', ');
@endphp
<x-filament-panels::page>
    <div class="sh rm">
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

        {{-- 1. Las cuatro cifras --}}
        <div class="rm-cifras">
            <div class="rm-cifra">
                <span class="rm-cifra-etiqueta">Facturado</span>
                <span class="rm-cifra-valor">{{ $lempiras($facturado['total']) }}</span>
                <span class="rm-cifra-nota">
                    {{ $facturado['cuantas'] }} {{ $facturado['cuantas'] === 1 ? 'factura' : 'facturas' }}
                    @if ($anuladas['cuantas'] > 0)
                        · {{ $anuladas['cuantas'] }} {{ $anuladas['cuantas'] === 1 ? 'anulada' : 'anuladas' }}
                    @endif
                </span>
            </div>
            <div class="rm-cifra">
                <span class="rm-cifra-etiqueta"><i class="rm-punto rm-dinero-entro"></i>Entró</span>
                <span class="rm-cifra-valor">{{ $lempiras($dinero['entro']) }}</span>
                <span class="rm-cifra-nota">
                    efectivo, tarjeta y transferencia · {{ $cobrado['recibos'] }} {{ $cobrado['recibos'] === 1 ? 'recibo' : 'recibos' }}
                    @unless ($pendiente->esCero())
                        · <span class="rm-ojo">{{ $pendiente->esNegativo() ? 'cobrado de más' : 'falta' }} {{ $lempiras($cobrado['pendiente']) }}</span>
                    @endunless
                </span>
            </div>
            <div class="rm-cifra">
                <span class="rm-cifra-etiqueta"><i class="rm-punto rm-dinero-seguros"></i>Por cobrar a los seguros</span>
                <span class="rm-cifra-valor">{{ $lempiras($dinero['por_cobrar_seguros']) }}</span>
                <span class="rm-cifra-nota">todavía no entra · {{ count($seguros) }} {{ count($seguros) === 1 ? 'seguro' : 'seguros' }}</span>
            </div>
            <div class="rm-cifra rm-cifra-final">
                <span class="rm-cifra-etiqueta">Queda</span>
                <span class="rm-cifra-valor">{{ $lempiras($resultado['despues_de_honorarios']) }}</span>
                <span class="rm-cifra-nota">
                    {{ $lempiras($dinero['queda_de_lo_que_entro']) }} de lo que ya entró · {{ $lempiras($dinero['queda_por_cobrar']) }} por cobrar
                </span>
            </div>
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

        <div class="rm-dos">
            {{-- 2. Lo facturado --}}
            <div class="sh-panel">
                <div class="rm-cabeza">
                    <span class="rm-titulo">Lo facturado</span>
                    <span class="sh-nota">{{ $this->periodo() }} · descuentos dados {{ $lempiras($facturado['descuentos']) }}</span>
                </div>

                <table class="sh-tabla">
                    <tbody>
                        <tr>
                            <td><span class="sh-tabla-nombre">A cargo de los pacientes</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($facturado['pacientes']) }}</td>
                        </tr>
                        <tr>
                            <td><span class="sh-tabla-nombre">A crédito de los seguros</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($facturado['seguros']) }}</td>
                        </tr>
                        <tr>
                            <td class="rm-tenue">Importe exento</td>
                            <td class="sh-num rm-tenue">{{ $lempiras($facturado['exento']) }}</td>
                        </tr>
                        <tr>
                            <td class="rm-tenue">Importe gravado</td>
                            <td class="sh-num rm-tenue">{{ $lempiras($facturado['gravado']) }}</td>
                        </tr>
                        <tr>
                            <td class="rm-tenue">ISV</td>
                            <td class="sh-num rm-tenue">{{ $lempiras($facturado['isv']) }}</td>
                        </tr>
                        @if ($anuladas['cuantas'] > 0)
                            <tr>
                                <td>
                                    <span class="sh-tabla-nombre rm-tenue">Anuladas</span>
                                    <span class="sh-tabla-detalle">
                                        {{ $anuladas['cuantas'] }} {{ $anuladas['cuantas'] === 1 ? 'factura' : 'facturas' }} · no suman
                                    </span>
                                </td>
                                <td class="sh-num rm-tachado">{{ $lempiras($anuladas['total']) }}</td>
                            </tr>
                        @endif
                        <tr class="rm-subtotal">
                            <td><span class="sh-tabla-nombre">Total facturado</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($facturado['total']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- 3. Lo que entró de verdad y lo que falta cobrar --}}
            <div class="sh-panel">
                <div class="rm-cabeza">
                    <span class="rm-titulo">Lo que entró y lo que falta</span>
                    <span class="sh-nota">Lo que entró son los recibos de las cuentas facturadas, del día que se hayan recibido</span>
                </div>

                @if ($repartoDelDinero !== [])
                    <div class="rm-reparto">
                        <div class="rm-reparto-barra" role="img" aria-label="Lo facturado: {{ $leidoDelDinero }}">
                            @foreach ($repartoDelDinero as $parte)
                                @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                    <div
                                        class="rm-parte rm-dinero-{{ $parte['clave'] }}"
                                        style="flex: {{ $parte['parte'] }} 1 0"
                                        title="{{ $parte['nombre'] }} · {{ $lempiras($parte['monto']) }} · {{ $parte['parte'] }} %"
                                    >@if (\App\Domain\ValueObjects\Decimal::de($parte['parte'])->comparar('9') >= 0){{ $parte['parte'] }} %@endif</div>
                                @endunless
                            @endforeach
                        </div>
                        <div class="rm-leyenda">
                            @foreach ($repartoDelDinero as $parte)
                                @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                    <span><i class="rm-dinero-{{ $parte['clave'] }}"></i>{{ $parte['nombre'] }}</span>
                                @endunless
                            @endforeach
                        </div>
                    </div>
                @endif

                <table class="sh-tabla">
                    <thead>
                        <tr>
                            <th>Forma de pago</th>
                            <th class="sh-num">Recibos</th>
                            <th class="sh-num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cobrado['formas'] as $forma)
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
                                <td class="sh-num rm-tenue">{{ $forma['recibos'] }}</td>
                                <td class="sh-num">{{ $lempiras($forma['monto']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="rm-subtotal rm-entro">
                            <td>
                                <span class="sh-tabla-nombre">Entró</span>
                                <span class="sh-tabla-detalle">El dinero que sí llegó a caja y a los bancos</span>
                            </td>
                            <td class="sh-num">{{ $cobrado['recibos'] }}</td>
                            <td class="sh-num sh-total">{{ $lempiras($dinero['entro']) }}</td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <span class="sh-tabla-nombre">Por cobrar a los seguros</span>
                                <span class="sh-tabla-detalle">Facturado a su nombre: todavía no entra</span>
                            </td>
                            <td class="sh-num sh-total">{{ $lempiras($dinero['por_cobrar_seguros']) }}</td>
                        </tr>
                        @unless ($pendiente->esCero())
                            <tr>
                                <td colspan="2">
                                    <span class="sh-tabla-nombre rm-ojo">{{ $pendiente->esNegativo() ? 'Cobrado de más a los pacientes' : 'Por cobrar a los pacientes' }}</span>
                                    <span class="sh-tabla-detalle">Lo que les tocó menos lo que pagaron</span>
                                </td>
                                <td class="sh-num sh-total rm-ojo">{{ $lempiras($dinero['por_cobrar_pacientes']) }}</td>
                            </tr>
                        @endunless
                        <tr class="rm-subtotal">
                            <td colspan="2"><span class="sh-tabla-nombre">Facturado</span></td>
                            <td class="sh-num sh-total">{{ $lempiras($facturado['total']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- 4. Lo que queda: ingresos, menos medicamentos, menos honorarios --}}
            <div class="sh-panel">
                <div class="rm-cabeza">
                    <span class="rm-titulo">Lo que queda</span>
                    <span class="sh-nota">Los honorarios del personal y los salarios no se restan todavía</span>
                </div>

                @if ($reparto !== [])
                    <div class="rm-reparto">
                        <div
                            class="rm-reparto-barra"
                            role="img"
                            aria-label="A dónde se fueron los ingresos: {{ $leidoDelReparto }}"
                        >
                            @foreach ($reparto as $parte)
                                @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                    <div
                                        class="rm-parte rm-parte-{{ $parte['clave'] }}"
                                        style="flex: {{ $parte['parte'] }} 1 0"
                                        title="{{ $parte['nombre'] }} · {{ $lempiras($parte['monto']) }} · {{ $parte['parte'] }} %"
                                    >@if (\App\Domain\ValueObjects\Decimal::de($parte['parte'])->comparar('9') >= 0){{ $parte['parte'] }} %@endif</div>
                                @endunless
                            @endforeach
                        </div>
                        <div class="rm-leyenda">
                            @foreach ($reparto as $parte)
                                <span><i class="rm-parte-{{ $parte['clave'] }}"></i>{{ $parte['nombre'] }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <table class="sh-tabla">
                    <tbody>
                        <tr>
                            <td><span class="sh-tabla-nombre">Facturado</span></td>
                            <td class="sh-num">{{ $lempiras($facturado['total']) }}</td>
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
                        <tr class="rm-de-eso">
                            <td>
                                <span class="sh-tabla-nombre"><i class="rm-punto rm-dinero-entro"></i>De lo que ya entró</span>
                                <span class="sh-tabla-detalle">Lo que entró, menos ISV, medicamentos y honorarios</span>
                            </td>
                            <td @class(['sh-num', 'sh-total', 'rm-ojo' => \App\Domain\ValueObjects\Decimal::de($dinero['queda_de_lo_que_entro'])->esNegativo()])>{{ $lempiras($dinero['queda_de_lo_que_entro']) }}</td>
                        </tr>
                        <tr class="rm-de-eso">
                            <td>
                                <span class="sh-tabla-nombre"><i class="rm-punto rm-dinero-seguros"></i>Por cobrar</span>
                                <span class="sh-tabla-detalle">Lo que todavía deben los seguros{{ $pendiente->esCero() ? '' : ' y los pacientes' }}</span>
                            </td>
                            <td class="sh-num sh-total">{{ $lempiras($dinero['queda_por_cobrar']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- 5. Lo que quedó a crédito de cada seguro --}}
            <div class="sh-panel">
                <div class="rm-cabeza">
                    <span class="rm-titulo">A crédito de cada seguro</span>
                    <span class="sh-nota">Sin plazo fijo, se le da seguimiento desde la fecha de cada factura</span>
                </div>

                @if (count($seguros) > 0)
                    <table class="sh-tabla">
                        <thead>
                            <tr>
                                <th>Seguro</th>
                                <th class="sh-num sh-ocultable">Facturado</th>
                                <th class="sh-num sh-ocultable">Copago</th>
                                <th class="sh-num">A crédito</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($seguros as $seguro)
                                <tr wire:key="seguro-{{ $seguro['convenio_id'] }}">
                                    <td>
                                        <span class="sh-tabla-nombre">{{ $seguro['nombre'] }}</span>
                                        <span class="sh-tabla-detalle">
                                            {{ $seguro['facturas'] }} {{ $seguro['facturas'] === 1 ? 'factura' : 'facturas' }} · {{ $seguro['plazo'] }}
                                        </span>
                                    </td>
                                    <td class="sh-num sh-ocultable rm-tenue">{{ $lempiras($seguro['facturado']) }}</td>
                                    <td class="sh-num sh-ocultable rm-tenue">{{ $lempiras($seguro['copago']) }}</td>
                                    <td class="sh-num sh-total">{{ $lempiras($seguro['a_credito']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="rm-subtotal">
                                <td><span class="sh-tabla-nombre">Total a crédito</span></td>
                                <td class="sh-ocultable"></td>
                                <td class="sh-ocultable"></td>
                                <td class="sh-num sh-total">{{ $lempiras($facturado['seguros']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                @else
                    <p class="rm-vacio">Ninguna factura del período quedó a crédito de un seguro.</p>
                @endif
            </div>
        </div>

        {{-- 6. El detalle --}}
        <div class="sh-detalle">
            <div class="sh-detalle-cabeza">
                <h3 class="sh-detalle-titulo">Facturas del período</h3>
            </div>

            {{ $this->table }}
        </div>
    </div>

    @include('filament.partials.estilos-de-reporte')

    <style>
        .rm {
            --rm-medicamentos: #2a78d6;
            --rm-honorarios: #eb6834;
            --rm-queda: #1baf7a;
            --rm-entro: #1baf7a;
            --rm-seguros: #eda100;
            --rm-pacientes: #e87ba4;
        }
        .dark .rm {
            --rm-medicamentos: #3987e5;
            --rm-honorarios: #d95926;
            --rm-queda: #199e70;
            --rm-entro: #199e70;
            --rm-seguros: #c98500;
            --rm-pacientes: #d55181;
        }

        /* Las cuatro cifras */
        .rm-cifras { display: grid; gap: 1rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .rm-cifra {
            display: flex; flex-direction: column; gap: .2rem; min-width: 0;
            padding: 1.1rem 1.25rem; border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .rm-cifra-etiqueta { font-size: .8125rem; font-weight: 500; color: var(--sh-tenue); }
        .rm-cifra-valor {
            font-size: 1.625rem; font-weight: 600; line-height: 1.2; letter-spacing: -.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--sh-texto);
        }
        .rm-cifra-nota { font-size: .75rem; color: var(--sh-muy-tenue); }
        .rm-cifra-final { background: var(--sh-acento-fondo); box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-acento); }
        .rm-cifra-final .rm-cifra-etiqueta { color: var(--sh-acento); font-weight: 600; }

        /* Dos por dos */
        .rm-dos { display: grid; gap: 1rem; }
        .rm-cabeza { display: flex; flex-direction: column; gap: .15rem; padding: 1rem 1.5rem; }
        .rm-titulo { font-size: .9375rem; font-weight: 600; color: var(--sh-texto); }
        .rm-subtotal td { background: var(--sh-hover); }
        .rm-final td { box-shadow: inset 0 2px 0 var(--sh-linea); }
        .rm-final .sh-total { color: var(--sh-acento); }
        .rm-tenue { color: var(--sh-tenue) !important; }
        .rm-ojo { color: var(--sh-acento); }
        .rm-resta { color: var(--sh-tenue); }
        .rm-resta::before { content: '− '; }
        .rm-tachado { text-decoration: line-through; color: var(--sh-tenue); }
        .rm-vacio { margin: 0; padding: 1.25rem 1.5rem; border-top: 1px solid var(--sh-linea); font-size: .875rem; color: var(--sh-tenue); }

        /* A dónde se fueron los ingresos: una barra alta, un bloque por parte */
        .rm-reparto { padding: 0 1.5rem 1rem; }
        .rm-reparto-barra { display: flex; gap: 2px; height: 28px; }
        .rm-parte {
            display: flex; align-items: center; min-width: 0; padding: 0 .5rem; border-radius: 4px;
            font-size: .75rem; font-weight: 600; color: #0b0b0b; white-space: nowrap; overflow: hidden;
        }
        .rm-parte-medicamentos { background: var(--rm-medicamentos); }
        .rm-parte-honorarios { background: var(--rm-honorarios); }
        .rm-parte-queda { background: var(--rm-queda); }
        .rm-dinero-entro { background: var(--rm-entro); }
        .rm-dinero-seguros { background: var(--rm-seguros); }
        .rm-dinero-pacientes { background: var(--rm-pacientes); }
        .rm-punto { display: inline-block; width: .5rem; height: .5rem; margin-right: .4rem; border-radius: 2px; vertical-align: .05rem; }
        .rm-entro td { box-shadow: inset 3px 0 0 var(--rm-entro); }
        .rm-entro td + td { box-shadow: none; }
        .rm-de-eso td:first-child { padding-left: 2.5rem; }
        .rm-de-eso td { font-size: .8125rem; }
        .rm-leyenda { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-top: .5rem; font-size: .75rem; color: var(--sh-tenue); }
        .rm-leyenda i { display: inline-block; width: .625rem; height: .625rem; margin-right: .375rem; border-radius: 2px; }

        @media (min-width: 1024px) {
            .rm-dos { grid-template-columns: 1fr 1fr; align-items: start; }
        }
        @media (max-width: 1279px) {
            .rm-cifras { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 30rem) {
            .rm-cifras { grid-template-columns: 1fr; }
            .rm-cabeza { padding: 1rem; }
            .rm-reparto { padding: 0 1rem 1rem; }
        }
    </style>
</x-filament-panels::page>
