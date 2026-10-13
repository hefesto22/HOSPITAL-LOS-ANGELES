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

    Los estilos viven en `filament.partials.estilos-de-reporte`, compartidos
    con Consumo de productos y Existencias.
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
                <table class="sh-tabla">
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
                                    <span class="sh-tabla-nombre">{{ $medico['nombre'] }}</span>
                                    <span class="sh-tabla-detalle">
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

    @include('filament.partials.estilos-de-reporte')
</x-filament-panels::page>
