{{--
    ¿Quién paga qué?, en el modal de emitir la factura (13-oct-2026).

    Mauricio: «cuando escriban el monto que cubre el seguro, el resto lo paga
    el cliente, y el resto es como crédito del seguro a 30, 60 o 90 días; la
    verdad nunca se sabe cuánto tiempo». La doctora lo llama copago.

    Dos tarjetas, una por quien paga, con un color cada una y la barra arriba
    partida en los mismos dos colores: de un vistazo se ve qué parte es de
    quién. La del seguro dice que es CRÉDITO —se le factura y paga después,
    con el plazo como referencia y no como promesa—; la del paciente dice que
    es lo que se cobra HOY. Abajo, la suma, para que nadie la haga de cabeza.

    Se recalcula mientras se escribe. Con la cobertura del convenio vacía y
    nada escrito todavía, en vez de números dice qué falta.
--}}
<div class="qpq">
    @if ($porDecidir)
        <div class="qpq-pendiente">
            <p class="qpq-pendiente-titulo">Falta lo que autorizó {{ $seguro }}</p>
            <p class="qpq-pendiente-texto">
                Escribilo arriba, en porcentaje o en lempiras, y acá aparece cuánto le toca al paciente.
                La cuenta es de {{ $total }}.
            </p>
        </div>
    @else
        <div class="qpq-barra" role="img" aria-label="{{ $seguro }} {{ $parteDelSeguro }}, paciente {{ $parteDelPaciente }}">
            <span class="qpq-barra-seguro" style="width: {{ $anchoDelSeguro }}%"></span>
        </div>

        <div class="qpq-tarjetas">
            <div class="qpq-tarjeta qpq-del-seguro">
                <p class="qpq-quien"><span class="qpq-punto"></span>{{ $seguro }} · a crédito</p>
                <p class="qpq-monto">{{ $delSeguro }} <span class="qpq-parte">{{ $parteDelSeguro }}</span></p>
                @if ($seguroEnCero)
                    <p class="qpq-nota">No pone nada en esta cuenta: la paga entera el paciente.</p>
                @else
                    <p class="qpq-nota">Se le factura y queda por cobrar. {{ $plazo }}</p>
                @endif
                @if ($recorte)
                    <p class="qpq-aviso">{{ $recorte }}</p>
                @endif
            </div>

            <div class="qpq-tarjeta qpq-del-paciente">
                <p class="qpq-quien"><span class="qpq-punto"></span>Paciente · copago</p>
                <p class="qpq-monto">{{ $delPaciente }} <span class="qpq-parte">{{ $parteDelPaciente }}</span></p>
                <p class="qpq-nota">
                    @if ($pacienteEnCero)
                        No le toca pagar nada: el seguro cubre todo.
                    @elseif ($falta === null)
                        Ya lo pagó con sus abonos.
                    @elseif ($abonado)
                        Ya abonó {{ $abonado }}: hoy se cobran {{ $falta }}.
                    @else
                        Se cobra hoy, antes de emitir.
                    @endif
                </p>
            </div>
        </div>

        <p class="qpq-suma">{{ $delSeguro }} del seguro + {{ $delPaciente }} del paciente = {{ $total }}</p>
    @endif
</div>

<style>
    .qpq { display: grid; gap: .6rem; }

    .qpq-barra {
        display: flex; height: .5rem; border-radius: 999px; overflow: hidden;
        background: rgb(217 119 6 / .85);
    }
    .qpq-barra-seguro { display: block; height: 100%; background: rgb(37 99 235); }

    .qpq-tarjetas { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
    .qpq-tarjeta {
        padding: .65rem .85rem; border-radius: .75rem;
        border: 1px solid var(--qpq-borde); background: var(--qpq-fondo);
    }
    .qpq-del-seguro { --qpq-color: rgb(29 78 216); --qpq-borde: rgb(37 99 235 / .3); --qpq-fondo: rgb(37 99 235 / .06); }
    .qpq-del-paciente { --qpq-color: rgb(180 83 9); --qpq-borde: rgb(217 119 6 / .35); --qpq-fondo: rgb(217 119 6 / .07); }

    .qpq-quien {
        display: flex; align-items: center; gap: .4rem;
        font-size: .7rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
        color: var(--qpq-color);
    }
    .qpq-punto { width: .55rem; height: .55rem; border-radius: 999px; background: currentColor; flex: none; }
    .qpq-monto {
        margin-top: .15rem; font-size: 1.35rem; font-weight: 700; line-height: 1.25;
        color: rgb(24 24 27); font-variant-numeric: tabular-nums;
    }
    .qpq-parte { font-size: .8rem; font-weight: 600; color: var(--qpq-color); margin-left: .2rem; }
    .qpq-nota { margin-top: .2rem; font-size: .8rem; line-height: 1.35; color: rgb(82 82 91); }
    .qpq-aviso { margin-top: .3rem; font-size: .76rem; color: rgb(180 83 9); }

    .qpq-suma { font-size: .78rem; color: rgb(113 113 122); font-variant-numeric: tabular-nums; text-align: right; }

    .qpq-pendiente {
        padding: .7rem .9rem; border-radius: .75rem;
        border: 1px dashed rgb(217 119 6 / .55); background: rgb(217 119 6 / .06);
    }
    .qpq-pendiente-titulo { font-size: .9rem; font-weight: 700; color: rgb(180 83 9); }
    .qpq-pendiente-texto { margin-top: .15rem; font-size: .82rem; color: rgb(82 82 91); }

    .dark .qpq-del-seguro { --qpq-color: rgb(147 197 253); --qpq-borde: rgb(96 165 250 / .3); --qpq-fondo: rgb(59 130 246 / .1); }
    .dark .qpq-del-paciente { --qpq-color: rgb(252 211 77); --qpq-borde: rgb(251 191 36 / .3); --qpq-fondo: rgb(245 158 11 / .1); }
    .dark .qpq-barra { background: rgb(245 158 11 / .8); }
    .dark .qpq-barra-seguro { background: rgb(59 130 246); }
    .dark .qpq-monto { color: rgb(250 250 250); }
    .dark .qpq-nota,
    .dark .qpq-pendiente-texto { color: rgb(212 212 216); }
    .dark .qpq-aviso,
    .dark .qpq-pendiente-titulo { color: rgb(252 211 77); }
    .dark .qpq-suma { color: rgb(161 161 170); }
</style>
