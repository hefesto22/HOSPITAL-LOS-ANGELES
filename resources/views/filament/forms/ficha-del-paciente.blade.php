{{--
    La ficha del paciente elegido, con su expediente, en el modal «Abrir
    cuenta» (rediseño del 26-oct-2026).

    El estado del campo es el id del expediente. Con uno solo —casi
    siempre— ya viene marcado desde el servidor, al elegir al paciente, y
    la pastilla solo lo muestra; con varios se elige con un clic. Sin
    ninguno se dice, porque sin expediente no se abre la cuenta.

    Lo que dice la ficha lo arma `FichaDelPaciente`: nombre, fecha de
    nacimiento, edad, sexo y documento. Son los dos identificadores
    (§9.D2): antes de abrirle la cuenta, se ve a quién.
--}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($ficha !== null)
        <div
            class="ac"
            x-data="{
                estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                elegido(id) {
                    return this.estado !== null && this.estado !== undefined && String(this.estado) === String(id)
                },
            }"
        >
            <div class="ac-ficha">
                <span class="ac-avatar" aria-hidden="true">{{ $ficha['iniciales'] }}</span>

                <div class="ac-ficha-cuerpo">
                    <div class="ac-nombre">
                        <span>{{ $ficha['nombre'] }}</span>
                        @if ($ficha['nn'])
                            <span class="ac-etiqueta ac-etiqueta-ojo" title="Sin identificar todavía">NN</span>
                        @endif
                    </div>

                    <div class="ac-datos">
                        <div class="ac-datos-fila">
                            @foreach ($ficha['datos'] as $dato)
                                <span>{{ $dato }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="ac-expediente">
                    <span class="ac-rotulo">Expediente</span>

                    @if ($expedientes === [])
                        <span class="ac-sin-expediente">No tiene</span>
                    @else
                        <div class="ac-pastillas" role="radiogroup" aria-label="Expediente">
                            @foreach ($expedientes as $id => $numero)
                                <button
                                    type="button"
                                    role="radio"
                                    wire:key="ac-expediente-{{ $id }}"
                                    class="ac-pastilla"
                                    x-bind:class="{ 'ac-pastilla-elegida': elegido(@js($id)) }"
                                    x-bind:aria-checked="elegido(@js($id)) ? 'true' : 'false'"
                                    x-on:click="estado = @js($id)"
                                >
                                    <svg x-show="elegido(@js($id))" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                    </svg>
                                    {{ $numero }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if ($expedientes === [])
                <p class="ac-aviso">
                    Este paciente no tiene expediente en esta sede, y sin expediente no se le abre la cuenta.
                </p>
            @elseif (count($expedientes) > 1)
                <p class="ac-chico">Tiene más de un expediente: tocá el de esta atención.</p>
            @endif
        </div>
    @endif

    @include('filament.forms.partials.estilos-abrir-cuenta')
</x-dynamic-component>
