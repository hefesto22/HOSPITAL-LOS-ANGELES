{{--
    El «Mover» de lo que tiene existencia, en Existencias (13-oct-2026).

    Cuatro pasos que se leen de arriba abajo, con tarjetas como el de
    stock infinito:

      1. Qué lote, y de dónde sale: los de los estantes que quien mueve
         opera, lo que vence primero arriba.
      2. Entra a: los demás almacenes de la misma sede.
      3. ¿En qué se lleva?: los envases que hay ENTEROS en ese lote —de una
         caja abierta salen blísteres, no cajas— o unidades sueltas.
      4. ¿Cuántos?: con «Todo» y la cuenta en la unidad del kardex a la
         vista antes de apretar.

    El estado es un objeto {existencia, destino, forma, cantidad} que vive
    en Livewire; Alpine lo sigue con `$entangle`. Lo que se ve acá es para
    leer: el servidor vuelve a calcular las formas, el tope y la cuenta
    (`Existencias::trasladar()`). Los estilos están en
    `filament.partials.estilos-de-reporte`.
--}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="sh sh-compacto"
        x-data="{
            estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            lotes: @js($lotes),
            destinos: @js($destinos),
            yaHay: @js((object) $yaHay),
            tecleado: '',
            init() {
                this.tecleado = this.dato('cantidad') ?? ''
                this.$watch('tecleado', (valor) => this.poner({ cantidad: valor }))
            },
            dato(clave) {
                return this.estado && typeof this.estado === 'object' ? this.estado[clave] : null
            },
            poner(cambios) {
                this.estado = {
                    existencia: this.dato('existencia'),
                    destino: this.dato('destino'),
                    forma: this.dato('forma'),
                    cantidad: this.dato('cantidad'),
                    ...cambios,
                }
            },
            lote() {
                return this.lotes.find((lote) => lote.id === Number(this.dato('existencia'))) ?? null
            },
            forma() {
                const lote = this.lote()

                return lote ? (lote.formas.find((forma) => forma.clave === this.dato('forma')) ?? null) : null
            },
            elegirLote(id) {
                const lote = this.lotes.find((otro) => otro.id === id)
                const destino = this.destinos.find((otro) => otro.id === Number(this.dato('destino')))
                const sigue = destino && lote && destino.id !== lote.almacen && destino.sede === lote.sede

                this.tecleado = ''
                this.poner({
                    existencia: id,
                    destino: sigue ? destino.id : null,
                    forma: lote && lote.formas.length > 0 ? lote.formas[0].clave : null,
                    cantidad: null,
                })
            },
            esDestino(destino) {
                const lote = this.lote()

                return lote !== null && destino.id !== lote.almacen && destino.sede === lote.sede
            },
            esDestinoId(id) {
                const destino = this.destinos.find((otro) => otro.id === id)

                return destino ? this.esDestino(destino) : false
            },
            elegirForma(clave) {
                this.tecleado = ''
                this.poner({ forma: clave, cantidad: null })
            },
            numero() {
                const texto = String(this.tecleado ?? '').trim().replace(',', '.')

                if (texto === '' || ! /^\d*\.?\d+$/.test(texto)) {
                    return null
                }

                return parseFloat(texto)
            },
            todo() {
                const forma = this.forma()

                if (forma) {
                    this.tecleado = forma.max
                }
            },
            problema() {
                const forma = this.forma()
                const texto = String(this.tecleado ?? '').trim()
                const numero = this.numero()

                if (! forma || texto === '') {
                    return null
                }

                if (numero === null || numero <= 0) {
                    return 'Escriba solo números, mayores que cero. Ejemplo: 2'
                }

                if (numero > parseFloat(forma.max)) {
                    return 'En este lote hay ' + forma.max + ' ' + forma.nombre + (forma.entero ? ' sin abrir.' : '.')
                }

                if (forma.entero && ! Number.isInteger(numero)) {
                    return 'Un ' + forma.nombre + ' sin abrir se mueve entero. Para mover una parte, elija ' + this.suelta().nombre + ' sueltas.'
                }

                return null
            },
            suelta() {
                const lote = this.lote()

                return lote ? lote.formas[lote.formas.length - 1] : { nombre: 'unidades', max: '0' }
            },
            corto(valor) {
                return String(Number(valor.toFixed(4)))
            },
            cuenta() {
                const forma = this.forma()
                const numero = this.numero()

                if (! forma || numero === null || numero <= 0 || this.problema() !== null) {
                    return null
                }

                const suelta = this.suelta()
                const unidades = numero * parseFloat(forma.contenido)
                const quedan = Math.max(parseFloat(suelta.max) - unidades, 0)
                const enKardex = forma.entero ? ' = ' + this.corto(unidades) + ' ' + suelta.nombre : ''

                return 'Se mueven ' + this.corto(numero) + ' ' + forma.nombre + enKardex
                    + ' · en el lote quedan ' + this.corto(quedan) + ' ' + suelta.nombre
            },
        }"
    >
        {{-- 1. Qué lote --}}
        <div class="sh-paso">
            <span class="sh-paso-titulo"><span class="sh-paso-numero">1</span> Qué lote, y de dónde sale</span>

            <div class="sh-tiles sh-tiles-lotes">
                @foreach ($lotes as $lote)
                    <button
                        type="button"
                        wire:key="lote-{{ $lote['id'] }}"
                        x-on:click="elegirLote({{ $lote['id'] }})"
                        x-bind:class="{ 'sh-tile-on': Number(dato('existencia')) === {{ $lote['id'] }} }"
                        x-bind:aria-pressed="Number(dato('existencia')) === {{ $lote['id'] }} ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre">{{ $lote['titulo'] }}</span>
                            <span class="sh-tile-tipo">{{ $lote['detalle'] }}</span>
                            @if ($lote['vence'] !== null)
                                <span class="sh-tile-vence">
                                    <span class="sh-vence sh-vence-{{ $lote['color'] }}">{{ $lote['vence'] }}</span>
                                </span>
                            @endif
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

            <div class="sh-tiles sh-tiles-chicas">
                @foreach ($destinos as $almacen)
                    <button
                        type="button"
                        wire:key="destino-{{ $almacen['id'] }}"
                        x-show="esDestinoId({{ $almacen['id'] }})"
                        x-on:click="poner({ destino: {{ $almacen['id'] }} })"
                        x-bind:class="{ 'sh-tile-on': Number(dato('destino')) === {{ $almacen['id'] }} }"
                        x-bind:aria-pressed="Number(dato('destino')) === {{ $almacen['id'] }} ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon :icon="$almacen['icono']" class="sh-tile-svg" />
                        </span>
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre">{{ $almacen['nombre'] }}</span>
                            <span class="sh-tile-tipo">
                                {{ $almacen['tipo'] }}
                                @isset($yaHay[$almacen['id']])
                                    <span class="sh-tile-ya">· {{ $yaHay[$almacen['id']] }}</span>
                                @endisset
                            </span>
                        </span>
                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>
                @endforeach
            </div>

            <p class="sh-cambios-nada" x-show="lote() === null">Elija primero el lote.</p>
            <p class="sh-cambios-nada" x-show="lote() !== null && ! destinos.some((destino) => esDestino(destino))" x-cloak>
                No hay otro almacén en esta sede. Se crea en Inventario → Almacenes.
            </p>
        </div>

        {{-- 3. ¿En qué se lleva? --}}
        <div class="sh-paso" x-show="lote() !== null">
            <span class="sh-paso-titulo"><span class="sh-paso-numero">3</span> ¿En qué se lleva?</span>

            <div class="sh-tiles sh-tiles-chicas">
                <template x-for="forma in (lote() ? lote().formas : [])" :key="'forma-' + forma.clave">
                    <button
                        type="button"
                        x-on:click="elegirForma(forma.clave)"
                        x-bind:class="{ 'sh-tile-on': dato('forma') === forma.clave }"
                        x-bind:aria-pressed="dato('forma') === forma.clave ? 'true' : 'false'"
                        class="sh-tile"
                    >
                        <span class="sh-tile-icono">
                            <x-filament::icon icon="heroicon-o-cube" class="sh-tile-svg" />
                        </span>
                        <span class="sh-tile-texto">
                            <span class="sh-tile-nombre" x-text="forma.nombre"></span>
                            <span class="sh-tile-tipo" x-text="forma.detalle"></span>
                        </span>
                        <span class="sh-tile-marca" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-check" class="sh-tile-check" />
                        </span>
                    </button>
                </template>
            </div>
        </div>

        {{-- 4. ¿Cuántos? --}}
        <div class="sh-paso" x-show="forma() !== null">
            <span class="sh-paso-titulo">
                <span class="sh-paso-numero">4</span>
                <span x-text="'¿Cuántos ' + (forma() ? forma().nombre : '') + '?'"></span>
            </span>

            <div class="sh-cantidad">
                <input
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    class="sh-numero"
                    x-model="tecleado"
                    x-bind:aria-invalid="problema() !== null ? 'true' : 'false'"
                    aria-label="Cantidad que se mueve"
                />

                <button type="button" class="sh-todos" x-on:click="todo()">
                    <span x-text="'Todo (' + (forma() ? forma().max : '') + ')'"></span>
                </button>
            </div>

            <p class="sh-cuenta" x-show="cuenta() !== null" x-text="cuenta()"></p>
            <p class="sh-aviso-chico" x-show="problema() !== null" x-text="problema()" x-cloak></p>
        </div>
    </div>
</x-dynamic-component>
