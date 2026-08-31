{{--
    Cuánto cobró cada médico en el período.

    ─────────────────────────────────────────────────────────────────────
    LA CINTA CONTESTA LA PREGUNTA CON LA QUE SE ENTRA
    ─────────────────────────────────────────────────────────────────────

    La tabla ya suma por grupo, pero esos subtotales quedan repartidos
    entre cien renglones y solo se ven bajando. Acá arriba está el total
    de cada doctor, completo y sin desplazarse, que es lo que alguien
    viene a ver el último día del mes.

    Cada tarjeta es además un filtro: apretarla deja la tabla en ese
    médico, y volver a apretarla la suelta.
--}}
<x-filament-panels::page>
    @php($medicos = $this->medicos())

    <div class="sihla-honorarios">
        @if (count($medicos) > 0)
            <div class="sihla-periodo">
                <span class="sihla-periodo-rango">{{ $this->periodo() }}</span>
                <span class="sihla-periodo-total">{{ $this->totalDelPeriodo() }}</span>
            </div>

            <div class="sihla-medicos">
                @foreach ($medicos as $medico)
                    <button
                        type="button"
                        wire:key="medico-{{ $medico['id'] }}"
                        wire:click="verSoloEste({{ $medico['id'] }})"
                        wire:loading.attr="disabled"
                        @class(['sihla-medico', 'sihla-medico-activo' => $medico['activo']])
                    >
                        <span class="sihla-medico-nombre">{{ $medico['nombre'] }}</span>

                        @if ($medico['especialidad'] !== '')
                            <span class="sihla-medico-especialidad">{{ $medico['especialidad'] }}</span>
                        @endif

                        <span class="sihla-medico-cifras">
                            <span class="sihla-medico-total">{{ $medico['total'] }}</span>
                            <span class="sihla-medico-renglones">
                                {{ $medico['renglones'] }}
                                {{ \Illuminate\Support\Str::plural('honorario', $medico['renglones']) }}
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif

        {{ $this->table }}
    </div>

    <style>
        .sihla-honorarios { display: flex; flex-direction: column; gap: 1.25rem; }

        .sihla-periodo {
            display: flex; flex-wrap: wrap; align-items: baseline;
            justify-content: space-between; gap: .5rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid rgb(228 228 231);
        }
        .dark .sihla-periodo { border-color: rgb(63 63 70); }

        .sihla-periodo-rango {
            font-size: .75rem; font-weight: 600;
            letter-spacing: .04em; text-transform: uppercase;
            color: rgb(113 113 122);
        }

        .sihla-periodo-total {
            font-size: 1.375rem; font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: rgb(24 24 27);
        }
        .dark .sihla-periodo-total { color: rgb(244 244 245); }

        .sihla-medicos {
            display: grid; gap: .625rem;
            grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
        }

        .sihla-medico {
            display: flex; flex-direction: column; gap: .15rem;
            padding: .75rem .875rem;
            text-align: left; cursor: pointer;
            border-radius: .75rem;
            border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            box-shadow: 0 1px 2px rgb(0 0 0 / .04);
            transition: border-color .12s ease, box-shadow .12s ease;
        }
        .sihla-medico:hover { border-color: rgb(161 161 170); }
        .sihla-medico:disabled { opacity: .55; cursor: progress; }
        .sihla-medico:focus-visible {
            outline: 2px solid rgb(245 158 11);
            outline: 2px solid var(--primary-500);
            outline-offset: 1px;
        }
        .dark .sihla-medico {
            border-color: rgb(63 63 70); background: rgb(24 24 27); box-shadow: none;
        }
        .dark .sihla-medico:hover { border-color: rgb(113 113 122); }

        /*
         * ⚠️ En Filament v5 `--primary-500` guarda un COLOR entero
         * (`oklch(…)`), no los tres canales sueltos: la transparencia va
         * con `color-mix`. Cada regla lleva antes su ámbar plano por si
         * el navegador no lo entiende.
         */
        .sihla-medico-activo, .sihla-medico-activo:hover {
            border-color: rgb(245 158 11);
            border-color: var(--primary-500);
            box-shadow: inset 0 0 0 1px rgb(245 158 11 / .35);
            box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 35%, transparent);
        }

        .sihla-medico-nombre {
            font-size: .875rem; font-weight: 600; line-height: 1.25;
            color: rgb(24 24 27);
        }
        .dark .sihla-medico-nombre { color: rgb(244 244 245); }

        .sihla-medico-especialidad {
            font-size: .6875rem; font-weight: 500;
            letter-spacing: .03em; text-transform: uppercase;
            color: rgb(161 161 170);
        }

        .sihla-medico-cifras {
            display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem;
            margin-top: .35rem;
        }

        .sihla-medico-total {
            font-size: 1rem; font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: rgb(24 24 27);
        }
        .dark .sihla-medico-total { color: rgb(244 244 245); }

        .sihla-medico-renglones {
            font-size: .75rem; font-weight: 500;
            color: rgb(113 113 122);
        }
    </style>
</x-filament-panels::page>
