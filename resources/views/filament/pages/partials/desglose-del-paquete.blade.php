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
--}}
@php($desglose = $presupuesto->desglose())

<div class="sihla-desglose" wire:key="desglose-{{ $presupuesto->id }}">
    {{--
        🔴 NO ES UN `<details>`, Y ESO ES A PROPÓSITO.

        El desplegado es estado de Livewire. Con `<details>` —probado
        solo, con `@entangle` y con `wire:key`— el nodo se recrea en cada
        actualización y la lista se cierra sola: despachar cinco
        renglones obligaba a reabrirla cinco veces.
    --}}
    <button type="button" class="sihla-desglose-titulo" wire:click="alternarPaquete">
        <span>{{ $this->paqueteAbierto ? '▾' : '▸' }}</span>
        QUÉ INCLUYE · {{ $desglose->count() }} renglones
        @php($deFarmacia = $desglose->where('estado', '!=', 'incluido'))
        @php($entregados = $deFarmacia->where('estado', 'completo')->count())

        {{--
            El avance cuenta SOLO lo que sale de farmacia. Decir «0 de
            21» cuando dieciséis de esos veintiuno no se despachan nunca
            haría parecer atrasado un caso que va al día.
        --}}
        <span class="sihla-desglose-avance">
            @if ($deFarmacia->isEmpty())
                nada que despachar
            @else
                {{ $entregados }} de {{ $deFarmacia->count() }} despachados
            @endif
        </span>
    </button>

    @if ($this->paqueteAbierto)
    <table class="sihla-desglose-tabla">
        <tbody>
            @foreach ($desglose as $fila)
                @php($linea = $fila['linea'])
                <tr wire:key="renglon-{{ $linea->id }}">
                    <td class="sihla-desglose-marca">
                        @if ($fila['estado'] === 'incluido')
                            <span
                                class="sihla-desglose-auto"
                                title="No sale de farmacia: va incluido en el paquete"
                            >&check;</span>
                        @elseif ($fila['estado'] === 'completo')
                            <span title="Despachado completo">&check;</span>
                        @elseif ($fila['estado'] === 'parcial')
                            <span title="Despachado en parte">&sim;</span>
                        @else
                            <span title="Falta despacharlo de farmacia">&nbsp;</span>
                        @endif
                    </td>

                    <td>
                        {{ $linea->texto }}
                        @if ($linea->opcional)
                            <span class="sihla-desglose-opcional">opcional</span>
                        @endif
                    </td>

                    {{--
                        🔴 LA CANTIDAD SE MUESTRA SIEMPRE.

                        Que sean TRES días de habitación y no uno es el
                        dato que el turno necesita, esté o no marcado.
                        Reemplazarlo por la palabra «incluido» borraba
                        justo lo único que había que leer.
                    --}}
                    @php($pedida = rtrim(rtrim((string) $linea->cantidad, '0'), '.'))
                    @php($unidad = $linea->unidad?->simbolo)

                    <td class="sihla-num">
                        @if ($fila['estado'] === 'incluido')
                            <span class="sihla-desglose-auto">{{ $pedida }}{{ $unidad ? ' '.$unidad : '' }}</span>
                        @else
                            {{ rtrim(rtrim($fila['consumida']->redondeado(4), '0'), '.') }}
                            de
                            {{ $pedida }}{{ $unidad ? ' '.$unidad : '' }}
                        @endif
                    </td>

                    {{--
                        🔴 EL BOTÓN SOLO EN LO QUE SALE DE FARMACIA.

                        Un honorario no se «entrega»; ponerle botón sería
                        invitar a marcar algo que nadie despachó.

                        Entrega LO QUE FALTA de un tirón: el renglón ya
                        sabe el producto, el envase y la cantidad. Que la
                        cajera lo vuelva a teclear es donde se escribe 100
                        en vez de 10.
                    --}}
                    <td class="sihla-desglose-acc">
                        @if ($fila['estado'] !== 'incluido' && $fila['estado'] !== 'completo')
                            @if ($this->lineaAEntregar === $linea->id)
                                {{--
                                    🔴 SE PREGUNTA CUÁNTO, SIEMPRE.

                                    Casi nunca se entregan las diez de una
                                    sola vez. Si se dan ocho y el paciente
                                    pide el alta, las otras dos nunca
                                    salieron de farmacia — y marcarlas como
                                    entregadas sería decir que se dio algo
                                    que está en el estante.
                                --}}
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
                                        class="sihla-desglose-entregar"
                                    >Dar</button>
                                    <button
                                        type="button"
                                        wire:click="cancelarEntrega"
                                        class="sihla-desglose-entregar"
                                    >&times;</button>
                                </span>
                            @else
                                <button
                                    type="button"
                                    wire:click="pedirLaCantidad({{ $linea->id }})"
                                    class="sihla-desglose-entregar"
                                >
                                    Entregar
                                </button>
                            @endif
                        @endif

                        {{--
                            Se equivocó: puso 8 y solo dio 6. Deshace la
                            última entrega —reversa al kardex— para volver
                            a darla bien. Un cargo no se edita (§9.0.3).
                        --}}
                        @if ($fila['estado'] !== 'incluido' && ! $fila['consumida']->esCero())
                            <button
                                type="button"
                                wire:click="deshacerEntrega({{ $linea->id }})"
                                wire:confirm="¿Deshacer la última entrega de este renglón? El medicamento vuelve al inventario."
                                class="sihla-desglose-entregar"
                                title="Deshacer la última entrega"
                            >&#8634;</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{--
        De dónde salió la rebaja del renglón de la cirugía.

        Sin esto, quien abre la cuenta ve un descuento sobre una
        apendicectomía y no tiene cómo saber que viene de los
        medicamentos: el paquete no muestra sus precios.
    --}}
    @php($rebaja = (float) ($descuentoDeFarmacia ?? 0))

    @if ($rebaja > 0)
        <p class="sihla-desglose-pie">
            Descuento del hospital sobre lo entregado de farmacia:
            <strong>&minus; L {{ number_format($rebaja, 2) }}</strong>, ya rebajados del renglón de la cirugía.
            Crece con cada medicamento que sale; lo presupuestado y no despachado no se descuenta.
        </p>
    @endif

    <p class="sihla-desglose-pie">
        Lo de esta lista ya está pagado dentro del paquete. Lo que se le dé y no esté acá se cobra aparte.
        El lote lo elige el sistema por FEFO: siempre sale primero el que está más cerca de vencer.

        {{--
            🔴 Abre en pestaña NUEVA a propósito.

            Esto vive dentro del modal de cargar a la cuenta. Navegar en
            la misma pestaña cerraría el modal y se perdería lo que la
            cajera estaba armando — con el paciente enfrente.
        --}}
        <a
            href="{{ \App\Filament\Resources\Presupuestos\PresupuestoResource::getUrl('edit', ['record' => $presupuesto]) }}"
            target="_blank"
            rel="noopener"
            class="sihla-desglose-enlace"
        >
            Cambiar el presupuesto ({{ $presupuesto->numero }}) &rarr;
        </a>
    </p>
    @endif
</div>
