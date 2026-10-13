{{--
    El expediente del paciente: sus carpetas y sus atenciones (12-oct-2026).

    Cada atención entra cuando se factura su cuenta y sale si la factura
    se anula (ver AtencionesDelPaciente). La más reciente arriba y abierta;
    las demás muestran el resumen y el detalle a un clic.

    Sin montos: el expediente lo lee el médico, y el médico no ve costos.

    Colores literales con fichas propias que cambian con `.dark`: el CSS
    de Filament viene precompilado y `rgb(var(--gray-200))` no sirve en
    Filament 5 (las variables traen oklch completo).
--}}
@php
    /** @var list<\App\Support\AtencionDelPaciente> $atenciones */
    /** @var list<array{numero: string, sede: string, estado: string}> $carpetas */
@endphp

<div class="sihla-exp">
    {{-- Las carpetas: una por sede --}}
    <div class="sihla-exp-carpetas">
        <span class="sihla-exp-rotulo">{{ count($carpetas) === 1 ? 'Expediente' : 'Expedientes' }}</span>

        @forelse ($carpetas as $carpeta)
            <span class="sihla-exp-carpeta">
                <x-filament::icon icon="heroicon-o-folder" class="sihla-exp-carpeta-icono" />
                <strong>{{ $carpeta['numero'] }}</strong>
                <span>{{ $carpeta['sede'] }} · {{ $carpeta['estado'] }}</span>
            </span>
        @empty
            <span class="sihla-exp-nada">Todavía no se le abrió expediente en ninguna sede.</span>
        @endforelse
    </div>

    {{-- Las atenciones --}}
    <div class="sihla-exp-cabecera">
        <h3 class="sihla-exp-titulo">
            Atenciones
            <span class="sihla-exp-cuantas">{{ count($atenciones) }}</span>
        </h3>
        <p class="sihla-exp-ayuda">Cada vez que se factura su cuenta, la atención queda guardada acá. Si la factura se anula, sale.</p>
    </div>

    @if ($atenciones === [])
        <div class="sihla-exp-vacio">
            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="sihla-exp-vacio-icono" />
            <p>Todavía no tiene atenciones cerradas.</p>
        </div>
    @else
        <ol class="sihla-exp-lista">
            @foreach ($atenciones as $i => $atencion)
                <li class="sihla-exp-atencion" x-data="{ abierta: {{ $i === 0 ? 'true' : 'false' }} }" wire:key="atencion-{{ $atencion->encuentro }}">
                    <div class="sihla-exp-atencion-cabeza">
                        <x-filament::badge :color="$atencion->tipo->color()" :icon="$atencion->tipo->icono()">
                            {{ $atencion->tipo->etiqueta() }}
                        </x-filament::badge>

                        <span class="sihla-exp-fechas">
                            {{ $atencion->ingreso->format('d/m/Y') }}
                            @unless ($atencion->ingreso->isSameDay($atencion->egreso))
                                → {{ $atencion->egreso->format('d/m/Y') }}
                            @endunless
                            <span class="sihla-exp-estancia">· {{ $atencion->estancia }}</span>
                        </span>

                        <span class="sihla-exp-numero">{{ $atencion->encuentro }}</span>
                    </div>

                    <p class="sihla-exp-descripcion">{{ $atencion->descripcion }}</p>

                    <button type="button" class="sihla-exp-boton" x-on:click="abierta = ! abierta" x-bind:aria-expanded="abierta ? 'true' : 'false'">
                        <span x-text="abierta ? 'Ocultar el detalle' : 'Ver el detalle'">Ver el detalle</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="sihla-exp-flecha" x-bind:class="abierta && 'sihla-exp-flecha-abierta'" />
                    </button>

                    <div class="sihla-exp-detalle" x-show="abierta" x-cloak>
                        @if ($atencion->motivo !== null)
                            <div class="sihla-exp-bloque">
                                <span class="sihla-exp-bloque-titulo">Motivo</span>
                                <p>{{ $atencion->motivo }}</p>
                            </div>
                        @endif

                        <div class="sihla-exp-bloque">
                            <span class="sihla-exp-bloque-titulo">Diagnóstico</span>
                            @if ($atencion->diagnosticos === [])
                                <p class="sihla-exp-tenue">Sin diagnóstico registrado.</p>
                            @else
                                <ul>
                                    @foreach ($atencion->diagnosticos as $diagnostico)
                                        <li>
                                            <span class="sihla-exp-codigo">{{ $diagnostico['codigo'] }}</span>
                                            {{ $diagnostico['descripcion'] }}
                                            @if ($diagnostico['principal'])
                                                <span class="sihla-exp-principal">principal</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        @foreach ($atencion->grupos as $grupo)
                            <div class="sihla-exp-bloque">
                                <span class="sihla-exp-bloque-titulo">
                                    <x-filament::icon :icon="$grupo['icono']" class="sihla-exp-bloque-icono" />
                                    {{ $grupo['titulo'] }}
                                </span>
                                <p class="sihla-exp-items">
                                    {{ implode(' · ', $grupo['items']) }}
                                    @if ($grupo['mas'] > 0)
                                        <span class="sihla-exp-tenue">· y {{ $grupo['mas'] }} más</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach

                        @if ($atencion->medicos !== [])
                            <div class="sihla-exp-bloque">
                                <span class="sihla-exp-bloque-titulo">
                                    <x-filament::icon icon="heroicon-o-user-circle" class="sihla-exp-bloque-icono" />
                                    {{ count($atencion->medicos) === 1 ? 'Médico' : 'Médicos' }}
                                </span>
                                <p>{{ implode(' · ', $atencion->medicos) }}</p>
                            </div>
                        @endif

                        <p class="sihla-exp-pie">{{ $atencion->expediente }} · {{ $atencion->sede }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>

<style>
    .sihla-exp {
        --se-fondo: rgb(255 255 255);
        --se-suave: rgb(250 250 250);
        --se-linea: rgb(228 228 231);
        --se-texto: rgb(24 24 27);
        --se-texto-2: rgb(63 63 70);
        --se-tenue: rgb(113 113 122);
        --se-acento: rgb(180 83 9);

        display: flex; flex-direction: column; gap: 1rem;
        color: var(--se-texto);
    }
    .dark .sihla-exp {
        --se-fondo: rgb(24 24 27);
        --se-suave: rgb(39 39 42);
        --se-linea: rgb(63 63 70);
        --se-texto: rgb(244 244 245);
        --se-texto-2: rgb(212 212 216);
        --se-tenue: rgb(161 161 170);
        --se-acento: rgb(251 191 36);
    }
    .sihla-exp [x-cloak] { display: none !important; }

    .sihla-exp-carpetas { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .sihla-exp-rotulo { font-size: .8rem; font-weight: 600; color: var(--se-tenue); margin-right: .25rem; }
    .sihla-exp-carpeta {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .3rem .7rem; border-radius: 999px;
        border: 1px solid var(--se-linea); background: var(--se-suave);
        font-size: .8rem; color: var(--se-texto-2);
    }
    .sihla-exp-carpeta strong { font-variant-numeric: tabular-nums; color: var(--se-texto); }
    .sihla-exp-carpeta-icono { width: 1rem; height: 1rem; color: var(--se-tenue); }
    .sihla-exp-nada { font-size: .85rem; color: var(--se-tenue); }

    .sihla-exp-cabecera { border-top: 1px solid var(--se-linea); padding-top: 1rem; }
    .sihla-exp-titulo { font-size: 1rem; font-weight: 600; }
    .sihla-exp-cuantas {
        display: inline-block; min-width: 1.5rem; margin-left: .35rem; padding: 0 .45rem;
        border-radius: 999px; border: 1px solid var(--se-linea); background: var(--se-suave);
        font-size: .75rem; font-weight: 600; text-align: center; color: var(--se-tenue);
    }
    .sihla-exp-ayuda { margin-top: .2rem; font-size: .8rem; color: var(--se-tenue); }

    .sihla-exp-vacio {
        display: flex; flex-direction: column; align-items: center; gap: .5rem;
        padding: 2rem 1rem; border: 1px dashed var(--se-linea); border-radius: .75rem;
        font-size: .9rem; color: var(--se-tenue);
    }
    .sihla-exp-vacio-icono { width: 1.75rem; height: 1.75rem; }

    .sihla-exp-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .75rem; }
    .sihla-exp-atencion {
        padding: 1rem 1.15rem; border-radius: .75rem;
        border: 1px solid var(--se-linea); background: var(--se-fondo);
    }
    .sihla-exp-atencion-cabeza { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
    .sihla-exp-fechas { font-size: .9rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .sihla-exp-estancia { font-weight: 400; color: var(--se-tenue); }
    .sihla-exp-numero { margin-left: auto; font-size: .75rem; color: var(--se-tenue); font-variant-numeric: tabular-nums; }

    .sihla-exp-descripcion { margin-top: .6rem; font-size: .9rem; line-height: 1.55; color: var(--se-texto-2); max-width: 72ch; }

    .sihla-exp-boton {
        display: inline-flex; align-items: center; gap: .25rem; margin-top: .5rem;
        font-size: .8rem; font-weight: 600; color: var(--se-acento); cursor: pointer;
    }
    .sihla-exp-boton:focus-visible { outline: 2px solid var(--se-acento); outline-offset: 2px; border-radius: .25rem; }
    .sihla-exp-flecha { width: 1rem; height: 1rem; transition: transform .15s; }
    .sihla-exp-flecha-abierta { transform: rotate(180deg); }
    @media (prefers-reduced-motion: reduce) { .sihla-exp-flecha { transition: none; } }

    .sihla-exp-detalle {
        display: grid; gap: .75rem 1.5rem; margin-top: .75rem; padding-top: .75rem;
        border-top: 1px solid var(--se-linea);
        grid-template-columns: minmax(0, 1fr);
    }
    @media (min-width: 48rem) { .sihla-exp-detalle { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .sihla-exp-bloque { font-size: .85rem; color: var(--se-texto-2); }
    .sihla-exp-bloque-titulo {
        display: flex; align-items: center; gap: .35rem; margin-bottom: .2rem;
        font-size: .75rem; font-weight: 600; color: var(--se-tenue);
    }
    .sihla-exp-bloque-icono { width: .95rem; height: .95rem; }
    .sihla-exp-bloque ul { list-style: none; margin: 0; padding: 0; }
    .sihla-exp-bloque li + li { margin-top: .15rem; }
    .sihla-exp-codigo { font-weight: 600; font-variant-numeric: tabular-nums; color: var(--se-texto); margin-right: .25rem; }
    .sihla-exp-principal {
        margin-left: .3rem; padding: 0 .35rem; border-radius: .25rem;
        font-size: .65rem; font-weight: 600; color: var(--se-acento); border: 1px solid currentColor;
    }
    .sihla-exp-items { line-height: 1.55; }
    .sihla-exp-tenue { color: var(--se-tenue); }
    .sihla-exp-pie { grid-column: 1 / -1; font-size: .75rem; color: var(--se-tenue); }
</style>
