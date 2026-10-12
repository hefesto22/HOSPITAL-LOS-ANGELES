{{--
    Lo que va a decir la factura, en el modal de emitir (12-oct-2026).

    Los renglones de la cuenta —cantidad, qué y cuánto— y abajo los
    totales que el formato fiscal separa: exento, gravado e ISV. Es lo
    último que se mira antes de consumir un número del SAR que no vuelve.

    Con muchos renglones —una hospitalización larga— la lista se desplaza
    sola y los totales quedan a la vista.

    ⚠️ Estilos propios: el CSS del panel viene precompilado y las clases
    de Tailwind que el panel no usa no existen (§9.A7).
--}}
<div class="ldf">
    @if ($renglones === [])
        <p class="ldf-vacia">La cuenta no tiene renglones que facturar.</p>
    @else
        <div class="ldf-lista">
            @foreach ($renglones as $renglon)
                <div class="ldf-renglon">
                    <span class="ldf-cant">
                        {{ $renglon['cantidad'] }}
                        @if ($renglon['unidad'])
                            <span class="ldf-unidad">{{ $renglon['unidad'] }}</span>
                        @endif
                    </span>
                    <span class="ldf-texto">{{ $renglon['texto'] }}</span>
                    <span class="ldf-importe">{{ $renglon['importe'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <dl class="ldf-totales">
        @foreach ($totales as $linea)
            <div class="ldf-linea">
                <dt>{{ $linea['etiqueta'] }}</dt>
                <dd>{{ $linea['valor'] }}</dd>
            </div>
        @endforeach
        <div class="ldf-linea ldf-total">
            <dt>Total</dt>
            <dd>{{ $total }}</dd>
        </div>
    </dl>
</div>

<style>
    .ldf-vacia { font-size: .85rem; color: rgb(113 113 122); }
    .ldf-lista {
        max-height: 11rem; overflow-y: auto; overscroll-behavior: contain;
        margin: 0 -.35rem; padding: 0 .35rem;
    }
    .ldf-renglon {
        display: grid; grid-template-columns: 4.5rem minmax(0, 1fr) auto; column-gap: .75rem;
        align-items: baseline; padding: .4rem 0;
    }
    .ldf-renglon + .ldf-renglon { border-top: 1px solid rgb(244 244 245); }
    .ldf-cant { font-size: .82rem; font-weight: 600; color: rgb(63 63 70); font-variant-numeric: tabular-nums; }
    .ldf-unidad { display: block; font-size: .66rem; font-weight: 500; color: rgb(161 161 170); line-height: 1.2; }
    .ldf-texto { font-size: .85rem; color: rgb(24 24 27); overflow-wrap: anywhere; }
    .ldf-importe { font-size: .85rem; font-weight: 600; color: rgb(24 24 27); font-variant-numeric: tabular-nums; white-space: nowrap; }

    .ldf-totales { margin: .5rem 0 0; padding-top: .5rem; border-top: 1px solid rgb(228 228 231); }
    .ldf-linea { display: flex; justify-content: space-between; gap: 1rem; padding: .12rem 0; font-size: .8rem; color: rgb(82 82 91); }
    .ldf-linea dd { font-variant-numeric: tabular-nums; }
    .ldf-total { margin-top: .3rem; padding-top: .4rem; border-top: 1px solid rgb(228 228 231); font-size: .95rem; font-weight: 700; color: rgb(24 24 27); }

    .dark .ldf-renglon + .ldf-renglon { border-top-color: rgb(255 255 255 / .08); }
    .dark .ldf-cant { color: rgb(212 212 216); }
    .dark .ldf-unidad { color: rgb(113 113 122); }
    .dark .ldf-texto,
    .dark .ldf-importe,
    .dark .ldf-total { color: rgb(250 250 250); }
    .dark .ldf-totales,
    .dark .ldf-total { border-top-color: rgb(255 255 255 / .1); }
    .dark .ldf-linea { color: rgb(161 161 170); }
    .dark .ldf-total { color: rgb(250 250 250); }
</style>
