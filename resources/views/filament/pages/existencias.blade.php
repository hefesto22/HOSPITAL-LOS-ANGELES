{{--
    Qué hay en cada estante, con la cinta de arriba diciendo cuánto vive
    en cada almacén.

    ─────────────────────────────────────────────────────────────────────
    LA CINTA NO ES UN ADORNO
    ─────────────────────────────────────────────────────────────────────

    Es la respuesta a «¿cuánto hay en bodega y cuánto en farmacia?» sin
    filtrar nada. En una tabla de doscientas filas agrupadas por producto,
    ese total no se puede leer — y es justamente el número con el que
    alguien decide si hay que bajar mercadería al carrito o no.

    Cada tarjeta es además un filtro: apretarla deja la tabla en ese
    almacén. Es el camino corto de «hay 40 renglones en bodega» a verlos.
--}}
<x-filament-panels::page>
    @php($estantes = $this->estantes())

    <div class="sihla-existencias">
        @if (count($estantes) > 0)
            <div class="sihla-estantes">
                @foreach ($estantes as $estante)
                    <button
                        type="button"
                        wire:key="estante-{{ $estante['id'] }}"
                        wire:click="verSoloEste({{ $estante['id'] }})"
                        wire:loading.attr="disabled"
                        @class(['sihla-estante', 'sihla-estante-activo' => $estante['activo']])
                    >
                        <span class="sihla-estante-nombre">{{ $estante['nombre'] }}</span>
                        <span class="sihla-estante-tipo">{{ $estante['tipo'] }}</span>

                        <span class="sihla-estante-cifras">
                            <span class="sihla-estante-renglones">
                                {{ number_format($estante['renglones']) }}
                                {{ \Illuminate\Support\Str::plural('renglón', $estante['renglones']) }}
                            </span>

                            @if ($estante['porVencer'] > 0)
                                <span class="sihla-estante-alerta">
                                    {{ $estante['porVencer'] }} por vencer
                                </span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        @endif

        {{ $this->table }}
    </div>

    <x-filament-actions::modals />

    <style>
        .sihla-existencias { display: flex; flex-direction: column; gap: 1.25rem; }

        .sihla-estantes {
            display: grid; gap: .625rem;
            grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
        }

        .sihla-estante {
            display: flex; flex-direction: column; gap: .15rem;
            padding: .75rem .875rem;
            text-align: left; cursor: pointer;
            border-radius: .75rem;
            border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            box-shadow: 0 1px 2px rgb(0 0 0 / .04);
            transition: border-color .12s ease, box-shadow .12s ease;
        }
        .sihla-estante:hover { border-color: rgb(161 161 170); }
        .sihla-estante:disabled { opacity: .55; cursor: progress; }
        .sihla-estante:focus-visible {
            outline: 2px solid rgb(245 158 11);
            outline: 2px solid var(--primary-500);
            outline-offset: 1px;
        }
        .dark .sihla-estante {
            border-color: rgb(63 63 70); background: rgb(24 24 27); box-shadow: none;
        }
        .dark .sihla-estante:hover { border-color: rgb(113 113 122); }

        /*
         * ⚠️ En Filament v5 `--primary-500` guarda un COLOR entero
         * (`oklch(…)`), no los tres canales sueltos: la transparencia va
         * con `color-mix`. Cada regla lleva antes su ámbar plano por si
         * el navegador no lo entiende.
         */
        .sihla-estante-activo, .sihla-estante-activo:hover {
            border-color: rgb(245 158 11);
            border-color: var(--primary-500);
            box-shadow: inset 0 0 0 1px rgb(245 158 11 / .35);
            box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 35%, transparent);
        }

        .sihla-estante-nombre {
            font-size: .875rem; font-weight: 600; line-height: 1.25;
            color: rgb(24 24 27);
        }
        .dark .sihla-estante-nombre { color: rgb(244 244 245); }

        .sihla-estante-tipo {
            font-size: .6875rem; font-weight: 500;
            letter-spacing: .03em; text-transform: uppercase;
            color: rgb(161 161 170);
        }

        .sihla-estante-cifras {
            display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem;
            margin-top: .35rem;
        }

        .sihla-estante-renglones {
            font-size: .8125rem; font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: rgb(63 63 70);
        }
        .dark .sihla-estante-renglones { color: rgb(212 212 216); }

        .sihla-estante-alerta {
            padding: .05rem .4rem; border-radius: 999px;
            font-size: .6875rem; font-weight: 600;
            background: rgb(254 243 199); color: rgb(146 64 14);
        }
        .dark .sihla-estante-alerta { background: rgb(69 26 3); color: rgb(253 230 138); }
    </style>
</x-filament-panels::page>
