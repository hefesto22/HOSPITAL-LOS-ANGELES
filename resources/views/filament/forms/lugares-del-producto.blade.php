{{--
    «¿Dónde hay?» de un producto con stock infinito — el modal «Lugares»
    de Existencias (13-oct-2026).

    Cada almacén es una tarjeta que se toca para marcarla o desmarcarla.
    Marcada, muestra los envases del producto para decir QUÉ hay ahí
    (Mauricio: «puede ser que solo haya blíster, o cajas, o unidades, o de
    todo; si marca caja, por ende hay unidades y blíster»):

      · Un envase marcado trae lo que lleva adentro: con la caja, el
        blíster aparece incluido y no hace falta tocarlo.
      · Las unidades sueltas están siempre: de cualquier envase se saca
        una. Sin ningún envase marcado, ahí solo hay unidades.

    Abajo se ve qué cambia —lugares que se agregan, que se quitan y los que
    cambian lo que tienen— antes de guardar.

    El estado es una lista de {almacen, envases}; vive en Livewire y Alpine
    la sigue con `$entangle`. `antes` es cómo estaba al abrir. Los estilos
    viven en `filament.partials.estilos-de-reporte`, que la página ya
    incluye.
--}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="sh sh-compacto"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            antes: @js($antes),
            envases: @js($envases),
            unidad: @js($unidad),
            nombres: @js((object) collect($almacenes)->pluck('nombre', 'id')->all()),
            limpiar(lista) {
                return (Array.isArray(lista) ? lista : [])
                    .filter((lugar) => lugar && typeof lugar === 'object')
                    .map((lugar) => ({
                        almacen: Number(lugar.almacen),
                        envases: (Array.isArray(lugar.envases) ? lugar.envases : []).map(Number),
                    }))
            },
            lista() {
                return this.limpiar(this.estado)
            },
            lugar(id) {
                return this.lista().find((lugar) => lugar.almacen === id) ?? null
            },
            esta(id) {
                return this.lugar(id) !== null
            },
            deTodo() {
                return this.envases
                    .filter((envase) => ! this.envases.some((otro) => otro.trae.includes(envase.id)))
                    .map((envase) => envase.id)
            },
            tocar(id) {
                const ahora = this.lista()

                this.estado = this.esta(id)
                    ? ahora.filter((lugar) => lugar.almacen !== id)
                    : [...ahora, { almacen: id, envases: this.deTodo() }]
            },
            incluidoEn(marcados, envaseId) {
                return this.envases.some((otro) => marcados.includes(otro.id) && otro.trae.includes(envaseId))
            },
            marcado(id, envaseId) {
                const lugar = this.lugar(id)

                return lugar !== null && lugar.envases.includes(envaseId) && ! this.incluidoEn(lugar.envases, envaseId)
            },
            incluido(id, envaseId) {
                const lugar = this.lugar(id)

                return lugar !== null && this.incluidoEn(lugar.envases, envaseId)
            },
            tocarEnvase(id, envaseId) {
                if (this.incluido(id, envaseId)) {
                    return
                }

                const trae = (this.envases.find((envase) => envase.id === envaseId) ?? { trae: [] }).trae

                this.estado = this.lista().map((lugar) => {
                    if (lugar.almacen !== id) {
                        return lugar
                    }

                    return lugar.envases.includes(envaseId)
                        ? { almacen: id, envases: lugar.envases.filter((otro) => otro !== envaseId) }
                        : { almacen: id, envases: [...lugar.envases.filter((otro) => ! trae.includes(otro)), envaseId] }
                })
            },
            hay(marcados) {
                return this.envases
                    .filter((envase) => marcados.includes(envase.id) || this.incluidoEn(marcados, envase.id))
                    .map((envase) => envase.id)
            },
            resumen(marcados) {
                if (this.envases.length === 0) {
                    return ''
                }

                const hay = this.hay(marcados)

                if (hay.length === 0) {
                    return 'SOLO ' + this.unidad
                }

                return this.envases
                    .filter((envase) => hay.includes(envase.id))
                    .map((envase) => envase.corto)
                    .concat([this.unidad])
                    .join(', ')
            },
            resumenDe(id) {
                const lugar = this.lugar(id)

                return lugar === null ? '' : this.resumen(lugar.envases)
            },
            conQue(nombre, que) {
                return que ? nombre + ' · ' + que : nombre
            },
            cambios() {
                const antes = this.limpiar(this.antes)
                const ahora = this.lista()
                const lista = []

                for (const lugar of ahora) {
                    const previo = antes.find((otro) => otro.almacen === lugar.almacen)

                    if (! previo) {
                        lista.push({ clave: 'mas-' + lugar.almacen, tipo: 'mas', texto: '+ ' + this.conQue(this.nombres[lugar.almacen], this.resumen(lugar.envases)) })
                    } else if (this.hay(previo.envases).join() !== this.hay(lugar.envases).join()) {
                        lista.push({ clave: 'cambia-' + lugar.almacen, tipo: 'mas', texto: this.nombres[lugar.almacen] + ': ' + this.resumen(lugar.envases) })
                    }
                }

                for (const lugar of antes) {
                    if (! ahora.some((otro) => otro.almacen === lugar.almacen)) {
                        lista.push({ clave: 'menos-' + lugar.almacen, tipo: 'menos', texto: this.nombres[lugar.almacen] })
                    }
                }

                return lista
            },
        }"
    >
        <div class="sh-tiles sh-tiles-lugares" role="group" aria-label="Almacenes">
            @foreach ($almacenes as $almacen)
                <div
                    wire:key="lugar-{{ $almacen['id'] }}"
                    class="sh-lugar"
                    x-bind:class="{ 'sh-lugar-on': esta({{ $almacen['id'] }}) }"
                >
                    <button
                        type="button"
                        x-on:click="tocar({{ $almacen['id'] }})"
                        x-bind:class="{ 'sh-tile-on': esta({{ $almacen['id'] }}) }"
                        x-bind:aria-pressed="esta({{ $almacen['id'] }}) ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon :icon="$almacen['icono']" class="sh-tile-svg" />
                        </span>

                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre">{{ $almacen['nombre'] }}</span>
                            <span class="sh-tile-tipo">
                                {{ $almacen['tipo'] }}
                                <span class="sh-tile-ya" x-show="resumenDe({{ $almacen['id'] }}) !== ''" x-text="'· ' + resumenDe({{ $almacen['id'] }})"></span>
                            </span>
                        </span>

                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>

                    @if ($envases !== [])
                        <div class="sh-envases" x-show="esta({{ $almacen['id'] }})" x-cloak>
                            @foreach ($envases as $envase)
                                <button
                                    type="button"
                                    class="sh-envase"
                                    x-on:click="tocarEnvase({{ $almacen['id'] }}, {{ $envase['id'] }})"
                                    x-bind:class="{
                                        'sh-envase-on': marcado({{ $almacen['id'] }}, {{ $envase['id'] }}),
                                        'sh-envase-incluido': incluido({{ $almacen['id'] }}, {{ $envase['id'] }}),
                                    }"
                                    x-bind:aria-pressed="marcado({{ $almacen['id'] }}, {{ $envase['id'] }}) || incluido({{ $almacen['id'] }}, {{ $envase['id'] }}) ? 'true' : 'false'"
                                    x-bind:title="incluido({{ $almacen['id'] }}, {{ $envase['id'] }}) ? 'Viene adentro de lo marcado' : ''"
                                >
                                    {{ $envase['nombre'] }}
                                </button>
                            @endforeach

                            <span class="sh-envase sh-envase-fijo" title="Siempre: de cualquier envase se saca una">
                                {{ $unidad }} sueltas
                            </span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="sh-cambios" x-show="cambios().length > 0" x-cloak>
            <span class="sh-cambios-titulo">Al guardar</span>

            <template x-for="cambio in cambios()" :key="cambio.clave">
                <span
                    class="sh-cambio"
                    x-bind:class="cambio.tipo === 'mas' ? 'sh-cambio-mas' : 'sh-cambio-menos'"
                    x-text="cambio.texto"
                ></span>
            </template>
        </div>

        <p class="sh-cambios-nada" x-show="cambios().length === 0">
            Toque un almacén para agregarlo o quitarlo, y sus envases para decir qué hay ahí. Lo que ya
            se cargó a las cuentas no cambia.
        </p>
    </div>
</x-dynamic-component>
