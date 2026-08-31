{{--
    La cuenta, leída como lo que va a ser: una factura en construcción.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ CON FORMA DE DOCUMENTO Y NO DE TABLA DE SISTEMA
    ─────────────────────────────────────────────────────────────────────

    Quien carga tiene que poder mirar la pantalla y ver lo mismo que el
    paciente va a recibir impreso. Una tabla de sistema obliga a traducir;
    un documento se lee de una. Y lo que se lee, se revisa: los errores de
    captura se ven acá, no en caja tres días después.

    Las líneas van en orden ASCENDENTE, como en una factura, y el bloque
    hace scroll solo hasta el final para que la última cargada quede a la
    vista sin buscarla.

    El pie separa exento de gravado y de ISV porque es exactamente lo que
    el formato fiscal hondureño exige totalizar por separado (§8.6.1-3), y
    porque es lo primero que alguien va a querer cruzar cuando algo no
    cuadre.
--}}
@php($cuenta = $cuenta ?? null)
@php($usuario = $usuario ?? null)
@php($cargoAQuitar = $cargoAQuitar ?? null)

@if ($cuenta)
    {{--
        🔴 Se muestran las líneas VIVAS, no las anuladas ni sus reversas.

        Rehacer una línea para aplicarle el descuento deja tres filas en la
        base —la original anulada, su reversa, y la nueva— y las tres suman
        cero entre ellas. Este bloque es el documento que el paciente va a
        recibir, no el libro: mostrarle tres renglones donde compró una caja
        lo vuelve ilegible justo cuando más se necesita revisarlo.

        El rastro completo NO se pierde: vive en «Cargos» y en la bitácora,
        que es donde se lo busca cuando hay que explicar algo.
    --}}
    @php($renglones = $cuenta->renglonesVivos())
    @php($varios = $cuenta->laCargaMasDeUno())

    <div class="sihla-factura">
        <div class="sihla-factura-cabecera">
            <span class="sihla-factura-rotulo">Cuenta en curso</span>
            <span class="sihla-factura-numero">{{ $cuenta->numero }}</span>
        </div>

        @if ($renglones->isEmpty())
            <p class="sihla-factura-vacia">
                Todavía no tiene nada cargado. Escaneá el primer ítem arriba, o tocá uno de los atajos.
            </p>
        @else
            <div class="sihla-factura-cuerpo" x-data x-init="$el.scrollTop = $el.scrollHeight">
                <table class="sihla-factura-tabla">
                    <thead>
                        <tr>
                            <th class="sihla-col-n">#</th>
                            <th>Descripción</th>
                            <th class="sihla-num">Cant.</th>
                            <th class="sihla-num">P. unit.</th>
                            <th class="sihla-num">Desc.</th>
                            <th class="sihla-num">Importe</th>
                            <th class="sihla-col-acc"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($renglones as $indice => $renglon)
                            <tr>
                                <td class="sihla-col-n">{{ $indice + 1 }}</td>

                                <td>
                                    <span class="sihla-linea-texto">{{ $renglon->texto }}</span>
                                    <span class="sihla-linea-nota">
                                        {{ $renglon->nota }}
                                        {{--
                                            El nombre aparece solo si hay
                                            más de una persona cargando.
                                            Con una sola se repetiría en
                                            cada renglón sin decir nada, y
                                            un rótulo que está siempre se
                                            vuelve decorado.
                                        --}}
                                        @if ($varios && $renglon->quien)
                                            · {{ $renglon->quien }}
                                        @endif
                                        @if ($renglon->cuantasEntregas() > 1)
                                            · {{ $renglon->cuantasEntregas() }} entregas
                                        @endif
                                    </span>
                                </td>

                                {{--
                                    La cantidad y su envase juntos: «1
                                    FRASCO 60 ML» y no un «60» suelto que
                                    nadie entregó. El rótulo solo aparece
                                    cuando se cobró por envase.
                                --}}
                                <td class="sihla-num">
                                    {{ rtrim(rtrim($renglon->cantidad->redondeado(4), '0'), '.') }}
                                    @if ($renglon->unidad)
                                        <span class="sihla-linea-unidad">{{ $renglon->unidad }}</span>
                                    @endif
                                </td>
                                <td class="sihla-num">{{ number_format((float) $renglon->precioUnitario, 2) }}</td>
                                <td class="sihla-num">
                                    {{ $renglon->descuento->esCero() ? '—' : number_format((float) $renglon->descuento->redondeado(2), 2) }}
                                </td>
                                <td class="sihla-num sihla-fuerte">{{ $renglon->importe()->formateado() }}</td>

                                <td class="sihla-col-acc">
                                    @if ($renglon->sePuedeQuitar())
                                        @php($ultima = $renglon->ultimaEntrega())
                                        @php($pideMotivo = $renglon->pideMotivoParaQuitar($usuario))
                                        {{--
                                            Quita la ÚLTIMA entrega, no el
                                            renglón entero: borrar de un
                                            clic algo que se cargó bien
                                            hace horas no es lo que alguien
                                            quiere decir con la ✕ sobre una
                                            lista que está armando. Dos
                                            clics quitan las dos.

                                            🔴 Dos ✕ distintas, y la
                                            diferencia no es cosmética.

                                            Si la línea es tuya y de
                                            recién, quita de una: es un
                                            error de tecleo y pedir una
                                            justificación por eso produce
                                            «aaaaaaaaaa», no auditoría.

                                            Si la puso otro turno, o ya no
                                            es de recién, abre el campo del
                                            porqué. Ahí quitarla dejó de
                                            ser corregir un tecleo — el
                                            medicamento pudo salir del
                                            carro, y quien la puso merece
                                            saber por qué desapareció.

                                            `wire:loading.attr` apaga el
                                            botón mientras viaja: dos clics
                                            seguidos anularían dos veces el
                                            mismo cargo, y el segundo
                                            revienta contra un estado que
                                            ya no es pendiente.
                                        --}}
                                        <button
                                            type="button"
                                            wire:click="{{ $pideMotivo ? 'pedirElMotivo' : 'quitarCargo' }}({{ $ultima?->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="quitarCargo({{ $ultima?->id }})"
                                            class="sihla-anular {{ $pideMotivo ? 'sihla-anular-pregunta' : '' }}"
                                            title="{{ $pideMotivo
                                                ? 'Quitar — hay que decir por qué'
                                                : ($renglon->cuantasEntregas() > 1
                                                    ? 'Quitar la última entrega ('.rtrim(rtrim((string) $ultima?->cantidad, '0'), '.').')'
                                                    : 'Quitar esta línea') }}"
                                        >✕</button>
                                    @endif
                                </td>
                            </tr>

                            {{--
                                El campo del porqué, debajo de la línea que
                                se va a quitar.

                                Va acá y no en un modal aparte: un modal
                                encima del modal de cargar no se puede
                                montar en esta pantalla —ya se probó, el
                                clic no hace nada y no da error— y además
                                taparía justo la línea que hay que mirar
                                para decidir.
                            --}}
                            {{--
                                El desglose del paquete presupuestado
                                (ADR-0009): qué de la cirugía ya se le dio
                                al paciente y qué falta.

                                Va debajo del renglón y plegado, como la
                                bitácora: la familia lee UN número y el
                                turno abre el detalle cuando lo necesita.
                            --}}
                            @php($paquete = $renglon->presupuestoDelPaquete())

                            @if ($paquete !== null)
                                {{--
                                    🔴 `wire:key` NO ES OPCIONAL ACÁ.

                                    Sin él, Livewire REEMPLAZA este pedazo
                                    del DOM en cada actualización en vez de
                                    reconciliarlo, y con el nodo nuevo se
                                    van el desplegado y la posición del
                                    scroll. Con la key, es el mismo nodo y
                                    solo cambia lo que cambió.
                                --}}
                                <tr class="sihla-desglose-fila" wire:key="paquete-{{ $paquete->id }}">
                                    <td colspan="7">
                                        @include('filament.pages.partials.desglose-del-paquete', [
                                            'presupuesto' => $paquete,
                                            'descuentoDeFarmacia' => $renglon->ultimaEntrega()?->descuento_comercial,
                                        ])
                                    </td>
                                </tr>
                            @endif

                            @if ($renglon->sePuedeQuitar() && $cargoAQuitar === $renglon->ultimaEntrega()?->id)
                                <tr class="sihla-motivo-fila">
                                    <td colspan="7">
                                        <div class="sihla-motivo">
                                            <label for="sihla-motivo-campo">
                                                ¿Por qué se quita? Lo puso
                                                <strong>{{ $renglon->quien ?? 'otro turno' }}</strong>
                                                y queda escrito en la bitácora de la cuenta.
                                            </label>
                                            <div class="sihla-motivo-fila-campos">
                                                <input
                                                    id="sihla-motivo-campo"
                                                    type="text"
                                                    wire:model="motivoDeQuitar"
                                                    wire:keydown.enter="quitarConMotivo"
                                                    class="sihla-motivo-campo"
                                                    placeholder="El paciente pidió traslado y no se le administró."
                                                    autofocus
                                                >
                                                <button
                                                    type="button"
                                                    wire:click="quitarConMotivo"
                                                    wire:loading.attr="disabled"
                                                    wire:target="quitarConMotivo"
                                                    class="sihla-motivo-quitar"
                                                >Quitar</button>
                                                <button
                                                    type="button"
                                                    wire:click="cancelarQuitar"
                                                    class="sihla-motivo-cancelar"
                                                >Cancelar</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php($descuentos = (float) $cuenta->total_descuento)
            @php($armado = $armado ?? null)

            {{--
                🔴 EL ORDEN DE ESTAS LÍNEAS NO ES DECORATIVO: ES LA CUENTA.

                `base_exenta` y `base_gravada` ya vienen NETAS del descuento
                —son el subtotal repartido, y el subtotal es bruto menos
                descuentos (`CalculadoraDeCargo`, paso 4)—. Con «Descuentos»
                abajo del ISV, quien sume de arriba hacia abajo llega a un
                total que no es el que dice el renglón TOTAL, y una factura
                que no cuadra a simple vista no la firma nadie.

                Con el bruto arriba, la resta se lee sola:
                bruto − descuentos = exento + gravado, más ISV, igual TOTAL.

                Y va SIEMPRE, no solo cuando hay descuento: un renglón que
                aparece y desaparece deja la duda de si esta vez no hubo
                descuento o si el sistema no lo mostró.
            --}}
            <div class="sihla-factura-pie">
                <div class="sihla-pie-linea">
                    <span>Importe bruto</span>
                    <span>{{ number_format((float) $cuenta->total_bruto, 2) }}</span>
                </div>
                <div class="sihla-pie-linea">
                    <span>Descuentos</span>
                    <span>{{ $descuentos === 0.0 ? '0.00' : '− '.number_format($descuentos, 2) }}</span>
                </div>
                <div class="sihla-pie-linea">
                    <span>Importe exento</span>
                    <span>{{ number_format((float) $cuenta->total_exento, 2) }}</span>
                </div>
                <div class="sihla-pie-linea">
                    <span>Importe gravado</span>
                    <span>{{ number_format((float) $cuenta->total_gravado, 2) }}</span>
                </div>
                <div class="sihla-pie-linea">
                    <span>ISV</span>
                    <span>{{ number_format((float) $cuenta->total_isv, 2) }}</span>
                </div>

                <div class="sihla-pie-linea sihla-pie-total">
                    <span>TOTAL</span>
                    <span>{{ $cuenta->saldo()->formateado() }}</span>
                </div>

                <div class="sihla-pie-linea sihla-pie-division">
                    <span>Le toca al paciente</span>
                    <span>{{ $cuenta->saldoDelPaciente()->formateado() }}</span>
                </div>

                {{--
                    ─────────────────────────────────────────────────────
                    LO QUE YA PAGARON
                    ─────────────────────────────────────────────────────

                    Solo aparece si hay abonos. Con la cuenta sin pagar,
                    «Abonado 0.00» y un saldo igual al total serían dos
                    renglones que no dicen nada, y el pie ya es largo.

                    El saldo es DERIVADO: total menos los abonos vivos.
                    Anular un recibo lo cambia en el acto.
                --}}
                @php($abonado = $cuenta->abonado())

                @if (! $abonado->esCero())
                    @php($saldo = $cuenta->saldoPendiente())

                    <div class="sihla-pie-linea">
                        <span>Abonado</span>
                        <span>&minus; {{ number_format((float) $abonado->redondeado(2), 2) }}</span>
                    </div>

                    <div class="sihla-pie-linea sihla-pie-total">
                        <span>{{ $saldo->esNegativo() ? 'SALDO A FAVOR' : 'SALDO' }}</span>
                        <span>
                            {{ number_format((float) ($saldo->esNegativo()
                                ? $cuenta->saldoAFavor()->redondeado(2)
                                : $saldo->redondeado(2)), 2) }}
                        </span>
                    </div>
                @endif

                {{--
                    El descuento que está puesto arriba pero todavía no tocó
                    ninguna línea.

                    Sin este renglón la pantalla se ve inerte: se teclea 30,
                    se escribe el motivo, y abajo no cambia nada — porque los
                    cargos que ya están asentados no se tocan. Decirlo acá,
                    junto a los números, es la diferencia entre «el sistema no
                    hizo nada» y «está armado y espera la próxima línea».
                --}}
                @if (filled($armado) && (float) $armado > 0)
                    <div class="sihla-pie-linea sihla-pie-armado">
                        <span>Descuento del hospital · {{ rtrim(rtrim(number_format((float) $armado, 2), '0'), '.') }} %</span>
                        <span>aplicado a toda la cuenta</span>
                    </div>
                @endif

                @if (! $cuenta->saldoDeLaAseguradora()->esCero())
                    <div class="sihla-pie-linea sihla-pie-division">
                        <span>Le toca a {{ $cuenta->convenio->nombre }}</span>
                        <span>{{ $cuenta->saldoDeLaAseguradora()->formateado() }}</span>
                    </div>
                @endif
            </div>
        @endif

        {{--
            ─────────────────────────────────────────────────────────────
            🔴 LA BITÁCORA: LO QUE LA FACTURA NO PUEDE MOSTRAR
            ─────────────────────────────────────────────────────────────

            Arriba está el DOCUMENTO —lo que el paciente va a recibir
            impreso— y por eso esconde las anuladas y sus reversas: tres
            filas que suman cero vuelven ilegible justo lo que hay que
            revisar.

            Esto es el LIBRO. En el cambio de turno la pregunta no es
            «cuánto va la cuenta», es qué pasó acá: quién cargó qué, a qué
            hora, qué se quitó, quién lo quitó y por qué. Con solo las
            vivas, una línea que el turno anterior puso y alguien quitó es
            una línea que nunca existió — y eso es lo que después nadie
            puede explicar.

            Cerrada por defecto: se abre cuando hace falta y no le roba
            pantalla a lo que se usa cien veces por turno.
        --}}
        @php($bitacora = $cuenta->bitacora())

        @if ($bitacora->isNotEmpty())
            <details class="sihla-bitacora">
                <summary>
                    Bitácora · {{ $bitacora->count() }}
                    {{ $bitacora->count() === 1 ? 'movimiento' : 'movimientos' }}
                </summary>

                <div class="sihla-bitacora-cuerpo">
                    @foreach ($bitacora as $movimiento)
                        @php($esReversa = $movimiento->estado === \App\Domain\Enums\EstadoCargo::Anulacion)
                        @php($esAnulado = $movimiento->estado === \App\Domain\Enums\EstadoCargo::Anulado)

                        <div class="sihla-bitacora-linea {{ $esReversa || $esAnulado ? 'sihla-bitacora-muerta' : '' }}">
                            <span class="sihla-bitacora-hora">
                                {{ $movimiento->registrado_en->format('d/m H:i') }}
                            </span>

                            <span class="sihla-bitacora-que">
                                <span class="{{ $esAnulado ? 'sihla-tachado' : '' }}">
                                    {{ $movimiento->texto }}
                                </span>
                                <span class="sihla-bitacora-quien">
                                    {{ $movimiento->createdBy?->name ?? 'Sistema' }}
                                    ·
                                    {{ rtrim(rtrim((string) $movimiento->cantidad, '0'), '.') }}
                                    ·
                                    {{ $movimiento->estado->etiqueta() }}
                                    @if (filled($movimiento->motivo_anulacion))
                                        — «{{ $movimiento->motivo_anulacion }}»
                                    @endif
                                </span>
                            </span>

                            <span class="sihla-bitacora-monto">
                                {{ $movimiento->totalParaMostrar() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>

    <style>
        .sihla-pie-armado {
            font-size: .72rem; color: rgb(180 120 20); font-style: italic;
            padding-top: .35rem;
        }
        .sihla-factura {
            margin-top: 1rem; border: 1px solid rgb(228 228 231); border-radius: .6rem;
            overflow: hidden; background: rgb(255 255 255);
        }

        .sihla-factura-cabecera {
            display: flex; align-items: baseline; justify-content: space-between;
            padding: .55rem .85rem; background: rgb(250 250 250);
            border-bottom: 1px solid rgb(228 228 231);
        }
        .sihla-factura-rotulo {
            font-size: .7rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .06em; color: rgb(113 113 122);
        }
        .sihla-factura-numero {
            font-size: .8rem; font-variant-numeric: tabular-nums; color: rgb(63 63 70);
        }

        .sihla-factura-vacia {
            padding: 1.5rem .85rem; font-size: .85rem; color: rgb(113 113 122); text-align: center;
        }

        .sihla-factura-cuerpo { max-height: 17rem; overflow-y: auto; }

        .sihla-factura-tabla {
            width: 100%; border-collapse: collapse;
            font-size: .8rem; font-variant-numeric: tabular-nums;
        }
        .sihla-factura-tabla thead th {
            position: sticky; top: 0; z-index: 1;
            background: rgb(255 255 255);
            text-align: start; font-weight: 600; font-size: .68rem;
            text-transform: uppercase; letter-spacing: .04em; color: rgb(113 113 122);
            padding: .4rem .6rem; border-bottom: 1px solid rgb(228 228 231);
        }
        .sihla-factura-tabla td {
            padding: .45rem .6rem; border-bottom: 1px solid rgb(244 244 245); vertical-align: top;
        }
        .sihla-factura-tabla tbody tr:last-child td { border-bottom: none; }

        .sihla-col-n { width: 2rem; color: rgb(161 161 170); text-align: end; }
        .sihla-col-acc { width: 2rem; text-align: center; }
        .sihla-num { text-align: end; white-space: nowrap; }
        .sihla-fuerte { font-weight: 700; color: rgb(24 24 27); }

        .sihla-linea-texto { display: block; color: rgb(24 24 27); }
        .sihla-linea-nota { display: block; font-size: .68rem; color: rgb(161 161 170); }

        /*
         * El envase pegado a la cantidad, más chico y en gris: es el
         * rótulo del número, no otro dato. En el mismo tamaño competiría
         * con la cifra, que es lo que hay que leer.
         */
        .sihla-linea-unidad { font-size: .68rem; color: rgb(161 161 170); margin-left: .15rem; }

        .sihla-anulado { opacity: .45; }
        .sihla-anulado .sihla-linea-texto { text-decoration: line-through; }

        .sihla-anular {
            font-size: .8rem; line-height: 1; color: rgb(161 161 170); cursor: pointer;
            padding: .15rem .3rem; border-radius: .25rem;
        }
        .sihla-anular:hover { color: rgb(190 18 60); background: rgb(254 242 242); }

        /* La ✕ que va a preguntar se ve distinta ANTES de tocarla. */
        .sihla-anular-pregunta { color: rgb(180 120 20); }
        .sihla-anular-pregunta:hover { color: rgb(146 64 14); background: rgb(254 249 235); }

        .sihla-motivo-fila > td { background: rgb(254 249 235); padding: .6rem .85rem; }
        .sihla-motivo label {
            display: block; font-size: .72rem; color: rgb(120 83 12); margin-bottom: .35rem;
        }
        .sihla-motivo-fila-campos { display: flex; gap: .4rem; align-items: center; }
        .sihla-motivo-campo {
            flex: 1; font-size: .8rem; padding: .35rem .55rem;
            border: 1px solid rgb(214 158 46); border-radius: .35rem;
            background: rgb(255 255 255); color: rgb(24 24 27);
        }
        .sihla-motivo-quitar, .sihla-motivo-cancelar {
            font-size: .78rem; padding: .35rem .7rem; border-radius: .35rem; cursor: pointer;
            white-space: nowrap;
        }
        .sihla-motivo-quitar { background: rgb(190 18 60); color: rgb(255 255 255); }
        .sihla-motivo-quitar:disabled { opacity: .5; cursor: wait; }
        .sihla-motivo-cancelar { background: rgb(228 228 231); color: rgb(63 63 70); }

        .sihla-bitacora { border-top: 1px solid rgb(228 228 231); }
        .sihla-bitacora > summary {
            padding: .5rem .85rem; font-size: .72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em;
            color: rgb(113 113 122); cursor: pointer; user-select: none;
        }
        .sihla-bitacora > summary:hover { color: rgb(63 63 70); }
        .sihla-bitacora-cuerpo {
            max-height: 14rem; overflow-y: auto; padding: 0 .85rem .6rem;
        }
        .sihla-bitacora-linea {
            display: flex; gap: .7rem; align-items: baseline;
            padding: .3rem 0; border-bottom: 1px solid rgb(244 244 245);
            font-size: .74rem; font-variant-numeric: tabular-nums;
        }
        .sihla-bitacora-linea:last-child { border-bottom: none; }
        .sihla-bitacora-hora { color: rgb(161 161 170); white-space: nowrap; }
        .sihla-bitacora-que { flex: 1; min-width: 0; color: rgb(63 63 70); }
        .sihla-bitacora-quien { display: block; font-size: .68rem; color: rgb(161 161 170); }
        .sihla-bitacora-monto { white-space: nowrap; color: rgb(82 82 91); }
        .sihla-bitacora-muerta { opacity: .55; }
        .sihla-tachado { text-decoration: line-through; }

        .sihla-factura-pie {
            padding: .6rem .85rem; background: rgb(250 250 250);
            border-top: 1px solid rgb(228 228 231);
        }
        .sihla-pie-linea {
            display: flex; justify-content: space-between; gap: 2rem;
            font-size: .78rem; color: rgb(82 82 91); padding: .12rem 0;
            font-variant-numeric: tabular-nums;
        }
        .sihla-pie-linea > span:last-child { min-width: 7rem; text-align: end; }
        .sihla-pie-total {
            margin-top: .35rem; padding-top: .35rem; border-top: 1px solid rgb(212 212 216);
            font-size: 1rem; font-weight: 700; color: rgb(24 24 27);
        }
        .sihla-pie-division { color: rgb(113 113 122); }

        .dark .sihla-factura { background: rgb(24 24 27); border-color: rgb(63 63 70); }
        .dark .sihla-factura-cabecera,
        .dark .sihla-factura-pie { background: rgb(39 39 42); border-color: rgb(63 63 70); }
        .dark .sihla-factura-tabla thead th { background: rgb(24 24 27); border-bottom-color: rgb(63 63 70); }
        .dark .sihla-factura-tabla td { border-bottom-color: rgb(39 39 42); }
        .dark .sihla-linea-texto,
        .dark .sihla-fuerte,
        .dark .sihla-pie-total { color: rgb(244 244 245); }
        .dark .sihla-factura-numero { color: rgb(212 212 216); }
        .dark .sihla-pie-linea { color: rgb(212 212 216); }
        .dark .sihla-pie-total { border-top-color: rgb(82 82 91); }
        .dark .sihla-anular:hover { background: rgb(69 26 26); }
        .dark .sihla-anular-pregunta:hover { background: rgb(69 51 16); color: rgb(252 211 77); }
        .dark .sihla-motivo-fila > td { background: rgb(45 39 25); }
        .dark .sihla-motivo label { color: rgb(252 211 77); }
        .dark .sihla-motivo-campo {
            background: rgb(24 24 27); color: rgb(244 244 245); border-color: rgb(146 64 14);
        }
        .dark .sihla-motivo-cancelar { background: rgb(63 63 70); color: rgb(212 212 216); }
        .dark .sihla-bitacora { border-top-color: rgb(63 63 70); }
        .dark .sihla-bitacora > summary:hover { color: rgb(212 212 216); }
        .dark .sihla-bitacora-linea { border-bottom-color: rgb(39 39 42); }
        .dark .sihla-bitacora-que { color: rgb(212 212 216); }
        .dark .sihla-bitacora-monto { color: rgb(161 161 170); }
    
        /*
         * El desglose del paquete presupuestado (ADR-0009). Plegado por
         * defecto: la familia lee un número, el turno abre el detalle.
         */
        .sihla-desglose { margin: .25rem 0 .5rem; font-size: .78rem; }
        .sihla-desglose-titulo {
            cursor: pointer; letter-spacing: .04em; text-transform: uppercase;
            opacity: .75; padding: .2rem 0; background: none; border: 0;
            color: inherit; font: inherit; text-align: left; width: 100%;
        }
        .sihla-desglose-titulo:hover { opacity: 1; }
        .sihla-desglose-avance { text-transform: none; letter-spacing: 0; opacity: .8; margin-left: .5rem; }
        .sihla-desglose-tabla { width: 100%; margin-top: .35rem; }

        /*
         * La barra de scroll del contenedor tapaba la columna de la
         * cantidad —el dato que hay que leer—. `scrollbar-gutter` le
         * reserva el carril aunque la barra no esté, así la columna no
         * se corre cuando aparece; el padding es el respaldo para los
         * navegadores que no lo soportan.
         */
        .sihla-desglose-tabla td:last-child { padding-right: 1.1rem; }
        .sihla-desglose { scrollbar-gutter: stable; }
        .sihla-desglose-tabla td { padding: .18rem .4rem; vertical-align: top; }
        .sihla-desglose-marca { width: 1.4rem; text-align: center; opacity: .9; }
        .sihla-desglose-auto { opacity: .45; font-style: italic; }
        .sihla-desglose-acc { width: 5.5rem; text-align: right; padding-right: .2rem; }
        .sihla-desglose-entregar {
            font-size: .72rem; padding: .12rem .5rem; border-radius: .3rem;
            border: 1px solid currentColor; opacity: .85; cursor: pointer;
            text-transform: uppercase; letter-spacing: .03em;
        }
        .sihla-desglose-entregar:hover { opacity: 1; }
        .sihla-desglose-entregar[disabled] { opacity: .4; cursor: wait; }
        .sihla-desglose-acc { width: 9rem; }
        .sihla-desglose-pregunta { display: inline-flex; gap: .2rem; align-items: center; }
        .sihla-desglose-campo {
            width: 3.2rem; text-align: right; font-size: .72rem;
            padding: .1rem .3rem; border-radius: .3rem;
            border: 1px solid currentColor; background: transparent; color: inherit;
        }
        .sihla-desglose-opcional {
            font-size: .7rem; opacity: .6; margin-left: .35rem;
            text-transform: uppercase; letter-spacing: .04em;
        }
        .sihla-desglose-pie { margin-top: .4rem; opacity: .65; font-size: .72rem; }
        .sihla-desglose-enlace {
            display: inline-block; margin-left: .35rem;
            text-decoration: underline; opacity: .9; white-space: nowrap;
        }
    </style>
@endif
