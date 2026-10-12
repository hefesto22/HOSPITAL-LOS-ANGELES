{{--
    El desglose del paquete presupuestado (ADR-0009).

    ─────────────────────────────────────────────────────────────────────
    QUÉ RESUELVE ESTO
    ─────────────────────────────────────────────────────────────────────

    La cuenta muestra «APENDICECTOMIA · L 22,235.60» y eso es lo que la
    familia paga. Pero el turno necesita saber otra cosa: qué de todo eso
    YA se le dio al paciente y qué falta.

    Cada renglón se marca solo a medida que se consume. Lo que está
    dentro del paquete no se le vuelve a cobrar; lo que aparezca y no
    esté acá se cobra aparte y avisa.

    ⚠️ Lo consumido NO es una columna: sale de una consulta agrupada
    sobre `cargos`. Un saldo que se edita es un saldo que en tres días
    miente (§9.G1).

    ─────────────────────────────────────────────────────────────────────
    CÓMO SE LEE (12-oct-2026)
    ─────────────────────────────────────────────────────────────────────

    Un panel aparte, metido debajo de la cirugía y con su propia
    cabecera: estado, concepto, cantidad, importe. El estado va en
    palabras y con color —incluido, entregado, parcial, pendiente— y no
    en símbolos sueltos que había que adivinar. Y el avance de farmacia
    con su barrita, en el botón mismo, para saber cómo va sin abrirlo.
--}}
@php($desglose = $presupuesto->desglose())
@php($abierto = $this->paqueteAbierto)
@php($deFarmacia = $desglose->where('estado', '!=', 'incluido'))
@php($entregados = $deFarmacia->where('estado', 'completo')->count())
@php($avance = $deFarmacia->isEmpty() ? 100 : (int) round($entregados * 100 / $deFarmacia->count()))

<div class="sihla-desglose {{ $abierto ? 'sihla-desglose-abierto' : '' }}" wire:key="desglose-{{ $presupuesto->id }}">
    {{--
        🔴 NO ES UN `<details>`, Y ESO ES A PROPÓSITO.

        El desplegado es estado de Livewire. Con `<details>` —probado
        solo, con entangle y con `wire:key`— el nodo se recrea en cada
        actualización y la lista se cierra sola: despachar cinco
        renglones obligaba a reabrirla cinco veces.

        Cerrado dice lo que hace el botón —«Desglosar»—; abierto, lo que
        se está viendo —«Desglose»—. «Qué incluye» describía la lista,
        no el clic, y quien nunca lo había abierto no sabía que ahí
        estaba el detalle de la cirugía con sus precios.
    --}}
    <button
        type="button"
        class="sihla-desglose-toggle"
        wire:click="alternarPaquete"
        aria-expanded="{{ $abierto ? 'true' : 'false' }}"
    >
        <svg class="sihla-desglose-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
        </svg>

        <span class="sihla-desglose-toggle-texto">{{ $abierto ? 'Desglose' : 'Desglosar' }}</span>
        <span class="sihla-desglose-cuenta">{{ $desglose->count() }} {{ $desglose->count() === 1 ? 'renglón' : 'renglones' }}</span>

        {{--
            El avance cuenta SOLO lo que sale de farmacia. Decir «0 de
            21» cuando dieciséis de esos veintiuno no se despachan nunca
            haría parecer atrasado un caso que va al día.
        --}}
        <span class="sihla-desglose-avance">
            @if ($deFarmacia->isEmpty())
                nada que despachar de farmacia
            @else
                <span class="sihla-desglose-barra"><i style="width: {{ $avance }}%"></i></span>
                {{ $entregados }} de {{ $deFarmacia->count() }} despachados
            @endif
        </span>
    </button>

    @if ($abierto)
        <div class="sihla-desglose-panel">
            <table class="sihla-desglose-tabla">
                <thead>
                    <tr>
                        <th class="sihla-desglose-col-estado">Estado</th>
                        <th>Concepto</th>
                        <th class="sihla-num">Cantidad</th>
                        <th class="sihla-num">Importe</th>
                        <th class="sihla-desglose-acc"></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($desglose as $fila)
                        @php($linea = $fila['linea'])
                        @php($pedida = rtrim(rtrim((string) $linea->cantidad, '0'), '.'))
                        @php($unidad = $linea->unidad?->simbolo)
                        @php($unitario = number_format((float) $linea->precio_unitario, 2))

                        <tr wire:key="renglon-{{ $linea->id }}">
                            <td class="sihla-desglose-col-estado">
                                @if ($fila['estado'] === 'incluido')
                                    <span class="sihla-estado sihla-estado-incluido" title="No sale de farmacia: va incluido en el paquete">Incluido</span>
                                @elseif ($fila['estado'] === 'completo')
                                    <span class="sihla-estado sihla-estado-completo" title="Despachado completo">Entregado</span>
                                @elseif ($fila['estado'] === 'parcial')
                                    <span class="sihla-estado sihla-estado-parcial" title="Despachado en parte">Parcial</span>
                                @else
                                    <span class="sihla-estado sihla-estado-pendiente" title="Falta despacharlo de farmacia">Pendiente</span>
                                @endif
                            </td>

                            <td class="sihla-desglose-concepto">
                                {{ $linea->texto }}
                                @if ($linea->opcional)
                                    <span class="sihla-desglose-opcional">opcional</span>
                                @endif
                            </td>

                            {{--
                                🔴 LA CANTIDAD SE MUESTRA SIEMPRE.

                                Que sean TRES días de habitación y no uno es
                                el dato que el turno necesita, esté o no
                                marcado. En lo que sale de farmacia va
                                «cuánto se dio de cuánto».
                            --}}
                            <td class="sihla-num sihla-desglose-cant">
                                @if ($fila['estado'] === 'incluido')
                                    {{ $pedida }}
                                @else
                                    <strong>{{ rtrim(rtrim($fila['consumida']->redondeado(4), '0'), '.') }}</strong>
                                    <span class="sihla-desglose-de">de</span>
                                    {{ $pedida }}
                                @endif
                                @if ($unidad)
                                    <span class="sihla-desglose-unidad">{{ $unidad }}</span>
                                @endif
                            </td>

                            {{--
                                🔴 CUÁNTO CUESTA CADA RENGLÓN, NO SOLO CUÁNTOS.

                                La familia pregunta «¿y por qué son catorce
                                mil?». El precio es el que quedó CONGELADO en
                                el presupuesto (ADR-0008): lo que se cotizó,
                                no lo que costaría hoy. Va el `total` de la
                                línea porque es el único número que suma
                                exacto lo que dice el renglón de la cuenta;
                                el unitario, al pasar el mouse.
                            --}}
                            <td
                                class="sihla-num sihla-desglose-precio"
                                title="{{ $pedida }}{{ $unidad ? ' '.$unidad : '' }} × L {{ $unitario }} c/u"
                            >L {{ number_format((float) $linea->total, 2) }}</td>

                            {{--
                                🔴 EL BOTÓN SOLO EN LO QUE SALE DE FARMACIA.

                                Un honorario no se «entrega»; ponerle botón
                                sería invitar a marcar algo que nadie
                                despachó. Y se pregunta CUÁNTO, siempre: si
                                se dan ocho y el paciente pide el alta, las
                                otras dos nunca salieron del estante.
                            --}}
                            <td class="sihla-desglose-acc">
                                @if ($fila['estado'] !== 'incluido' && $fila['estado'] !== 'completo')
                                    @if ($this->lineaAEntregar === $linea->id)
                                        <span class="sihla-desglose-pregunta">
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                wire:model="cantidadAEntregar"
                                                wire:keydown.enter="entregarDelPaquete({{ $linea->id }})"
                                                class="sihla-desglose-campo"
                                                aria-label="¿Cuánto se le entrega?"
                                                autofocus
                                            >
                                            <button
                                                type="button"
                                                wire:click="entregarDelPaquete({{ $linea->id }})"
                                                wire:loading.attr="disabled"
                                                class="sihla-btn-dar"
                                            >Dar</button>
                                            <button
                                                type="button"
                                                wire:click="cancelarEntrega"
                                                class="sihla-btn-icono"
                                                title="Cancelar"
                                            >&times;</button>
                                        </span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="pedirLaCantidad({{ $linea->id }})"
                                            class="sihla-btn-entregar"
                                        >Entregar</button>
                                    @endif
                                @endif

                                {{--
                                    Se equivocó: puso 8 y solo dio 6. Deshace
                                    la última entrega —reversa al kardex—
                                    para volver a darla bien. Un cargo no se
                                    edita (§9.0.3).
                                --}}
                                @if ($fila['estado'] !== 'incluido' && ! $fila['consumida']->esCero())
                                    <button
                                        type="button"
                                        wire:click="deshacerEntrega({{ $linea->id }})"
                                        wire:confirm="¿Deshacer la última entrega de este renglón? El medicamento vuelve al inventario."
                                        class="sihla-btn-icono"
                                        title="Deshacer la última entrega"
                                    >&#8634;</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                {{--
                    🔴 LA SUMA VA IMPRESA, Y TIENE QUE CUADRAR.

                    Es el mismo número que el renglón de la cuenta —el
                    paquete se asienta por el total del presupuesto—. Si
                    algún día no cuadra, el presupuesto cambió después de
                    asentarse y eso hay que verlo, no esconderlo.

                    El descuento de farmacia NO se resta acá: no es del
                    presupuesto, se rebaja del renglón de la cirugía y se
                    explica debajo.
                --}}
                <tfoot>
                    <tr class="sihla-desglose-suma">
                        <td></td>
                        <td>Suma del paquete</td>
                        <td></td>
                        <td class="sihla-num sihla-desglose-precio">L {{ number_format((float) $presupuesto->total, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            @php($rebaja = (float) ($descuentoDeFarmacia ?? 0))

            <div class="sihla-desglose-notas">
                <div class="sihla-desglose-notas-texto">
                    @if ($rebaja > 0)
                        <p class="sihla-desglose-rebaja">
                            Descuento del hospital sobre lo entregado de farmacia:
                            <strong>&minus; L {{ number_format($rebaja, 2) }}</strong>, ya rebajados del renglón de la cirugía.
                            Crece con cada medicamento que sale; lo presupuestado y no despachado no se descuenta.
                        </p>
                    @endif

                    <p>
                        Lo de esta lista ya está pagado dentro del paquete; lo que se le dé y no esté acá se cobra aparte.
                        El lote lo elige el sistema por FEFO: sale primero el que vence primero.
                    </p>
                </div>

                {{--
                    🔴 Abre en pestaña NUEVA a propósito: esto vive dentro
                    del modal de cargar, y navegar en la misma pestaña lo
                    cerraría con lo que la cajera estaba armando.
                --}}
                <a
                    href="{{ \App\Filament\Resources\Presupuestos\PresupuestoResource::getUrl('edit', ['record' => $presupuesto]) }}"
                    target="_blank"
                    rel="noopener"
                    class="sihla-desglose-enlace"
                >Cambiar el presupuesto {{ $presupuesto->numero }} &rarr;</a>
            </div>
        </div>
    @endif
</div>
