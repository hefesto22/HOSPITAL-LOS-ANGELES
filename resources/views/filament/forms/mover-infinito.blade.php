{{--
    El «Mover» de un producto con stock infinito, en Existencias
    (13-oct-2026).

    Tres pasos que se leen de arriba abajo, con tarjetas como en
    «Lugares» —nada escondido en un desplegable—:

      1. Sale de: los almacenes donde está y que quien mueve opera, cada
         uno con lo que hay (CAJA, BLISTER, TAB).
      2. Entra a: los demás almacenes de la misma sede a los que les falta
         algo de lo que hay en el origen. Uno que ya tiene todo NO se
         ofrece (Mauricio: «si es infinito y ya hay de eso en ese lugar,
         no hay lógica en moverlo»).
      3. ¿Qué se llevó?: solo lo que al destino le falta —un envase, con
         lo que trae adentro; unidades sueltas si ahí no había nada— o
         todo, y el origen queda vacío.

    Al pie, cómo queda: lo que cambia resaltado y el almacén que se vacía,
    tachado.

    El estado es un objeto {origen, destino, envase, todo} que vive en
    Livewire —`envase` 0 son unidades sueltas—; Alpine lo sigue con
    `$entangle`. El servidor vuelve a mirar todo
    (`Existencias::moverInfinito()`). Los estilos están en
    `filament.partials.estilos-de-reporte`.
--}}
@php
    $statePath = $getStatePath();
    $nombres = collect($destinos)->pluck('nombre', 'id')->all();
    $sedes = collect($destinos)->pluck('sede', 'id')->all();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="sh sh-compacto"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            donde: @js(array_values($donde)),
            nombres: @js((object) $nombres),
            sedes: @js((object) $sedes),
            orden: @js(array_column($destinos, 'id')),
            envases: @js($envases),
            disponibles: @js((object) $disponibles),
            unidad: @js($unidad),
            dato(clave) {
                return this.estado && typeof this.estado === 'object' ? this.estado[clave] : null
            },
            origen() {
                return Number(this.dato('origen')) || null
            },
            destino() {
                return Number(this.dato('destino')) || null
            },
            elegido() {
                const valor = this.dato('envase')

                return valor === null || valor === undefined || valor === '' ? null : Number(valor)
            },
            todo() {
                return this.dato('todo') === true
            },
            poner(cambios) {
                this.estado = {
                    origen: this.origen(),
                    destino: this.destino(),
                    envase: this.elegido(),
                    todo: this.todo(),
                    ...cambios,
                }
            },
            yaHay(id) {
                return this.donde.includes(id)
            },
            hayEn(id) {
                return this.disponibles[id] ?? []
            },
            faltanEn(id) {
                const alla = this.yaHay(id) ? this.hayEn(id) : []

                return this.hayEn(this.origen()).filter((envase) => ! alla.includes(envase))
            },
            esDestino(id) {
                return id !== this.origen()
                    && this.sedes[id] === this.sedes[this.origen()]
                    && (! this.yaHay(id) || this.faltanEn(id).length > 0)
            },
            hayDestinos() {
                return this.orden.some((id) => this.esDestino(id))
            },
            opciones() {
                const destino = this.destino()

                return destino === null ? [] : this.envases.filter((envase) => this.faltanEn(destino).includes(envase.id))
            },
            sueltasEsOpcion() {
                return this.destino() !== null && ! this.yaHay(this.destino())
            },
            sigueValiendo(envase, destino) {
                if (envase === null || destino === null) {
                    return false
                }

                return envase === 0 ? ! this.yaHay(destino) : this.faltanEn(destino).includes(envase)
            },
            elegirOrigen(id) {
                this.poner({ origen: id })

                const destino = this.esDestino(this.destino()) ? this.destino() : null

                this.poner({
                    destino,
                    envase: this.sigueValiendo(this.elegido(), destino) ? this.elegido() : null,
                    todo: destino !== null && this.todo(),
                })
            },
            elegirDestino(id) {
                this.poner({ destino: id })
                this.poner({
                    envase: this.sigueValiendo(this.elegido(), id) ? this.elegido() : null,
                })
            },
            nombre(id) {
                return id ? this.nombres[id] : 'el almacén de donde sale'
            },
            cortos(ids) {
                return this.envases.filter((envase) => ids.includes(envase.id)).map((envase) => envase.corto)
            },
            etiquetaDe(ids) {
                if (this.envases.length === 0) {
                    return ''
                }

                return ids.length === 0
                    ? 'SOLO ' + this.unidad
                    : this.cortos(ids).concat([this.unidad]).join(', ')
            },
            conQue(nombre, que) {
                return que ? nombre + ' · ' + que : nombre
            },
            loQueLlega() {
                if (this.todo()) {
                    return this.hayEn(this.origen())
                }

                const envase = this.envases.find((otro) => otro.id === this.elegido())

                return envase ? [envase.id, ...envase.trae] : []
            },
            queda() {
                return this.orden
                    .filter((id) => this.yaHay(id) || id === this.destino())
                    .map((id) => {
                        const sale = this.todo() && id === this.origen() && this.destino() !== null
                        const llega = id === this.destino() && (this.todo() || this.elegido() !== null)

                        if (! llega) {
                            return { id, texto: this.conQue(this.nombres[id], this.etiquetaDe(this.hayEn(id))), nuevo: false, sale }
                        }

                        const antes = this.yaHay(id) ? this.hayEn(id) : []
                        const despues = [...new Set([...antes, ...this.loQueLlega()])]

                        return {
                            id,
                            texto: (this.yaHay(id) ? '' : '+ ') + this.conQue(this.nombres[id], this.etiquetaDe(despues)),
                            nuevo: true,
                            sale,
                        }
                    })
            },
        }"
    >
        {{-- 1. Sale de --}}
        <div class="sh-paso">
            <span class="sh-paso-titulo"><span class="sh-paso-numero">1</span> Sale de</span>

            <div class="sh-tiles sh-tiles-chicas">
                @foreach ($origenes as $almacen)
                    <button
                        type="button"
                        wire:key="origen-{{ $almacen['id'] }}"
                        x-on:click="elegirOrigen({{ $almacen['id'] }})"
                        x-bind:class="{ 'sh-tile-on': origen() === {{ $almacen['id'] }} }"
                        x-bind:aria-pressed="origen() === {{ $almacen['id'] }} ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon :icon="$almacen['icono']" class="sh-tile-svg" />
                        </span>
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre">{{ $almacen['nombre'] }}</span>
                            <span class="sh-tile-tipo">{{ $almacen['tipo'] }}</span>
                            <span class="sh-tile-hay" x-show="envases.length > 0">
                                <template x-for="corto in cortos(hayEn({{ $almacen['id'] }}))" :key="corto">
                                    <span class="sh-donde-tag" x-text="corto"></span>
                                </template>
                                <span class="sh-donde-tag sh-donde-tag-suelta" x-text="unidad"></span>
                            </span>
                        </span>
                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- 2. Entra a --}}
        <div class="sh-paso">
            <span class="sh-paso-titulo"><span class="sh-paso-numero">2</span> Entra a</span>

            <div class="sh-tiles sh-tiles-chicas" x-show="hayDestinos()">
                @foreach ($destinos as $almacen)
                    <button
                        type="button"
                        wire:key="destino-{{ $almacen['id'] }}"
                        x-show="esDestino({{ $almacen['id'] }})"
                        x-on:click="elegirDestino({{ $almacen['id'] }})"
                        x-bind:class="{ 'sh-tile-on': destino() === {{ $almacen['id'] }} }"
                        x-bind:aria-pressed="destino() === {{ $almacen['id'] }} ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon :icon="$almacen['icono']" class="sh-tile-svg" />
                        </span>
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre">{{ $almacen['nombre'] }}</span>
                            <span class="sh-tile-tipo">
                                {{ $almacen['tipo'] }}
                                <span x-show="! yaHay({{ $almacen['id'] }})">· todavía no hay</span>
                            </span>
                            <span class="sh-tile-hay" x-show="yaHay({{ $almacen['id'] }}) && envases.length > 0">
                                <template x-for="corto in cortos(hayEn({{ $almacen['id'] }}))" :key="corto">
                                    <span class="sh-donde-tag" x-text="corto"></span>
                                </template>
                                <span class="sh-donde-tag sh-donde-tag-suelta" x-text="unidad"></span>
                            </span>
                        </span>
                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>
                @endforeach
            </div>

            <p class="sh-aviso-chico" x-show="! hayDestinos()" x-cloak>
                En los demás almacenes ya hay todo lo que hay en <span x-text="nombre(origen())"></span>:
                no hay nada que mover. Para quitarlo de un almacén, use «Lugares».
            </p>
        </div>

        {{-- 3. ¿Qué se llevó? --}}
        <div class="sh-paso" x-show="hayDestinos()">
            <span class="sh-paso-titulo"><span class="sh-paso-numero">3</span> ¿Qué se llevó?</span>

            <p class="sh-cambios-nada" x-show="destino() === null">Elija primero a qué almacén entra.</p>

            <div class="sh-tiles sh-tiles-chicas" x-show="destino() !== null" x-cloak>
                <template x-for="envase in opciones()" :key="'envase-' + envase.id">
                    <button
                        type="button"
                        x-on:click="poner({ envase: envase.id, todo: false })"
                        x-bind:class="{ 'sh-tile-on': ! todo() && elegido() === envase.id }"
                        x-bind:aria-pressed="! todo() && elegido() === envase.id ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon icon="heroicon-o-cube" class="sh-tile-svg" />
                        </span>
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre" x-text="envase.nombre"></span>
                            <span class="sh-tile-tipo" x-text="'Sigue habiendo en ' + nombre(origen())"></span>
                        </span>
                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>
                </template>

                <button
                    type="button"
                    x-show="sueltasEsOpcion()"
                    x-on:click="poner({ envase: 0, todo: false })"
                    x-bind:class="{ 'sh-tile-on': ! todo() && elegido() === 0 }"
                    x-bind:aria-pressed="! todo() && elegido() === 0 ? 'true' : 'false'"
                    class="sh-tile"
                >
                    <span class="sh-tile-icono">
                        <x-filament::icon icon="heroicon-o-square-2-stack" class="sh-tile-svg" />
                    </span>
                    <span class="sh-tile-texto">
                        <span class="sh-tile-nombre" x-text="unidad + ' sueltas'"></span>
                        <span class="sh-tile-tipo" x-text="'Sigue habiendo en ' + nombre(origen())"></span>
                    </span>
                    <span class="sh-tile-marca" aria-hidden="true">
                        <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                    </span>
                </button>

                <button
                    type="button"
                    x-on:click="poner({ todo: true, envase: null })"
                    x-bind:class="{ 'sh-tile-on': todo() }"
                    x-bind:aria-pressed="todo() ? 'true' : 'false'"
                    class="sh-tile"
                >
                    <span class="sh-tile-icono">
                        <x-filament::icon icon="heroicon-o-archive-box-arrow-down" class="sh-tile-svg" />
                    </span>
                    <span class="sh-tile-texto">
                        <span class="sh-tile-nombre">Todo</span>
                        <span class="sh-tile-tipo" x-text="nombre(origen()) + ' queda sin nada'"></span>
                    </span>
                    <span class="sh-tile-marca" aria-hidden="true">
                        <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                    </span>
                </button>
            </div>
        </div>

        {{-- Cómo queda --}}
        <div class="sh-cambios" x-show="hayDestinos()">
            <span class="sh-cambios-titulo">Queda en</span>

            <template x-for="lugar in queda()" :key="'queda-' + lugar.id">
                <span
                    class="sh-cambio"
                    x-bind:class="{
                        'sh-cambio-mas': lugar.nuevo,
                        'sh-cambio-menos': lugar.sale,
                        'sh-cambio-queda': ! lugar.nuevo && ! lugar.sale,
                    }"
                    x-text="lugar.texto"
                ></span>
            </template>
        </div>
    </div>
</x-dynamic-component>
