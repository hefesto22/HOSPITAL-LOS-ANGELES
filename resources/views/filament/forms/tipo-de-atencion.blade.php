{{--
    El tipo de atención en tarjetas, en el modal «Abrir cuenta»
    (rediseño del 26-oct-2026): un clic, en vez de abrir un select y
    elegir. El estado es el valor de `TipoEncuentro`; el servidor lo
    vuelve a validar con `in` al abrir la cuenta.
--}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ac"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            elegido(valor) {
                return this.estado === valor
            },
        }"
    >
        <div class="ac-tipos" role="radiogroup" aria-label="Tipo de atención">
            @foreach ($tipos as $tipo)
                <button
                    type="button"
                    role="radio"
                    wire:key="ac-tipo-{{ $tipo['valor'] }}"
                    class="ac-tipo"
                    x-bind:class="{ 'ac-tipo-elegido': elegido(@js($tipo['valor'])) }"
                    x-bind:aria-checked="elegido(@js($tipo['valor'])) ? 'true' : 'false'"
                    x-on:click="estado = @js($tipo['valor'])"
                >
                    <x-filament::icon :icon="$tipo['icono']" class="ac-tipo-icono" />
                    <span>{{ $tipo['etiqueta'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @include('filament.forms.partials.estilos-abrir-cuenta')
</x-dynamic-component>
