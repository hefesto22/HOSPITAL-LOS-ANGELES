{{--
    Quién paga qué, en el modal de emitir la factura (13 y 14-oct-2026).

    Mauricio: «cuando escriban el monto que cubre el seguro, el resto lo paga
    el cliente, y el resto es como crédito del seguro a 30, 60 o 90 días».
    La doctora lo llama copago.

    Una barra partida en dos colores y dos cuadros, uno por quien paga: lo
    del seguro es CRÉDITO —se le factura y paga después, con el plazo como
    referencia—; lo del paciente es lo que se cobra HOY.

    COMPACTO a propósito (14-oct-2026: «me gustaban los cuadros detallados,
    solo que eran muy grandes»): un renglón de quién, uno de cuánto y uno de
    nota. El modal entero tiene que caber sin scroll.

    Con la cobertura del convenio vacía y nada escrito todavía, en vez de
    números dice qué falta.
--}}
<div class="qpq">
    @if ($porDecidir)
        <p class="qpq-pendiente">
            <strong>Falta lo que autorizó el seguro.</strong>
            Escribilo arriba y acá aparece cuánto le toca al paciente.
        </p>
    @else
        <div class="qpq-barra" role="img" aria-label="{{ $seguro }} {{ $parteDelSeguro }}, paciente {{ $parteDelPaciente }}">
            <span class="qpq-barra-seguro" style="width: {{ $anchoDelSeguro }}%"></span>
        </div>

        <div class="qpq-cuadros">
            <div class="qpq-cuadro qpq-del-seguro">
                <p class="qpq-quien" title="{{ $seguro }}"><span class="qpq-punto"></span>{{ $seguro }} · a crédito</p>
                <p class="qpq-monto">{{ $delSeguro }} <span class="qpq-parte">{{ $parteDelSeguro }}</span></p>
                <p class="qpq-nota">{{ $notaDelSeguro }}</p>
            </div>

            <div class="qpq-cuadro qpq-del-paciente">
                <p class="qpq-quien"><span class="qpq-punto"></span>Paciente · copago</p>
                <p class="qpq-monto">{{ $delPaciente }} <span class="qpq-parte">{{ $parteDelPaciente }}</span></p>
                <p class="qpq-nota">{{ $notaDelPaciente }}</p>
            </div>
        </div>
    @endif
</div>

<style>
    .qpq { display: grid; gap: .45rem; }

    .qpq-barra { display: flex; height: .3rem; border-radius: 999px; overflow: hidden; background: rgb(217 119 6 / .85); }
    .qpq-barra-seguro { display: block; height: 100%; background: rgb(37 99 235); }

    .qpq-cuadros { display: grid; gap: .5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .qpq-cuadro { padding: .4rem .65rem; border-radius: .6rem; border: 1px solid var(--qpq-borde); background: var(--qpq-fondo); min-width: 0; }
    .qpq-del-seguro { --qpq-color: rgb(29 78 216); --qpq-borde: rgb(37 99 235 / .3); --qpq-fondo: rgb(37 99 235 / .06); }
    .qpq-del-paciente { --qpq-color: rgb(180 83 9); --qpq-borde: rgb(217 119 6 / .35); --qpq-fondo: rgb(217 119 6 / .07); }

    .qpq-quien {
        display: flex; align-items: center; gap: .35rem;
        font-size: .66rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
        color: var(--qpq-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .qpq-punto { width: .45rem; height: .45rem; border-radius: 999px; background: currentColor; flex: none; }
    .qpq-monto { font-size: 1.05rem; font-weight: 700; line-height: 1.3; color: rgb(24 24 27); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .qpq-parte { font-size: .72rem; font-weight: 600; color: var(--qpq-color); margin-left: .15rem; }
    .qpq-nota { font-size: .72rem; line-height: 1.3; color: rgb(82 82 91); }

    .qpq-pendiente {
        padding: .45rem .7rem; border-radius: .6rem; font-size: .8rem; color: rgb(82 82 91);
        border: 1px dashed rgb(217 119 6 / .55); background: rgb(217 119 6 / .06);
    }
    .qpq-pendiente strong { color: rgb(180 83 9); }

    .dark .qpq-del-seguro { --qpq-color: rgb(147 197 253); --qpq-borde: rgb(96 165 250 / .3); --qpq-fondo: rgb(59 130 246 / .1); }
    .dark .qpq-del-paciente { --qpq-color: rgb(252 211 77); --qpq-borde: rgb(251 191 36 / .3); --qpq-fondo: rgb(245 158 11 / .1); }
    .dark .qpq-barra { background: rgb(245 158 11 / .8); }
    .dark .qpq-barra-seguro { background: rgb(59 130 246); }
    .dark .qpq-monto { color: rgb(250 250 250); }
    .dark .qpq-nota,
    .dark .qpq-pendiente { color: rgb(212 212 216); }
    .dark .qpq-pendiente strong { color: rgb(252 211 77); }
</style>
