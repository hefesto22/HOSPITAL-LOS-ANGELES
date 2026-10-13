{{--
    Honorarios por médico — segundo rediseño del 13-oct-2026.

    Cuenta SOLO lo facturado en el período (ver la clase): sin negativos
    y sin mezclar un mes con otro.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ ASÍ («se ve muy feo», con razón)
    ─────────────────────────────────────────────────────────────────────

    El primer intento era una pila de cajas con borde —cuatro botones,
    dos fechas, tres tarjetas de cifras, una tarjeta por médico— cada una
    con su color de acento. Mucho ruido para tres preguntas. Ahora:

    1. Una barra: el período como un control segmentado (como las
       pestañas del panel) y las dos fechas al lado.
    2. UN panel, con el mismo aspecto que las secciones de Filament: el
       total arriba y, debajo, los médicos como una tabla chica con los
       números alineados. Tocar una fila deja el detalle en ese
       médico. Los números alineados en columna se comparan de un
       vistazo; repartidos en tarjetas, no.
    3. El detalle, renglón por renglón.

    ⚠️ `wire:model.live.change` en las fechas, no `.live`: con `.live`
    cada tecla manda la fecha a medio escribir, que llega vacía y la
    página la reemplaza por el inicio del mes mientras se teclea.

    ⚠️ Colores literales con variables propias y `.dark`: en Filament 5
    las variables del panel son oklch y `rgb(var(--gray-200))` no vale.
    El `<style>` va acá porque el CSS del panel está precompilado.
--}}
<x-filament-panels::page>
    @php
        $resumen = $this->resumen();
        $medicos = $this->medicos();
        $elegido = collect($medicos)->firstWhere('activo', true);
    @endphp

    <div class="sh">
        {{-- 1. El período --}}
        <div class="sh-barra">
            <div class="sh-seg" role="group" aria-label="Período">
                @foreach ($this->periodos() as $atajo)
                    <button
                        type="button"
                        wire:key="atajo-{{ $atajo['clave'] }}"
                        wire:click="usarPeriodo('{{ $atajo['clave'] }}')"
                        title="{{ $atajo['rango'] }}"
                        @class(['sh-seg-boton', 'sh-seg-activo' => $atajo['activo']])
                        @if ($atajo['activo']) aria-pressed="true" @endif
                    >{{ $atajo['etiqueta'] }}</button>
                @endforeach
            </div>

            <div class="sh-rango">
                <input type="date" wire:model.live.change="desde" aria-label="Desde">
                <span class="sh-rango-flecha" aria-hidden="true">→</span>
                <input type="date" wire:model.live.change="hasta" aria-label="Hasta">
            </div>
        </div>

        {{-- 2. El total y los médicos, en un solo panel --}}
        <div class="sh-panel">
            <div class="sh-cabeza">
                <span class="sh-etiqueta">Total facturado · {{ $this->periodo() }}</span>
                <span class="sh-valor">{{ $resumen['total'] }}</span>
                <span class="sh-nota">
                    {{ $resumen['renglones'] }} {{ \Illuminate\Support\Str::plural('honorario', $resumen['renglones']) }}
                    · {{ $resumen['medicos'] }} {{ $resumen['medicos'] === 1 ? 'médico' : 'médicos' }}
                </span>
            </div>

            @if (count($medicos) > 0)
                <table class="sh-medicos">
                    <thead>
                        <tr>
                            <th>Médico</th>
                            <th class="sh-num sh-ocultable">Honorarios</th>
                            <th class="sh-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($medicos as $medico)
                            <tr
                                wire:key="medico-{{ $medico['id'] }}"
                                wire:click="verSoloEste({{ $medico['id'] }})"
                                wire:keydown.enter="verSoloEste({{ $medico['id'] }})"
                                tabindex="0"
                                @class(['sh-fila', 'sh-fila-activa' => $medico['activo']])
                                @if ($medico['activo']) aria-selected="true" @endif
                                title="{{ $medico['activo'] ? 'Tocá otra vez para ver a todos' : 'Ver solo sus honorarios' }}"
                            >
                                <td>
                                    <span class="sh-medico-nombre">{{ $medico['nombre'] }}</span>
                                    <span class="sh-medico-detalle">
                                        @if ($medico['especialidad'] !== '')
                                            {{ $medico['especialidad'] }}
                                        @endif
                                        <span class="sh-solo-cel">
                                            · {{ $medico['renglones'] }} {{ \Illuminate\Support\Str::plural('honorario', $medico['renglones']) }}
                                        </span>
                                    </span>
                                </td>
                                <td class="sh-num sh-ocultable">{{ $medico['renglones'] }}</td>
                                <td class="sh-num sh-total">{{ $medico['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- 3. El detalle --}}
        <div class="sh-detalle">
            <div class="sh-detalle-cabeza">
                <h3 class="sh-detalle-titulo">
                    {{ $elegido ? $elegido['nombre'] : 'Todos los honorarios' }}
                </h3>

                @if ($elegido)
                    <button type="button" wire:click="verTodos" class="sh-todos">
                        <x-filament::icon icon="heroicon-m-x-mark" class="sh-todos-icono" />
                        Ver todos los médicos
                    </button>
                @endif
            </div>

            {{ $this->table }}
        </div>
    </div>

    <style>
        .sh {
            --sh-fondo: rgb(255 255 255);
            --sh-anillo: rgb(9 9 11 / .05);
            --sh-sombra: 0 1px 2px 0 rgb(0 0 0 / .05);
            --sh-linea: rgb(9 9 11 / .06);
            --sh-texto: rgb(9 9 11);
            --sh-tenue: rgb(113 113 122);
            --sh-muy-tenue: rgb(161 161 170);
            --sh-hover: rgb(250 250 250);
            --sh-acento: rgb(217 119 6);
            --sh-acento-fondo: rgb(255 251 235);

            display: flex; flex-direction: column; gap: 1.5rem;
        }
        .dark .sh {
            --sh-fondo: rgb(24 24 27);
            --sh-anillo: rgb(255 255 255 / .1);
            --sh-sombra: none;
            --sh-linea: rgb(255 255 255 / .08);
            --sh-texto: rgb(255 255 255);
            --sh-tenue: rgb(161 161 170);
            --sh-muy-tenue: rgb(113 113 122);
            --sh-hover: rgb(255 255 255 / .03);
            --sh-acento: rgb(251 191 36);
            --sh-acento-fondo: rgb(251 191 36 / .08);
        }

        /* 1. Barra del período */
        .sh-barra {
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: .75rem;
        }
        .sh-seg {
            display: inline-flex; flex-wrap: wrap; gap: .25rem; padding: .25rem;
            border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-seg-boton {
            padding: .45rem .8rem; border-radius: .5rem; cursor: pointer;
            font-size: .875rem; font-weight: 500; color: var(--sh-tenue);
            background: transparent; border: 0;
            transition: background-color .12s ease, color .12s ease;
        }
        .sh-seg-boton:hover { color: var(--sh-texto); background: var(--sh-hover); }
        .sh-seg-activo, .sh-seg-activo:hover { color: var(--sh-acento); background: var(--sh-acento-fondo); }

        .sh-rango {
            display: inline-flex; align-items: center; gap: .25rem; padding: .25rem .375rem;
            border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-rango input {
            height: 2rem; padding: 0 .375rem; border: 0; border-radius: .375rem;
            background: transparent; color: var(--sh-texto);
            font-size: .875rem; font-variant-numeric: tabular-nums;
            color-scheme: light;
        }
        .dark .sh-rango input { color-scheme: dark; }
        .sh-rango input:focus { outline: 2px solid var(--sh-acento); outline-offset: 0; }
        .sh-rango-flecha { font-size: .875rem; color: var(--sh-muy-tenue); }

        .sh-seg-boton:focus-visible, .sh-fila:focus-visible, .sh-todos:focus-visible {
            outline: 2px solid var(--sh-acento); outline-offset: -2px;
        }

        /* 2. Panel */
        .sh-panel {
            overflow: hidden; border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-cabeza {
            display: flex; flex-direction: column; gap: .2rem; padding: 1.25rem 1.5rem;
        }
        .sh-etiqueta { font-size: .8125rem; font-weight: 500; color: var(--sh-tenue); }
        .sh-valor {
            font-size: 1.875rem; font-weight: 600; line-height: 1.2; letter-spacing: -.01em;
            font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--sh-texto);
        }
        .sh-nota { font-size: .75rem; color: var(--sh-muy-tenue); }

        .sh-medicos { width: 100%; border-collapse: collapse; border-top: 1px solid var(--sh-linea); }
        .sh-medicos th {
            padding: .625rem 1.5rem; text-align: left;
            font-size: .75rem; font-weight: 500; color: var(--sh-tenue);
            border-bottom: 1px solid var(--sh-linea);
        }
        .sh-medicos td {
            padding: .75rem 1.5rem; vertical-align: middle;
            font-size: .875rem; color: var(--sh-texto);
            border-bottom: 1px solid var(--sh-linea);
        }
        .sh-medicos tbody tr:last-child td { border-bottom: 0; }
        .sh-num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .sh-total { font-weight: 600; }

        .sh-fila { cursor: pointer; transition: background-color .12s ease; }
        .sh-fila:hover td { background: var(--sh-hover); }
        .sh-fila-activa td, .sh-fila-activa:hover td { background: var(--sh-acento-fondo); }
        .sh-fila-activa td:first-child { box-shadow: inset 3px 0 0 var(--sh-acento); }

        .sh-medico-nombre { display: block; font-weight: 500; line-height: 1.3; }
        .sh-medico-detalle {
            display: block; margin-top: .1rem;
            font-size: .75rem; color: var(--sh-tenue);
        }
        .sh-solo-cel { display: none; }

        /* 3. Detalle */
        .sh-detalle { display: flex; flex-direction: column; gap: .75rem; }
        .sh-detalle-cabeza { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }
        .sh-detalle-titulo { font-size: 1rem; font-weight: 600; color: var(--sh-texto); }
        .sh-todos {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .375rem .75rem; border-radius: .5rem; cursor: pointer;
            font-size: .8125rem; font-weight: 500; color: var(--sh-tenue);
            background: var(--sh-fondo); border: 0;
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-todos:hover { color: var(--sh-texto); }
        .sh-todos-icono { width: 1rem; height: 1rem; }

        @media (max-width: 48rem) {
            .sh-ocultable { display: none; }
            .sh-solo-cel { display: inline; }
        }
        @media (max-width: 30rem) {
            .sh-barra { flex-direction: column; align-items: stretch; }
            .sh-seg { display: grid; grid-template-columns: 1fr 1fr; }
            .sh-rango { justify-content: space-between; }
            .sh-rango input { flex: 1; min-width: 0; }
            .sh-cabeza { padding: 1rem; }
            .sh-valor { font-size: 1.5rem; }
            .sh-medicos th, .sh-medicos td { padding-left: 1rem; padding-right: 1rem; }
        }
    </style>
</x-filament-panels::page>
