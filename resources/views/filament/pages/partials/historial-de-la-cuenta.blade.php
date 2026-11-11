{{--
    El historial de una cuenta: agregó, quitó, cambió, cobró, facturó,
    anuló — quién, cuándo y con qué motivo (ver `HistorialDeLaCuenta`).

    Vive en su propio parcial desde el 11-nov-2026 (Mauricio: «las que ya
    fueron facturadas deben tener la bitácora de esa cuenta, en caso de que
    quieran revisar quién hizo qué»). Se ve en dos lugares:

      · Al pie de la lista del modal de cargar, plegado: es para el cambio
        de turno, que necesita lo último y nada más.
      · En «Ver» de Todas las cuentas, abierto y suelto: es para revisar
        una cuenta que ya se facturó y no se puede abrir en el modal.

    Parámetros: `$cuenta`; `$abierto` (empieza desplegado) y `$suelto`
    (con su propio borde, fuera de la caja de la lista).

    Abierto y cerrado viven en Alpine y no en un `<details>`: el morph de
    Livewire le borra el `open` a un `<details>` en cada re-render.
--}}
@php($abierto = (bool) ($abierto ?? false))
@php($suelto = (bool) ($suelto ?? false))

@php($historial = app(\App\Support\HistorialDeLaCuenta::class))
@php($movimientos = $historial->de($cuenta))
@php($visibles = 8)

@if ($movimientos !== [])
    @php($ultimo = $movimientos[0])

    <div @class(['sihla-historial', 'sihla-historial-suelto' => $suelto]) x-data="{ abierto: @js($abierto), todo: false }">
        <button
            type="button"
            class="sihla-historial-boton"
            x-on:click="abierto = ! abierto"
            x-bind:aria-expanded="abierto ? 'true' : 'false'"
        >
            <x-filament::icon icon="heroicon-o-clock" class="sihla-historial-icono" />

            <span class="sihla-historial-rotulo">
                Historial de la cuenta
                <span class="sihla-historial-cuantos">{{ count($movimientos) }}</span>
            </span>

            <span class="sihla-historial-ultimo">
                Lo último: {{ mb_strtolower($ultimo->tipo->verbo()) }} {{ $ultimo->titulo }},
                {{ $ultimo->cuando->isToday() ? 'hoy' : $ultimo->cuando->format('d/m') }} a las {{ $ultimo->cuando->format('H:i') }}
            </span>

            <x-filament::icon
                icon="heroicon-m-chevron-down"
                class="sihla-historial-flecha"
                x-bind:class="abierto && 'sihla-historial-flecha-abierta'"
            />
        </button>

        <div class="sihla-historial-cuerpo" x-show="abierto" x-cloak>
            @foreach ($historial->porDia($movimientos, $visibles) as $dia)
                <section class="sihla-historial-dia" @if ($dia['oculto']) x-show="todo" @endif>
                    <h4 class="sihla-historial-fecha">{{ $dia['dia'] }}</h4>

                    <ol class="sihla-historial-hilo">
                        @foreach ($dia['movimientos'] as $fila)
                            @php($m = $fila['movimiento'])

                            <li
                                class="sihla-mov sihla-mov-{{ $m->tipo->value }}"
                                @if ($fila['oculto']) x-show="todo" @endif
                            >
                                <span class="sihla-mov-hora">{{ $m->cuando->format('H:i') }}</span>
                                <span class="sihla-mov-punto" aria-hidden="true"></span>

                                <div class="sihla-mov-cuerpo">
                                    <p class="sihla-mov-titulo">
                                        <span class="sihla-mov-verbo">{{ $m->tipo->verbo() }}</span>
                                        <span @class(['sihla-mov-que', 'sihla-mov-que-muerto' => $m->yaNoCuenta])>{{ $m->titulo }}</span>
                                    </p>

                                    <p class="sihla-mov-meta">
                                        {{ $m->quien }}
                                        @if (filled($m->detalle))
                                            · {{ $m->detalle }}
                                        @endif
                                    </p>

                                    @if (filled($m->motivo))
                                        <p class="sihla-mov-motivo">«{{ $m->motivo }}»</p>
                                    @endif

                                    @if (filled($m->nota))
                                        <p class="sihla-mov-nota">{{ $m->nota }}</p>
                                    @endif
                                </div>

                                <span @class([
                                    'sihla-mov-monto',
                                    'sihla-mov-monto-resta' => $m->tipo->deshace(),
                                    'sihla-mov-monto-muerto' => $m->yaNoCuenta,
                                ])>{{ $m->monto }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach

            @if (count($movimientos) > $visibles)
                <button type="button" class="sihla-historial-mas" x-show="! todo" x-on:click="todo = true">
                    Ver {{ count($movimientos) - $visibles === 1 ? 'el anterior' : 'los '.(count($movimientos) - $visibles).' anteriores' }}
                </button>
            @endif
        </div>
    </div>
@elseif ($suelto)
    <p class="sihla-historial-vacio">Esta cuenta todavía no tiene movimientos.</p>
@endif

<style>
    /*
     * ── El historial ───────────────────────────────────────────────
     * Un hilo con un punto por movimiento, del color de lo que pasó:
     * gris lo que se agregó, rojo lo que se quitó o se anuló, ámbar
     * lo que cambió, verde la plata que entró, azul la factura.
     */
    .sihla-historial {
        --sh-linea: rgb(228 228 231);
        --sh-texto: rgb(39 39 42);
        --sh-texto-2: rgb(82 82 91);
        --sh-tenue: rgb(113 113 122);
        --sh-fondo: rgb(255 255 255);
        --sh-suave: rgb(250 250 250);
        --sh-rojo: rgb(190 18 60);
        --sh-verde: rgb(21 128 61);

        border-top: 1px solid var(--sh-linea);
    }
    .dark .sihla-historial {
        --sh-linea: rgb(63 63 70);
        --sh-texto: rgb(244 244 245);
        --sh-texto-2: rgb(212 212 216);
        --sh-tenue: rgb(161 161 170);
        --sh-fondo: rgb(24 24 27);
        --sh-suave: rgb(39 39 42);
        --sh-rojo: rgb(251 113 133);
        --sh-verde: rgb(74 222 128);
    }
    .sihla-historial [x-cloak] { display: none !important; }

    .sihla-historial-boton {
        display: flex; align-items: center; gap: .55rem; flex-wrap: wrap;
        width: 100%; padding: .7rem .85rem; text-align: start;
        color: var(--sh-texto-2); cursor: pointer;
    }
    .sihla-historial-boton:hover { background: var(--sh-suave); }
    .sihla-historial-boton:focus-visible { outline: 2px solid rgb(217 119 6); outline-offset: -2px; }
    .sihla-historial-icono { width: 1.05rem; height: 1.05rem; color: var(--sh-tenue); flex-shrink: 0; }
    .sihla-historial-rotulo { font-size: .85rem; font-weight: 600; color: var(--sh-texto); }
    .sihla-historial-cuantos {
        display: inline-block; min-width: 1.4rem; margin-left: .3rem; padding: 0 .4rem;
        border-radius: 999px; background: var(--sh-suave); border: 1px solid var(--sh-linea);
        font-size: .7rem; font-weight: 600; text-align: center; color: var(--sh-tenue);
    }
    .sihla-historial-ultimo {
        flex: 1 1 14rem; min-width: 0;
        font-size: .75rem; color: var(--sh-tenue);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .sihla-historial-flecha { width: 1.1rem; height: 1.1rem; color: var(--sh-tenue); transition: transform .15s; }
    .sihla-historial-flecha-abierta { transform: rotate(180deg); }
    @media (prefers-reduced-motion: reduce) { .sihla-historial-flecha { transition: none; } }

    .sihla-historial-cuerpo { padding: 0 .85rem .75rem; }
    .sihla-historial-fecha {
        margin: .6rem 0 .2rem 3.1rem;
        font-size: .72rem; font-weight: 600; color: var(--sh-tenue);
    }

    .sihla-historial-hilo { list-style: none; margin: 0; padding: 0; }
    .sihla-mov {
        --mov: rgb(161 161 170);
        --mov-fondo: rgb(161 161 170 / .14);

        display: grid; align-items: start; column-gap: .55rem;
        grid-template-columns: 2.5rem .75rem minmax(0, 1fr) auto;
        padding: .4rem 0;
        font-size: .8rem; font-variant-numeric: tabular-nums;
    }
    .sihla-mov-quito,
    .sihla-mov-anulo { --mov: rgb(225 29 72); --mov-fondo: rgb(225 29 72 / .12); }
    .sihla-mov-cambio { --mov: rgb(217 119 6); --mov-fondo: rgb(217 119 6 / .14); }
    .sihla-mov-cobro { --mov: rgb(22 163 74); --mov-fondo: rgb(22 163 74 / .13); }
    .sihla-mov-facturo { --mov: rgb(37 99 235); --mov-fondo: rgb(37 99 235 / .12); }

    .sihla-mov-hora { padding-top: .05rem; text-align: end; font-size: .72rem; color: var(--sh-tenue); }

    /* El hilo: una raya que une los puntos del mismo día. */
    .sihla-mov-punto { position: relative; align-self: stretch; }
    .sihla-mov-punto::before {
        content: ''; position: absolute; left: 50%; top: -.4rem; bottom: -.4rem;
        width: 1px; transform: translateX(-50%); background: var(--sh-linea);
    }
    .sihla-mov:first-child .sihla-mov-punto::before { top: .55rem; }
    .sihla-mov:last-child .sihla-mov-punto::before { bottom: calc(100% - .55rem); }
    .sihla-mov-punto::after {
        content: ''; position: absolute; left: 50%; top: .3rem;
        width: .55rem; height: .55rem; border-radius: 999px; transform: translateX(-50%);
        background: var(--mov); box-shadow: 0 0 0 3px var(--sh-fondo);
    }

    .sihla-mov-titulo { line-height: 1.35; color: var(--sh-texto); }
    .sihla-mov-verbo {
        display: inline-block; margin-right: .3rem; padding: 0 .35rem; border-radius: .3rem;
        font-size: .68rem; font-weight: 600; line-height: 1.35rem; vertical-align: .05em;
        color: var(--mov); background: var(--mov-fondo);
    }
    .sihla-mov-que { font-weight: 600; }
    .sihla-mov-que-muerto { color: var(--sh-tenue); }
    .sihla-mov-meta { margin-top: .1rem; font-size: .72rem; color: var(--sh-tenue); }
    .sihla-mov-motivo { margin-top: .15rem; font-size: .72rem; color: var(--sh-texto-2); }
    .sihla-mov-nota { margin-top: .15rem; font-size: .7rem; font-style: italic; color: var(--sh-tenue); }

    .sihla-mov-monto { padding-top: .05rem; white-space: nowrap; font-weight: 600; color: var(--sh-texto); }
    .sihla-mov-cobro .sihla-mov-monto:not(.sihla-mov-monto-muerto) { color: var(--sh-verde); }
    .sihla-mov-monto-resta { color: var(--sh-rojo); }
    .sihla-mov-monto-muerto { color: var(--sh-tenue); font-weight: 400; text-decoration: line-through; }

    .sihla-historial-mas {
        margin: .5rem 0 0 3.1rem; padding: .3rem .65rem; border-radius: .4rem;
        border: 1px solid var(--sh-linea); font-size: .75rem; color: var(--sh-texto-2); cursor: pointer;
    }
    .sihla-historial-mas:hover { background: var(--sh-suave); }

    @media (max-width: 40rem) {
        .sihla-mov { grid-template-columns: 2.3rem .75rem minmax(0, 1fr); }
        .sihla-mov-monto { grid-column: 3; padding-top: .15rem; }
        .sihla-historial-fecha, .sihla-historial-mas { margin-left: 2.9rem; }
    }

    /* Fuera de la caja de la lista: con su propio borde. */
    .sihla-historial-suelto { border: 1px solid var(--sh-linea); border-radius: .75rem; overflow: hidden; }
    .sihla-historial-suelto .sihla-historial-cuerpo { padding-top: .25rem; }
    .sihla-historial-vacio { font-size: .85rem; color: rgb(113 113 122); }
</style>
