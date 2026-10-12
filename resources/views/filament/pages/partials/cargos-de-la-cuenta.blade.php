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
            <span class="sihla-factura-rotulo">
                Cuenta en curso
                @if ($renglones->isNotEmpty())
                    <span class="sihla-factura-cuantos">{{ $renglones->count() }} {{ $renglones->count() === 1 ? 'renglón' : 'renglones' }}</span>
                @endif
            </span>
            <span class="sihla-factura-numero">{{ $cuenta->numero }}</span>
        </div>

        @if ($renglones->isEmpty())
            <p class="sihla-factura-vacia">
                Todavía no tiene nada cargado. Escaneá el primer ítem arriba, o tocá uno de los atajos.
            </p>
        @else
            {{--
                Con el desglose de una cirugía abierto, el cuerpo deja de
                tener alto propio: una tabla con scroll adentro de otra con
                scroll, adentro de un modal con scroll, era leer por una
                rendija. Abierto, crece y el que se desplaza es el modal.
            --}}
            <div
                class="sihla-factura-cuerpo {{ $this->paqueteAbierto ? 'sihla-factura-cuerpo-libre' : '' }}"
                x-data
                x-init="$el.scrollTop = $el.scrollHeight"
            >
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
                            @php($paquete = $renglon->presupuestoDelPaquete())

                            <tr class="{{ $paquete !== null ? 'sihla-fila-paquete' : '' }}">
                                <td class="sihla-col-n">{{ $indice + 1 }}</td>

                                <td>
                                    <span class="sihla-linea-texto">
                                        {{ $renglon->texto }}
                                        @if ($paquete !== null)
                                            <span class="sihla-etiqueta-paquete">Paquete</span>
                                        @endif
                                    </span>
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
                                    <td colspan="7" class="sihla-desglose-celda">
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
                                                    style="text-transform: uppercase"
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
                        <span>Descuento en farmacia · {{ rtrim(rtrim(number_format((float) $armado, 2), '0'), '.') }} %</span>
                        <span>aplicado a toda la cuenta</span>
                    </div>
                @endif

                {{-- Los de servicios y honorarios se leen de la cuenta: es lo que quedó guardado, no lo que se está tecleando. --}}
                @foreach (['servicios', 'honorarios'] as $rubroDelPie)
                    @if ($cuenta->{'descuento_'.$rubroDelPie.'_monto'} !== null)
                        <div class="sihla-pie-linea sihla-pie-armado">
                            <span>Descuento en {{ $rubroDelPie }} · L {{ number_format((float) $cuenta->{'descuento_'.$rubroDelPie.'_monto'}, 2) }}</span>
                            <span>repartido entre lo cargado</span>
                        </div>
                    @elseif ($cuenta->{'descuento_'.$rubroDelPie} !== null)
                        <div class="sihla-pie-linea sihla-pie-armado">
                            <span>Descuento en {{ $rubroDelPie }} · {{ rtrim(rtrim(number_format((float) $cuenta->{'descuento_'.$rubroDelPie} * 100, 2), '0'), '.') }} %</span>
                            <span>cada renglón hasta su tope</span>
                        </div>
                    @endif
                @endforeach

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
        {{--
            🔴 La caja también va al libro (11-oct-2026): «Abonado − 26.95»
            sin un renglón que lo explique es plata que nadie sabe de
            dónde salió. Los recibos y las facturas —con sus anulaciones—
            van debajo de los cargos, en el orden en que pasaron.
        --}}
        @php($bitacora = $cuenta->bitacora())
        @php($caja = $cuenta->bitacoraDeCaja())
        @php($cuantos = $bitacora->count() + count($caja))

        @if ($cuantos > 0)
            <details class="sihla-bitacora">
                <summary>
                    Bitácora · {{ $cuantos }}
                    {{ $cuantos === 1 ? 'movimiento' : 'movimientos' }}
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

                    @if ($caja !== [])
                        <div class="sihla-bitacora-titulo">Caja · recibos y facturas</div>

                        @foreach ($caja as $linea)
                            <div class="sihla-bitacora-linea {{ $linea['muerto'] ? 'sihla-bitacora-muerta' : '' }}">
                                <span class="sihla-bitacora-hora">
                                    {{ $linea['cuando']->format('d/m H:i') }}
                                </span>

                                <span class="sihla-bitacora-que">
                                    <span class="{{ $linea['tachado'] ? 'sihla-tachado' : '' }}">
                                        {{ $linea['texto'] }}
                                    </span>
                                    <span class="sihla-bitacora-quien">{{ $linea['detalle'] }}</span>
                                </span>

                                <span class="sihla-bitacora-monto">{{ $linea['monto'] }}</span>
                            </div>
                        @endforeach
                    @endif
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
        .sihla-factura-cuerpo-libre { max-height: none; }

        /*
         * ⚠️ Hijo directo (`>`) en todo lo de la tabla principal: adentro
         * vive la tabla del desglose, y un `td` a secas le pegaba al de
         * ella el padding, el borde y —peor— la cabecera pegajosa, que se
         * subía encima de la de la cuenta al hacer scroll.
         */
        .sihla-factura-tabla {
            width: 100%; border-collapse: collapse;
            font-size: .82rem; font-variant-numeric: tabular-nums; color: rgb(63 63 70);
        }
        .sihla-factura-tabla > thead > tr > th {
            position: sticky; top: 0; z-index: 1;
            background: rgb(255 255 255);
            text-align: start; font-weight: 600; font-size: .66rem;
            text-transform: uppercase; letter-spacing: .05em; color: rgb(113 113 122);
            padding: .5rem .7rem; border-bottom: 1px solid rgb(228 228 231);
        }
        /* Los títulos de las cifras, alineados con las cifras: a la derecha. */
        .sihla-factura-tabla > thead > tr > th.sihla-num { text-align: end; }
        .sihla-factura-tabla > tbody > tr > td {
            padding: .6rem .7rem; border-bottom: 1px solid rgb(244 244 245); vertical-align: top;
        }
        .sihla-factura-tabla > tbody > tr:last-child > td { border-bottom: none; }
        .sihla-factura-tabla > tbody > tr:not(.sihla-desglose-fila):not(.sihla-motivo-fila):hover > td {
            background: rgb(250 250 250);
        }

        /*
         * La cirugía y su desglose se leen como un solo bloque: sin raya
         * entre el renglón y el panel, y el panel corrido a la altura de
         * la descripción.
         */
        .sihla-factura-tabla > tbody > tr.sihla-fila-paquete > td { border-bottom: none; }
        .sihla-factura-tabla > tbody > tr > td.sihla-desglose-celda { padding: 0 .7rem .85rem 2.7rem; }
        .sihla-etiqueta-paquete {
            display: inline-block; margin-left: .4rem; vertical-align: .1em;
            font-size: .6rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
            padding: .08rem .4rem; border-radius: 999px;
            color: #b45309; background: rgb(254 243 199);
        }
        .sihla-factura-cuantos {
            margin-left: .45rem; font-weight: 500; text-transform: none; letter-spacing: 0;
            color: rgb(161 161 170);
        }

        .sihla-col-n { width: 2rem; color: rgb(161 161 170); text-align: end; }
        .sihla-col-acc { width: 2rem; text-align: center; }
        .sihla-num { text-align: end; white-space: nowrap; }
        .sihla-fuerte { font-weight: 700; color: rgb(24 24 27); }

        .sihla-linea-texto { display: block; color: rgb(24 24 27); font-weight: 500; }
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
            padding: .3rem .4rem; border-radius: .35rem; border: 0; background: transparent;
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
        .sihla-bitacora-titulo {
            margin-top: .6rem; padding: .35rem 0 .2rem; font-size: .68rem; font-weight: 600;
            letter-spacing: .04em; text-transform: uppercase; color: rgb(161 161 170);
        }
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
        .dark .sihla-factura-tabla > thead > tr > th { background: rgb(24 24 27); border-bottom-color: rgb(63 63 70); }
        .dark .sihla-factura-tabla { color: rgb(212 212 216); }
        .dark .sihla-factura-tabla > tbody > tr > td { border-bottom-color: rgb(39 39 42); }
        .dark .sihla-factura-tabla > tbody > tr:not(.sihla-desglose-fila):not(.sihla-motivo-fila):hover > td {
            background: rgb(255 255 255 / .03);
        }
        .dark .sihla-etiqueta-paquete { color: #fbbf24; background: rgb(251 191 36 / .12); }
        .dark .sihla-factura-cuantos { color: rgb(113 113 122); }
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
         * ─────────────────────────────────────────────────────────────
         * EL DESGLOSE DEL PAQUETE (ADR-0009) — 12-oct-2026
         * ─────────────────────────────────────────────────────────────
         *
         * Plegado: un botón con el avance de farmacia. Abierto: un panel
         * con su propia cabecera, el estado en palabras y con color, y la
         * suma al pie. La raya ámbar a la izquierda dice «esto es de la
         * cirugía de arriba».
         */
        .sihla-desglose { font-size: .78rem; }
        .sihla-desglose-toggle {
            display: inline-flex; align-items: center; flex-wrap: wrap; gap: .3rem .6rem;
            padding: .32rem .7rem .32rem .45rem; border-radius: .5rem; cursor: pointer;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            color: rgb(63 63 70); font: inherit; font-size: .74rem; text-align: left;
        }
        .sihla-desglose-toggle:hover { border-color: rgb(212 212 216); background: rgb(244 244 245); }
        .sihla-desglose-chevron { width: 1rem; height: 1rem; color: rgb(113 113 122); transition: transform .15s ease; }
        .sihla-desglose-abierto .sihla-desglose-chevron { transform: rotate(90deg); }
        .sihla-desglose-toggle-texto { font-weight: 600; color: rgb(39 39 42); }
        .sihla-desglose-cuenta { color: rgb(113 113 122); }
        .sihla-desglose-avance { display: inline-flex; align-items: center; gap: .4rem; color: rgb(113 113 122); }
        .sihla-desglose-barra {
            display: inline-block; width: 3.5rem; height: .3rem; border-radius: 999px;
            background: rgb(228 228 231); overflow: hidden;
        }
        .sihla-desglose-barra i { display: block; height: 100%; border-radius: 999px; background: #16a34a; }

        .sihla-desglose-panel {
            margin-top: .5rem; overflow: hidden; border-radius: .55rem;
            border: 1px solid rgb(228 228 231); border-left: 3px solid #d97706;
            background: rgb(250 250 250);
        }
        .sihla-desglose-tabla { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .sihla-desglose-tabla th {
            text-align: start; font-size: .62rem; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase; color: rgb(113 113 122);
            padding: .5rem .65rem; border-bottom: 1px solid rgb(228 228 231);
        }
        .sihla-desglose-tabla th.sihla-num { text-align: end; }
        .sihla-desglose-tabla td {
            padding: .45rem .65rem; border-bottom: 1px solid rgb(238 238 240);
            vertical-align: middle; color: rgb(39 39 42);
        }
        .sihla-desglose-tabla tbody tr:last-child td { border-bottom: none; }
        .sihla-desglose-tabla tbody tr:hover td { background: rgb(255 255 255); }

        .sihla-desglose-col-estado { width: 6.5rem; }
        .sihla-desglose-concepto { font-weight: 500; }
        .sihla-desglose-cant { white-space: nowrap; }
        .sihla-desglose-de { color: rgb(161 161 170); margin: 0 .1rem; }
        .sihla-desglose-unidad { font-size: .68rem; color: rgb(161 161 170); margin-left: .15rem; }
        .sihla-desglose-precio { width: 7.5rem; white-space: nowrap; font-weight: 600; }
        .sihla-desglose-opcional {
            margin-left: .4rem; font-size: .6rem; font-weight: 600; letter-spacing: .05em;
            text-transform: uppercase; padding: .05rem .35rem; border-radius: 999px;
            color: rgb(113 113 122); box-shadow: inset 0 0 0 1px rgb(212 212 216);
        }

        .sihla-estado {
            display: inline-flex; align-items: center; gap: .3rem; white-space: nowrap;
            font-size: .66rem; font-weight: 600; padding: .14rem .5rem; border-radius: 999px;
        }
        .sihla-estado::before {
            content: ''; width: .38rem; height: .38rem; border-radius: 999px; background: currentColor;
        }
        .sihla-estado-incluido { color: rgb(113 113 122); background: rgb(244 244 245); }
        .sihla-estado-completo { color: #15803d; background: rgb(220 252 231); }
        .sihla-estado-parcial { color: #b45309; background: rgb(254 243 199); }
        .sihla-estado-pendiente { color: #0369a1; background: rgb(224 242 254); }

        .sihla-desglose-acc { width: 10rem; text-align: right; white-space: nowrap; }
        .sihla-desglose-pregunta { display: inline-flex; gap: .3rem; align-items: center; }
        .sihla-desglose-campo {
            width: 3.6rem; text-align: right; font-size: .74rem;
            padding: .22rem .4rem; border-radius: .4rem;
            border: 1px solid rgb(212 212 216); background: rgb(255 255 255); color: rgb(24 24 27);
        }
        .sihla-btn-entregar, .sihla-btn-dar {
            font-size: .7rem; font-weight: 600; padding: .25rem .65rem; border-radius: .4rem;
            border: 0; cursor: pointer; white-space: nowrap;
        }
        .sihla-btn-entregar {
            color: #b45309; background: rgb(254 243 199 / .55);
            box-shadow: inset 0 0 0 1px rgb(217 119 6 / .45);
        }
        .sihla-btn-entregar:hover { background: rgb(254 243 199); }
        .sihla-btn-dar { color: rgb(255 255 255); background: #d97706; }
        .sihla-btn-dar:hover { background: #b45309; }
        .sihla-btn-dar[disabled] { opacity: .5; cursor: wait; }
        .sihla-btn-icono {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.65rem; height: 1.65rem; border-radius: .4rem; border: 0;
            background: transparent; color: rgb(113 113 122); cursor: pointer; font-size: .9rem;
        }
        .sihla-btn-icono:hover { background: rgb(244 244 245); color: rgb(39 39 42); }

        .sihla-desglose-suma td {
            font-weight: 700; color: rgb(24 24 27);
            border-top: 1px solid rgb(212 212 216); background: rgb(244 244 245);
        }

        .sihla-desglose-notas {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: .5rem 1.25rem; padding: .6rem .7rem;
            border-top: 1px solid rgb(228 228 231); font-size: .7rem; color: rgb(113 113 122);
        }
        .sihla-desglose-notas-texto { flex: 1 1 24rem; display: grid; gap: .25rem; }
        .sihla-desglose-notas p { margin: 0; }
        .sihla-desglose-rebaja { color: #b45309; }
        .sihla-desglose-enlace { font-weight: 600; color: #b45309; white-space: nowrap; text-decoration: none; }
        .sihla-desglose-enlace:hover { text-decoration: underline; }

        .dark .sihla-desglose-toggle { background: rgb(255 255 255 / .04); border-color: rgb(63 63 70); color: rgb(212 212 216); }
        .dark .sihla-desglose-toggle:hover { background: rgb(255 255 255 / .07); border-color: rgb(82 82 91); }
        .dark .sihla-desglose-toggle-texto { color: rgb(244 244 245); }
        .dark .sihla-desglose-barra { background: rgb(255 255 255 / .12); }
        .dark .sihla-desglose-panel { background: rgb(255 255 255 / .025); border-color: rgb(63 63 70); border-left-color: #f59e0b; }
        .dark .sihla-desglose-tabla th { color: rgb(161 161 170); border-bottom-color: rgb(63 63 70); }
        .dark .sihla-desglose-tabla td { color: rgb(228 228 231); border-bottom-color: rgb(255 255 255 / .06); }
        .dark .sihla-desglose-tabla tbody tr:hover td { background: rgb(255 255 255 / .03); }
        .dark .sihla-desglose-opcional { color: rgb(161 161 170); box-shadow: inset 0 0 0 1px rgb(82 82 91); }
        .dark .sihla-estado-incluido { color: rgb(161 161 170); background: rgb(255 255 255 / .06); }
        .dark .sihla-estado-completo { color: #4ade80; background: rgb(34 197 94 / .12); }
        .dark .sihla-estado-parcial { color: #fbbf24; background: rgb(251 191 36 / .12); }
        .dark .sihla-estado-pendiente { color: #38bdf8; background: rgb(56 189 248 / .12); }
        .dark .sihla-desglose-campo { background: rgb(24 24 27); color: rgb(244 244 245); border-color: rgb(82 82 91); }
        .dark .sihla-btn-entregar { color: #fbbf24; background: rgb(251 191 36 / .08); box-shadow: inset 0 0 0 1px rgb(251 191 36 / .35); }
        .dark .sihla-btn-entregar:hover { background: rgb(251 191 36 / .16); }
        .dark .sihla-btn-icono:hover { background: rgb(255 255 255 / .08); color: rgb(244 244 245); }
        .dark .sihla-desglose-suma td { color: rgb(244 244 245); background: rgb(255 255 255 / .04); border-top-color: rgb(63 63 70); }
        .dark .sihla-desglose-notas { border-top-color: rgb(63 63 70); color: rgb(161 161 170); }
        .dark .sihla-desglose-rebaja,
        .dark .sihla-desglose-enlace { color: #fbbf24; }
    </style>
@endif
