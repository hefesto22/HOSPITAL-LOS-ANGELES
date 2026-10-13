{{--
    Los precios de un servicio, en su misma ficha (13-oct-2026).

    Mauricio: «debe ser rápido de registrar y se deben poder editar los
    precios desde acá». Una fila por pagador —el precio de lista y cada
    convenio vigente— con el precio de hoy escrito en su campo. Se cambia
    el número y se guarda la ficha; lo que no se tocó no se toca.

      · Lo que cambia se marca: «antes L 300.00», o «nuevo» si ese seguro
        no tenía precio propio.
      · Un seguro sin precio propio dice cuánto paga: el de lista.
      · El L 10 que nadie fijó no se muestra como precio: el campo queda
        vacío y dice «pendiente».

    El estado es «pagador => lo escrito» y vive en Livewire; Alpine lo
    sigue con `$entangle`. Lo guarda `PreciosDeLaFicha`, que vuelve a
    leer todo: lo de acá es para ver antes de guardar.

    ⚠️ Estilos propios con `<style>` y variables con `.dark`: el CSS del
    panel está precompilado y las clases nuevas de Tailwind no existen ahí
    (§9.A7).
--}}
@php
    $statePath = $getStatePath();
    $actuales = collect($filas)->pluck('actual', 'clave')->all();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="pf"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            actuales: @js((object) $actuales),
            todo() {
                return this.estado && typeof this.estado === 'object' && ! Array.isArray(this.estado) ? this.estado : {}
            },
            valor(clave) {
                const valor = this.todo()[clave]

                return valor === null || valor === undefined ? '' : String(valor)
            },
            poner(clave, valor) {
                this.estado = { ...this.todo(), [clave]: valor }
            },
            numero(texto) {
                const limpio = String(texto ?? '').trim().replace(',', '.')

                return /^(?:\d+\.?\d*|\.\d+)$/.test(limpio) ? parseFloat(limpio) : null
            },
            malo(clave) {
                const valor = this.valor(clave).trim()

                return valor !== '' && this.numero(valor) === null
            },
            cambia(clave) {
                const valor = this.valor(clave).trim()

                if (valor === '' || this.malo(clave)) {
                    return false
                }

                const actual = this.actuales[clave]

                return actual === null || actual === undefined || this.numero(valor) !== this.numero(actual)
            },
            esNuevo(clave) {
                const actual = this.actuales[clave]

                return this.cambia(clave) && (actual === null || actual === undefined)
            },
            cuantosCambian() {
                return Object.keys(this.todo()).filter((clave) => this.cambia(clave)).length
            },
        }"
    >
        <div class="pf-cabeza">
            <span class="pf-titulo">Precios</span>
            <span class="pf-porque">{{ $porQue }}</span>
        </div>

        <div class="pf-lista">
            @foreach ($filas as $fila)
                <div
                    wire:key="precio-{{ $fila['clave'] }}"
                    @class(['pf-fila', 'pf-fila-lista' => $fila['lista']])
                    x-bind:class="{ 'pf-fila-cambia': cambia('{{ $fila['clave'] }}') }"
                >
                    <div class="pf-quien">
                        <span class="pf-nombre">
                            {{ $fila['nombre'] }}
                            @if ($fila['lista'])
                                <span class="pf-obligatorio" title="Obligatorio">*</span>
                            @endif
                            @if ($fila['pendiente'])
                                <span class="pf-pendiente">Pendiente</span>
                            @endif
                        </span>
                        <span class="pf-detalle">{{ $fila['detalle'] }}</span>
                    </div>

                    <div class="pf-cuanto">
                        <span class="pf-antes" x-show="cambia('{{ $fila['clave'] }}')" x-cloak>
                            <span x-show="! esNuevo('{{ $fila['clave'] }}')">antes L {{ $fila['actual'] }}</span>
                            <span x-show="esNuevo('{{ $fila['clave'] }}')">nuevo</span>
                        </span>

                        <label class="pf-campo" x-bind:class="{ 'pf-campo-malo': malo('{{ $fila['clave'] }}') }">
                            <span class="pf-moneda">L</span>
                            <input
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                class="pf-input"
                                aria-label="Precio de {{ $fila['nombre'] }}"
                                placeholder="{{ $fila['lista'] ? ($fila['pendiente'] ? 'Escriba el precio' : '0.00') : 'de lista' }}"
                                x-bind:value="valor('{{ $fila['clave'] }}')"
                                x-on:input="poner('{{ $fila['clave'] }}', $event.target.value)"
                            />
                        </label>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="pf-pie">
            <span x-show="cuantosCambian() === 0">
                Vacío en un seguro = paga el precio de lista.
                @if ($variantes > 0)
                    Las variantes ({{ $variantes }}) tienen su propio precio: se fijan en la lista de precios de abajo.
                @endif
            </span>
            <span class="pf-pie-cambia" x-show="cuantosCambian() > 0" x-cloak>
                <span x-text="cuantosCambian() === 1 ? 'Cambia 1 precio' : 'Cambian ' + cuantosCambian() + ' precios'"></span>
                al guardar. El de antes queda en el historial; las facturas ya emitidas no cambian.
            </span>
        </p>
    </div>

    <style>
        .pf {
            --pf-fondo: rgb(255 255 255);
            --pf-anillo: rgb(9 9 11 / .08);
            --pf-linea: rgb(9 9 11 / .06);
            --pf-texto: rgb(9 9 11);
            --pf-tenue: rgb(113 113 122);
            --pf-hover: rgb(250 250 250);
            --pf-acento: rgb(217 119 6);
            --pf-acento-fondo: rgb(255 251 235);
            --pf-malo: rgb(220 38 38);

            display: flex; flex-direction: column; gap: .625rem;
        }
        .dark .pf {
            --pf-fondo: rgb(24 24 27);
            --pf-anillo: rgb(255 255 255 / .1);
            --pf-linea: rgb(255 255 255 / .07);
            --pf-texto: rgb(255 255 255);
            --pf-tenue: rgb(161 161 170);
            --pf-hover: rgb(255 255 255 / .03);
            --pf-acento: rgb(251 191 36);
            --pf-acento-fondo: rgb(251 191 36 / .08);
            --pf-malo: rgb(248 113 113);
        }
        .pf-cabeza { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; }
        .pf-titulo { font-size: .875rem; font-weight: 600; color: var(--pf-texto); }
        .pf-porque { font-size: .75rem; font-weight: 500; color: var(--pf-tenue); }
        .pf-lista {
            overflow: hidden; border-radius: .75rem;
            background: var(--pf-fondo); box-shadow: 0 0 0 1px var(--pf-anillo);
        }
        .pf-fila {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            padding: .625rem .875rem; border-top: 1px solid var(--pf-linea);
            transition: background-color .12s ease;
        }
        .pf-fila:first-child { border-top: 0; }
        .pf-fila-lista { background: var(--pf-hover); }
        .pf-fila-cambia, .pf-fila-cambia.pf-fila-lista { background: var(--pf-acento-fondo); box-shadow: inset 3px 0 0 var(--pf-acento); }
        .pf-quien { display: flex; flex-direction: column; min-width: 0; }
        .pf-nombre {
            display: inline-flex; flex-wrap: wrap; align-items: center; gap: .4rem;
            font-size: .8125rem; font-weight: 600; color: var(--pf-texto);
        }
        .pf-obligatorio { color: var(--pf-malo); }
        .pf-pendiente {
            padding: 0 .4rem; border-radius: .3rem; font-size: .6875rem; font-weight: 600; line-height: 1.25rem;
            color: var(--pf-acento); background: var(--pf-acento-fondo); box-shadow: inset 0 0 0 1px var(--pf-acento);
        }
        .pf-detalle { margin-top: .1rem; font-size: .75rem; color: var(--pf-tenue); }
        .pf-cuanto { display: flex; align-items: center; gap: .625rem; flex-shrink: 0; }
        .pf-antes { font-size: .75rem; font-weight: 600; color: var(--pf-acento); white-space: nowrap; }
        .pf-campo {
            display: inline-flex; align-items: center; gap: .35rem; width: 9.5rem;
            padding: .4rem .65rem; border-radius: .5rem; cursor: text;
            background: var(--pf-fondo); box-shadow: 0 0 0 1px var(--pf-anillo);
        }
        .pf-campo:focus-within { box-shadow: 0 0 0 2px var(--pf-acento); }
        .pf-campo-malo, .pf-campo-malo:focus-within { box-shadow: 0 0 0 2px var(--pf-malo); }
        .pf-moneda { font-size: .8125rem; font-weight: 600; color: var(--pf-tenue); }
        .pf-input {
            width: 100%; min-width: 0; padding: 0; border: 0; outline: 0; background: transparent;
            text-align: right; font-size: .9375rem; font-weight: 600; font-variant-numeric: tabular-nums;
            color: var(--pf-texto);
        }
        .pf-input:focus { box-shadow: none; }
        .pf-input::placeholder { font-weight: 400; color: var(--pf-tenue); }
        .pf-pie { font-size: .75rem; color: var(--pf-tenue); }
        .pf-pie-cambia { font-weight: 500; color: var(--pf-acento); }
        [x-cloak] { display: none !important; }

        @media (max-width: 30rem) {
            .pf-fila { flex-direction: column; align-items: stretch; gap: .5rem; }
            .pf-cuanto { justify-content: space-between; }
            .pf-campo { width: auto; flex: 1; }
        }
    </style>
</x-dynamic-component>
