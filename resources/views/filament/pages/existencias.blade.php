{{--
    Existencias — rediseño del 13-oct-2026.

    Antes: tarjetas por almacén, un panel de cinco filtros con su botón
    «Aplicar» y la tabla agrupada con encabezados plegables. Ahora, con
    el mismo lenguaje que Honorarios y Consumo de productos:

    1. DÓNDE: cada almacén con cuántos productos tiene, farmacia primero
       y «Todos» al final. Un toque deja la tabla en ese almacén.
    2. QUÉ: todo, por vencer, vencido, controlados o stock infinito, con
       su cuenta en el estante elegido. Por vencer y vencido se pintan en
       ámbar cuando hay algo.
    3. La tabla: UN renglón por producto, con un chip por almacén. «Lotes»
       abre el detalle; «Mover» pide el lote adentro. Lo que tiene stock
       infinito dice ∞ y, en vez de «Lotes», tiene «Lugares».

    Los estilos viven en `filament.partials.estilos-de-reporte`.
--}}
<x-filament-panels::page>
    @php
        $almacenes = $this->almacenes();
        $ningunAlmacen = collect($almacenes)->doesntContain('activo', true);
    @endphp

    <div class="sh">
        <div class="sh-filtros">
            {{--
                1. Dónde. Farmacia primero y «Todos» al final (Mauricio,
                13-oct-2026): la pantalla abre en el primero, que es de
                donde se despacha.
            --}}
            @if (count($almacenes) > 0)
                <div class="sh-seg" role="group" aria-label="Dónde">
                    @foreach ($almacenes as $almacen)
                        <button
                            type="button"
                            wire:key="almacen-{{ $almacen['id'] }}"
                            wire:click="verAlmacen({{ $almacen['id'] }})"
                            @class(['sh-seg-boton', 'sh-seg-activo' => $almacen['activo']])
                            @if ($almacen['activo']) aria-pressed="true" @endif
                        >
                            {{ $almacen['nombre'] }}
                            <span class="sh-seg-cuenta">{{ number_format($almacen['productos']) }}</span>
                        </button>
                    @endforeach

                    @if (count($almacenes) > 1)
                        <button
                            type="button"
                            wire:click="verTodosLosAlmacenes"
                            @class(['sh-seg-boton', 'sh-seg-activo' => $ningunAlmacen])
                            @if ($ningunAlmacen) aria-pressed="true" @endif
                        >
                            Todos
                            <span class="sh-seg-cuenta">{{ number_format($this->totalDeProductos()) }}</span>
                        </button>
                    @endif
                </div>
            @endif

            {{-- 2. Qué --}}
            <div class="sh-seg" role="group" aria-label="Qué mostrar">
                @foreach ($this->vistas() as $vista)
                    <button
                        type="button"
                        wire:key="vista-{{ $vista['clave'] }}"
                        wire:click="verVista('{{ $vista['clave'] }}')"
                        @class(['sh-seg-boton', 'sh-seg-activo' => $vista['activo']])
                        @if ($vista['activo']) aria-pressed="true" @endif
                    >
                        {{ $vista['etiqueta'] }}
                        <span @class(['sh-seg-cuenta', 'sh-seg-cuenta-alerta' => $vista['alerta']])>{{ number_format($vista['cuantos']) }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- 3. La tabla --}}
        {{ $this->table }}
    </div>

    <x-filament-actions::modals />

    @include('filament.partials.estilos-de-reporte')
</x-filament-panels::page>
