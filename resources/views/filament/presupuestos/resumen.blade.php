{{--
    El resumen del presupuesto, a la derecha de sus datos (12-oct-2026).

    Lo que se mira mientras se cotiza: en qué estado está, cuánto va,
    contra qué tope de la plantilla —con la barra y el aviso si se pasa—
    y, ya agregado a la cuenta, cuánto se consumió, con el color del
    semáforo (ADR-0008: avisa, no bloquea).

    ⚠️ Estilos propios: el CSS del panel viene precompilado y las clases
    de Tailwind que el panel no usa no existen (§9.A7).
--}}
<div class="psr">
    <div class="psr-fila">
        <span class="psr-rotulo">Estado</span>
        <span class="psr-estado" style="--c: {{ $colorEstado }}">{{ $estado }}</span>
    </div>

    <div class="psr-total">
        <span class="psr-rotulo">Total</span>
        <strong>L {{ $total }}</strong>
        <span class="psr-nota">{{ $renglones }} {{ $renglones === 1 ? 'renglón' : 'renglones' }}</span>
    </div>

    @if ($tope)
        <div class="psr-bloque">
            <div class="psr-fila">
                <span class="psr-rotulo">Tope de {{ $tope['plantilla'] }}</span>
                <span class="psr-cifra">L {{ $tope['tope'] }}</span>
            </div>
            <div class="psr-barra" style="--c: {{ $tope['excede'] ? '#dc2626' : '#0284c7' }}">
                <i style="width: {{ $tope['porcentaje'] }}%"></i>
            </div>
            @if ($tope['excede'])
                <p class="psr-aviso">Se pasa por L {{ $tope['exceso'] }}. Se puede emitir igual: revisá si el caso lo justifica.</p>
            @else
                <p class="psr-nota">Va en el {{ $tope['porcentaje'] }} % del tope.</p>
            @endif
        </div>
    @endif

    @if ($consumo)
        <div class="psr-bloque">
            <div class="psr-fila">
                <span class="psr-rotulo">Consumido en la cuenta</span>
                <span class="psr-cifra">L {{ $consumo['consumido'] }}</span>
            </div>
            <div class="psr-barra" style="--c: {{ $consumo['color'] }}">
                <i style="width: {{ $consumo['porcentaje'] }}%"></i>
            </div>
            <p class="psr-nota">
                {{ $consumo['porcentaje'] }} % de lo presupuestado
                @if ($consumo['cuenta'])
                    · {{ $consumo['cuenta'] }}
                @endif
            </p>
        </div>
    @endif

    @if ($vence)
        <div class="psr-fila psr-pie">
            <span class="psr-rotulo">Vale hasta</span>
            <span class="psr-cifra">{{ $vence }}</span>
        </div>
    @endif
</div>

<style>
    .psr { display: grid; gap: .9rem; }
    .psr-fila { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
    .psr-rotulo { font-size: .7rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgb(113 113 122); }
    .psr-estado {
        font-size: .75rem; font-weight: 600; padding: .15rem .6rem; border-radius: 999px;
        color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 35%, transparent);
    }
    .psr-total { display: grid; gap: .15rem; padding: .85rem 1rem; border-radius: .75rem; background: rgb(244 244 245); }
    .psr-total strong { font-size: 1.6rem; line-height: 1.15; font-weight: 700; color: rgb(24 24 27); font-variant-numeric: tabular-nums; }
    .psr-nota { font-size: .78rem; color: rgb(113 113 122); }
    .psr-cifra { font-size: .875rem; font-weight: 600; color: rgb(39 39 42); font-variant-numeric: tabular-nums; }
    .psr-bloque { display: grid; gap: .4rem; padding-top: .9rem; border-top: 1px solid rgb(228 228 231); }
    .psr-barra { height: .5rem; border-radius: 999px; background: rgb(228 228 231); overflow: hidden; }
    .psr-barra i { display: block; height: 100%; border-radius: 999px; background: var(--c); }
    .psr-aviso { font-size: .78rem; font-weight: 500; color: rgb(185 28 28); }
    .psr-pie { padding-top: .9rem; border-top: 1px solid rgb(228 228 231); }

    .dark .psr-total { background: rgb(255 255 255 / .05); }
    .dark .psr-total strong { color: rgb(250 250 250); }
    .dark .psr-cifra { color: rgb(228 228 231); }
    .dark .psr-bloque,
    .dark .psr-pie { border-top-color: rgb(255 255 255 / .08); }
    .dark .psr-barra { background: rgb(255 255 255 / .1); }
    .dark .psr-aviso { color: rgb(252 165 165); }
</style>
