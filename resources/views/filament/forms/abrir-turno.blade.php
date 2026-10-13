{{--
    Abrir la caja: qué turno y con cuánto (rediseño del 13-oct-2026).

    Mauricio: «en automático debe saber cuál es el turno que sigue». Así
    que se ve así, de arriba abajo:

      1. Cómo quedó el último turno: quién lo cerró, cuándo y si cuadró.
         Quien abre sabe de dónde arranca la gaveta.
      2. Los turnos como tarjetas, en el orden de `sihla.caja.turnos`. El
         que sigue ya viene marcado y dice SIGUE; el de quien abre dice
         TU TURNO; cada uno dice quiénes son de él, porque son ellos los
         que lo van a poder cerrar.
      3. Si se elige otro que el que sigue, una línea lo dice: saltarse un
         turno tiene que ser a propósito.

    El fondo es un campo de Filament debajo de esta vista. El estado es el
    nombre del turno («TURNO B»); Alpine lo sigue con `$entangle`, y el
    servidor lo vuelve a mirar todo al abrir (`AbridorDeTurnoDeCaja`).
--}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="tc"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            sigue: @js($sigue),
            elegido() {
                return typeof this.estado === 'string' ? this.estado : ''
            },
            elegir(nombre) {
                this.estado = nombre
            },
            seSalta() {
                return this.sigue !== null && this.elegido() !== '' && this.elegido() !== this.sigue
            },
        }"
    >
        @if ($ultimo !== null)
            <div class="tc-franja">
                <x-filament::icon icon="heroicon-o-clock" class="tc-franja-icono" />
                <div>
                    @if ($ultimo['cerro'] !== null)
                        El último fue el <strong>{{ $ultimo['nombre'] }}</strong>: lo cerró {{ $ultimo['cerro'] }} {{ $ultimo['cuando'] }}
                        contando <strong class="tc-numero">{{ $ultimo['contado'] }}</strong>
                        <span class="tc-etiqueta tc-etiqueta-{{ $ultimo['color'] }}">{{ $ultimo['resultado'] }}</span>
                    @else
                        El último fue el <strong>{{ $ultimo['nombre'] }}</strong>, {{ $ultimo['cuando'] }}.
                    @endif
                </div>
            </div>
        @endif

        @if ($turnos === [])
            {{-- El hospital no tiene turnos configurados: se escribe el nombre. --}}
            <div class="tc-caja">
                <label class="tc-titulo" for="tc-nombre-libre">¿Cómo se llama este turno?</label>
                <div class="tc-contado">
                    <input
                        id="tc-nombre-libre"
                        type="text"
                        maxlength="40"
                        placeholder="TURNO A"
                        autocomplete="off"
                        x-model="estado"
                        style="font-size: 1rem; text-transform: uppercase;"
                    />
                </div>
                <span class="tc-chico tc-tenue">Para reconocerlo después en el reporte. Opcional.</span>
            </div>
        @else
            <div style="display: flex; flex-direction: column; gap: .5rem;">
                <span class="tc-titulo">¿Qué turno abre?</span>

                <div class="tc-turnos" role="radiogroup" aria-label="Turno que se abre">
                    @foreach ($turnos as $turno)
                        <button
                            type="button"
                            role="radio"
                            wire:key="tc-turno-{{ $loop->index }}"
                            class="tc-turno"
                            x-bind:class="{ 'tc-turno-elegido': elegido() === @js($turno['nombre']) }"
                            x-bind:aria-checked="elegido() === @js($turno['nombre']) ? 'true' : 'false'"
                            x-on:click="elegir(@js($turno['nombre']))"
                        >
                            <span class="tc-turno-cabeza">
                                <span class="tc-turno-nombre">{{ $turno['nombre'] }}</span>
                                <span class="tc-turno-marca" aria-hidden="true">
                                    <svg x-show="elegido() === @js($turno['nombre'])" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </span>

                            <span class="tc-turno-etiquetas">
                                @if ($turno['sigue'])
                                    <span class="tc-etiqueta tc-etiqueta-acento">SIGUE</span>
                                @endif
                                @if ($turno['mio'])
                                    <span class="tc-etiqueta tc-etiqueta-gris">TU TURNO</span>
                                @endif
                            </span>

                            <span class="tc-turno-gente">
                                @if ($turno['asignados'] === [])
                                    Nadie tiene este turno asignado
                                @else
                                    {{ implode(', ', $turno['asignados']) }}
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>

                <p class="tc-chico tc-tenue" x-show="! seSalta()">
                    Lo cierra quien es de ese turno, quien lo abre o la administración.
                </p>
                <p class="tc-chico" style="color: var(--tc-acento);" x-show="seSalta()" x-cloak>
                    Sigue el <strong x-text="sigue"></strong>. Vas a abrir el <strong x-text="elegido()"></strong>: hacelo solo si hoy ese turno no se trabaja.
                </p>
            </div>
        @endif
    </div>

    @include('filament.forms.partials.estilos-turno')
</x-dynamic-component>
