{{--
    Cerrar el turno: contar la gaveta (rediseño del 13-oct-2026).

    De arriba abajo:

      1. Qué turno es, quién lo abrió y desde cuándo. Si lo abrió otra
         persona —la administración abre, la cajera del turno cierra—, se
         dice que el arqueo queda a nombre de quien cierra: es quien firma
         lo que cuenta.
      2. A la izquierda, el total contado, grande, con el foco puesto. A
         la derecha, el cuadre: aparece RECIÉN cuando se escribe el
         número —contar primero, comparar después; si el esperado se ve
         antes, se teclea el esperado—. Dice «Cuadra», «Faltan L 10.00»
         o «Sobran L 5.00», y cuando no cuadra pide la explicación.
      3. Lo cobrado con tarjeta y transferencia, aparte: no está en la
         gaveta y no se cuenta.

    La cuenta de acá es solo para mostrar. El arqueo de verdad lo hace el
    servidor con bcmath al cerrar (`AbridorDeTurnoDeCaja::cerrar`); por
    eso los centavos se sacan del texto y no de un `parseFloat`, para que
    lo que se ve sea lo mismo que se guarda.
--}}
@php
    $statePath = $getStatePath();
    $hayTurno = isset($esperado);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($hayTurno)
        <div
            class="tc"
            x-data="{
                estado: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                fondo: @js($fondo),
                efectivo: @js($efectivo),
                esperado: @js($esperado),
                texto() {
                    return this.estado === null || this.estado === undefined ? '' : String(this.estado).trim()
                },
                centavos(texto) {
                    const limpio = String(texto ?? '').trim().replace(',', '.')

                    if (! /^(?:\d+\.?\d*|\.\d+)$/.test(limpio)) {
                        return null
                    }

                    const [entero, fraccion = ''] = limpio.split('.')
                    const tres = (fraccion + '000').slice(0, 3)
                    let total = Number(entero || '0') * 100 + Number(tres.slice(0, 2))

                    if (Number(tres[2]) >= 5) {
                        total += 1
                    }

                    return total
                },
                contado() {
                    return this.centavos(this.texto())
                },
                escrito() {
                    return this.texto() !== ''
                },
                malo() {
                    return this.escrito() && this.contado() === null
                },
                diferencia() {
                    const contado = this.contado()

                    return contado === null ? null : contado - this.centavos(this.esperado)
                },
                lempiras(centavos) {
                    const valor = Math.abs(centavos) / 100

                    return 'L ' + valor.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            }"
        >
            <div @class(['tc-franja', 'tc-franja-aviso' => $loAbrioOtro])>
                <x-filament::icon icon="heroicon-o-lock-open" class="tc-franja-icono" />
                <div>
                    <strong>{{ $nombre }}</strong> <span class="tc-tenue">· {{ $numero }}</span><br>
                    Lo abrió {{ $abrio }} {{ $desde }} <span class="tc-tenue">({{ $hace }})</span>
                    · {{ $recibos }} {{ $recibos === 1 ? 'recibo' : 'recibos' }}.
                    @if ($loAbrioOtro)
                        <br>El arqueo queda a nombre de quien lo cierra: <strong>{{ $quienCierra }}</strong>.
                    @endif
                </div>
            </div>

            <div class="tc-cuadre">
                <div class="tc-caja">
                    <label class="tc-titulo" for="tc-contado">Efectivo contado en la gaveta</label>

                    <div class="tc-contado" x-bind:class="{ 'tc-contado-malo': malo() }">
                        <span class="tc-contado-prefijo">L</span>
                        <input
                            id="tc-contado"
                            x-ref="contado"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="0.00"
                            x-model="estado"
                            x-init="$nextTick(() => $refs.contado.focus())"
                        />
                    </div>

                    <span class="tc-chico tc-tenue" x-show="! malo()">
                        Billetes y monedas, todo junto. Con punto o coma para los centavos.
                    </span>
                    <span class="tc-chico" style="color: var(--tc-mal);" x-show="malo()" x-cloak>
                        Solo números: 1250.50 o 1250,50.
                    </span>
                </div>

                <div class="tc-caja">
                    <div class="tc-espera" x-show="contado() === null">
                        <x-filament::icon icon="heroicon-o-calculator" />
                        <span>Contá primero.<br>Cuando escribas el total, acá se ve si cuadra.</span>
                    </div>

                    <template x-if="contado() !== null">
                        <div style="display: flex; flex-direction: column; gap: .625rem;">
                            <dl class="tc-cinta">
                                <div class="tc-cinta-linea">
                                    <dt><span class="tc-cinta-signo"></span>Fondo con que se abrió</dt>
                                    <dd x-text="lempiras(centavos(fondo))"></dd>
                                </div>
                                <div class="tc-cinta-linea">
                                    <dt><span class="tc-cinta-signo">+</span>Efectivo recibido</dt>
                                    <dd x-text="lempiras(centavos(efectivo))"></dd>
                                </div>
                                <div class="tc-cinta-linea tc-cinta-total">
                                    <dt><span class="tc-cinta-signo">=</span>Tenía que haber</dt>
                                    <dd x-text="lempiras(centavos(esperado))"></dd>
                                </div>
                                <div class="tc-cinta-linea">
                                    <dt><span class="tc-cinta-signo"></span>Contaste</dt>
                                    <dd x-text="lempiras(contado())"></dd>
                                </div>
                            </dl>

                            <div
                                class="tc-veredicto"
                                x-bind:class="{
                                    'tc-veredicto-bien': diferencia() === 0,
                                    'tc-veredicto-mal': diferencia() < 0,
                                    'tc-veredicto-ojo': diferencia() > 0,
                                }"
                            >
                                <span x-text="diferencia() === 0 ? 'Cuadra exacto' : (diferencia() < 0 ? 'Faltan' : 'Sobran')"></span>
                                <span class="tc-numero" x-show="diferencia() !== 0" x-text="lempiras(diferencia())"></span>
                            </div>

                            <span class="tc-chico tc-tenue" x-show="diferencia() !== 0">
                                Escribí abajo qué pasó con el efectivo: sin eso no se cierra.
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            @if ($fuera !== [])
                <div class="tc-fuera">
                    <span>Fuera de la gaveta, no se cuenta:</span>
                    @foreach ($fuera as $linea)
                        <span title="{{ $linea['nota'] }}">
                            {{ $linea['etiqueta'] }} <strong>L {{ number_format((float) $linea['monto'], 2) }}</strong>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @include('filament.forms.partials.estilos-turno')
</x-dynamic-component>
