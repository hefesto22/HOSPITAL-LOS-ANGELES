{{--
    Los recibos que ya tiene la cuenta, en el modal de abono (12-oct-2026).

    El último arriba. Cada uno dice cuánto entró y cómo, cuándo, quién lo
    recibió y quién lo dejó. Mientras el turno en que se recibió siga
    abierto, se puede corregir —se anula y sale uno nuevo— o anular. Con el
    turno cerrado la plata ya se contó: eso es una devolución, y lo dice.

    Va en una columna angosta, a la derecha del formulario: el monto sube a
    la línea del número y los botones bajan a su propio renglón. Con muchos
    recibos la lista se desplaza sola y el modal no crece.

    Los botones montan la acción ENCIMA del modal de abono; al terminar se
    vuelve a él, con la lista al día.

    El modal de factura usa esta misma lista con `acciones` en falso: ahí
    se mira lo que ya entró, no se corrige.
--}}
<ul class="rcb">
    @foreach ($recibos as $recibo)
        <li @class(['rcb-fila', 'rcb-anulado' => $recibo['anulado']]) wire:key="recibo-{{ $recibo['id'] }}">
            <div class="rcb-arriba">
                <span class="rcb-numero">{{ $recibo['numero'] }}</span>
                @if ($recibo['anulado'])
                    <span class="rcb-marca">Anulado</span>
                @endif
                <span class="rcb-monto">L {{ $recibo['total'] }}</span>
            </div>

            <p class="rcb-medios">{{ $recibo['medios'] }}</p>
            <p class="rcb-gente">
                {{ $recibo['cuando'] }} · Recibió {{ $recibo['quien'] }}@if ($recibo['entrego']) · lo dejó {{ $recibo['entrego'] }}@endif
            </p>

            @if ($recibo['anulado'] && $recibo['motivo'])
                <p class="rcb-motivo">{{ $recibo['motivo'] }}</p>
            @elseif ($recibo['nota'])
                <p class="rcb-nota">{{ $recibo['nota'] }}</p>
            @endif

            @if (($acciones ?? true) && $recibo['tocable'])
                <div class="rcb-acciones">
                    <button
                        type="button"
                        class="rcb-boton"
                        wire:click="mountAction('corregirAbono', { recibo: {{ $recibo['id'] }} })"
                        wire:loading.attr="disabled"
                    >Corregir</button>
                    <button
                        type="button"
                        class="rcb-boton rcb-peligro"
                        wire:click="mountAction('anularAbono', { recibo: {{ $recibo['id'] }} })"
                        wire:loading.attr="disabled"
                    >Anular</button>
                </div>
            @elseif (($acciones ?? true) && ! $recibo['anulado'])
                <p class="rcb-cerrado">Turno cerrado · se corrige con una devolución</p>
            @endif
        </li>
    @endforeach
</ul>

<style>
    .rcb {
        list-style: none; margin: 0 -.35rem; padding: 0 .35rem;
        max-height: 26rem; overflow-y: auto; overscroll-behavior: contain;
    }
    .rcb-fila { padding: .7rem 0; }
    .rcb-fila:first-child { padding-top: .1rem; }
    .rcb-fila + .rcb-fila { border-top: 1px solid rgb(244 244 245); }
    .rcb-arriba { display: flex; align-items: baseline; gap: .5rem; }
    .rcb-numero { font-size: .875rem; font-weight: 600; color: rgb(24 24 27); font-variant-numeric: tabular-nums; }
    .rcb-marca {
        font-size: .68rem; font-weight: 600; padding: .05rem .5rem; border-radius: 999px;
        color: rgb(185 28 28); background: rgb(254 242 242); box-shadow: inset 0 0 0 1px rgb(252 165 165 / .7);
    }
    .rcb-monto {
        margin-left: auto; font-size: .95rem; font-weight: 700; color: rgb(22 163 74);
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .rcb-medios { font-size: .8rem; color: rgb(63 63 70); margin-top: .15rem; }
    .rcb-gente { font-size: .74rem; color: rgb(113 113 122); margin-top: .05rem; font-variant-numeric: tabular-nums; }
    .rcb-nota,
    .rcb-motivo { font-size: .74rem; margin-top: .25rem; white-space: pre-line; }
    .rcb-nota { color: rgb(113 113 122); }
    .rcb-motivo { color: rgb(185 28 28); }
    .rcb-acciones { display: flex; gap: .4rem; justify-content: flex-end; margin-top: .5rem; }
    .rcb-boton {
        height: 1.85rem; padding: 0 .7rem; border-radius: .5rem; border: 0; cursor: pointer;
        font-size: .78rem; font-weight: 600; color: rgb(39 39 42); background: rgb(255 255 255);
        box-shadow: inset 0 0 0 1px rgb(212 212 216);
    }
    .rcb-boton:hover { background: rgb(244 244 245); }
    .rcb-boton:focus-visible { outline: 2px solid rgb(217 119 6); outline-offset: 1px; }
    .rcb-boton:disabled { opacity: .5; cursor: progress; }
    .rcb-peligro { color: rgb(185 28 28); }
    .rcb-cerrado { font-size: .72rem; color: rgb(161 161 170); margin-top: .35rem; text-align: right; }

    .rcb-anulado .rcb-numero,
    .rcb-anulado .rcb-medios { color: rgb(161 161 170); }
    .rcb-anulado .rcb-monto { color: rgb(161 161 170); text-decoration: line-through; font-weight: 500; }

    .dark .rcb-fila + .rcb-fila { border-top-color: rgb(255 255 255 / .08); }
    .dark .rcb-numero { color: rgb(250 250 250); }
    .dark .rcb-medios { color: rgb(212 212 216); }
    .dark .rcb-gente,
    .dark .rcb-nota { color: rgb(161 161 170); }
    .dark .rcb-cerrado { color: rgb(113 113 122); }
    .dark .rcb-marca { color: rgb(252 165 165); background: rgb(239 68 68 / .12); box-shadow: inset 0 0 0 1px rgb(239 68 68 / .35); }
    .dark .rcb-motivo { color: rgb(252 165 165); }
    .dark .rcb-monto { color: rgb(74 222 128); }
    .dark .rcb-boton { color: rgb(244 244 245); background: rgb(255 255 255 / .05); box-shadow: inset 0 0 0 1px rgb(255 255 255 / .12); }
    .dark .rcb-boton:hover { background: rgb(255 255 255 / .1); }
    .dark .rcb-peligro { color: rgb(252 165 165); }
    .dark .rcb-anulado .rcb-numero,
    .dark .rcb-anulado .rcb-medios,
    .dark .rcb-anulado .rcb-monto { color: rgb(113 113 122); }
</style>
