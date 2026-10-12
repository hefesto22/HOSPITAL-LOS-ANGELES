{{--
    El resumen del presupuesto, en una franja arriba de todo (12-oct-2026).

    Lo que se mira mientras se cotiza, en UNA fila: cuánto va y en qué
    estado está, contra qué tope de la plantilla —con la barra y el aviso
    si se pasa—, y, ya agregado a la cuenta, cuánto se consumió con el
    color del semáforo (ADR-0008: avisa, no bloquea).

    Las casillas se reparten el ancho y bajan de renglón solas en el
    celular; las líneas entre ellas son el fondo del contenedor asomando
    por el espacio de 1 px, así quedan bien también cuando se apilan. Por
    eso todas crecen: una que no creciera dejaría asomar el fondo gris al
    quedar sola en su renglón.

    ⚠️ Estilos propios: el CSS del panel viene precompilado y las clases
    de Tailwind que el panel no usa no existen (§9.A7).
--}}
<div class="psr">
    <div class="psr-casilla psr-casilla-total">
        <div class="psr-cabeza">
            <span class="psr-rotulo">Total</span>
            <span class="psr-estado" style="--c: {{ $colorEstado }}">{{ $estado }}</span>
        </div>
        <strong class="psr-grande">L {{ $total }}</strong>
        <span class="psr-nota">{{ $renglones }} {{ $renglones === 1 ? 'renglón' : 'renglones' }}</span>
    </div>

    @if ($tope)
        <div class="psr-casilla psr-casilla-ancha">
            <div class="psr-cabeza">
                <span class="psr-rotulo" title="Tope de {{ $tope['plantilla'] }}">Tope de {{ $tope['plantilla'] }}</span>
                <span class="psr-cifra">L {{ $tope['tope'] }}</span>
            </div>
            <div class="psr-barra" style="--c: {{ $tope['excede'] ? '#dc2626' : '#0284c7' }}">
                <i style="width: {{ $tope['porcentaje'] }}%"></i>
            </div>
            @if ($tope['excede'])
                <span class="psr-aviso">Se pasa por L {{ $tope['exceso'] }} · se puede emitir igual</span>
            @else
                <span class="psr-nota">Va en el {{ $tope['porcentaje'] }} % del tope</span>
            @endif
        </div>
    @endif

    @if ($consumo)
        <div class="psr-casilla psr-casilla-ancha">
            <div class="psr-cabeza">
                <span class="psr-rotulo">Consumido en la cuenta</span>
                <span class="psr-cifra">L {{ $consumo['consumido'] }}</span>
            </div>
            <div class="psr-barra" style="--c: {{ $consumo['color'] }}">
                <i style="width: {{ $consumo['porcentaje'] }}%"></i>
            </div>
            <span class="psr-nota">
                {{ $consumo['porcentaje'] }} % de lo presupuestado
                @if ($consumo['cuenta'])
                    <span class="psr-entero">· {{ $consumo['cuenta'] }}</span>
                @endif
            </span>
        </div>
    @endif

    @if ($vence)
        <div class="psr-casilla psr-casilla-chica">
            <span class="psr-rotulo">Vale hasta</span>
            <strong class="psr-mediana">{{ $vence }}</strong>
        </div>
    @endif
</div>

<style>
    .psr {
        display: flex; flex-wrap: wrap; gap: 1px;
        border-radius: .75rem; overflow: hidden;
        background: rgb(228 228 231);
        box-shadow: 0 0 0 1px rgb(228 228 231), 0 1px 2px rgb(0 0 0 / .04);
    }
    .psr-casilla {
        flex: 1 1 11rem; min-width: 0;
        display: flex; flex-direction: column; justify-content: center; gap: .3rem;
        padding: .7rem 1rem; background: #fff;
    }
    .psr-casilla-total { flex: 1 1 13rem; }
    .psr-casilla-ancha { flex: 2 1 15rem; }
    .psr-casilla-chica { flex: 1 1 9rem; }
    .psr-cabeza { display: flex; align-items: center; justify-content: space-between; gap: .6rem; }
    .psr-rotulo {
        font-size: .68rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
        color: rgb(113 113 122); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .psr-estado {
        flex: none; font-size: .72rem; font-weight: 600; padding: .1rem .55rem; border-radius: 999px;
        color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 35%, transparent);
    }
    .psr-grande { font-size: 1.45rem; line-height: 1.1; font-weight: 700; color: rgb(24 24 27); font-variant-numeric: tabular-nums; }
    .psr-mediana { font-size: 1rem; line-height: 1.2; font-weight: 600; color: rgb(39 39 42); font-variant-numeric: tabular-nums; }
    .psr-cifra { flex: none; font-size: .875rem; font-weight: 600; color: rgb(39 39 42); font-variant-numeric: tabular-nums; }
    .psr-nota { font-size: .76rem; color: rgb(113 113 122); }
    .psr-entero { white-space: nowrap; }
    .psr-aviso { font-size: .76rem; font-weight: 500; color: rgb(185 28 28); }
    .psr-barra { height: .45rem; border-radius: 999px; background: rgb(228 228 231); overflow: hidden; }
    .psr-barra i { display: block; height: 100%; border-radius: 999px; background: var(--c); }

    .dark .psr { background: rgb(255 255 255 / .1); box-shadow: 0 0 0 1px rgb(255 255 255 / .1); }
    .dark .psr-casilla { background: rgb(24 24 27); }
    .dark .psr-grande { color: rgb(250 250 250); }
    .dark .psr-mediana,
    .dark .psr-cifra { color: rgb(228 228 231); }
    .dark .psr-barra { background: rgb(255 255 255 / .1); }
    .dark .psr-aviso { color: rgb(252 165 165); }
</style>
