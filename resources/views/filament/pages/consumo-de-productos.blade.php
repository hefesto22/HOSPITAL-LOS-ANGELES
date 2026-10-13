{{--
    Consumo de productos (13-oct-2026): qué se usó, cuánto, cuándo y de
    qué estante salió. Sobre todo para lo que tiene stock infinito, que no
    tiene kardex que mirar (ver la clase).

    Mismo esqueleto que Honorarios por médico:

    1. La barra del período: hoy, esta semana, este mes, mes pasado, y
       las dos fechas para cualquier otro rango.
    2. De dónde: un control con cada estante del que salió algo y cuántos
       usos tuvo. Tocarlo deja todo —resumen y detalle— en ese estante.
    3. UN panel: cuántos usos hubo y, debajo, los productos del más usado
       al menos usado, con su cantidad y de qué estantes salió. Tocar una
       fila deja el detalle en ese producto.
    4. El detalle, uso por uso: cuándo, cuánto, de dónde y a qué paciente.

    ⚠️ La lista de productos puede ser larga en un mes movido: se ven los
    primeros y el resto se abre con «Ver todos», sin volver al servidor
    (Alpine). El producto elegido se ve siempre, aunque esté abajo.

    ⚠️ `wire:model.live.change` en las fechas, no `.live`: con `.live` cada
    tecla manda la fecha a medio escribir.
--}}
<x-filament-panels::page>
    @php
        $almacenes = $this->almacenes();
        $productos = $this->productos();
        $activo = $this->productoActivo();
        $aLaVista = \App\Filament\Pages\ConsumoDeProductos::PRODUCTOS_A_LA_VISTA;
        $usos = array_sum(array_column($productos, 'veces'));
        $infinitos = count(array_filter($productos, fn (array $producto): bool => $producto['infinito']));
        $ningunAlmacen = collect($almacenes)->doesntContain('activo', true);
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

        {{-- 2. De dónde se sacó --}}
        @if (count($almacenes) > 1)
            <div class="sh-seg sh-seg-ancho" role="group" aria-label="De qué estante">
                <button
                    type="button"
                    wire:click="verTodosLosAlmacenes"
                    @class(['sh-seg-boton', 'sh-seg-activo' => $ningunAlmacen])
                    @if ($ningunAlmacen) aria-pressed="true" @endif
                >
                    Todos los estantes
                    <span class="sh-seg-cuenta">{{ number_format($this->totalDeUsos()) }}</span>
                </button>

                @foreach ($almacenes as $almacen)
                    <button
                        type="button"
                        wire:key="almacen-{{ $almacen['id'] }}"
                        wire:click="verAlmacen({{ $almacen['id'] }})"
                        @class(['sh-seg-boton', 'sh-seg-activo' => $almacen['activo']])
                        @if ($almacen['activo']) aria-pressed="true" @endif
                    >
                        {{ $almacen['nombre'] }}
                        <span class="sh-seg-cuenta">{{ number_format($almacen['veces']) }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- 3. Cuánto y de qué --}}
        <div class="sh-panel" x-data="{ todos: false }">
            <div class="sh-cabeza">
                <span class="sh-etiqueta">
                    Usado · {{ $this->periodo() }}
                    @if (! $ningunAlmacen)
                        · {{ collect($almacenes)->firstWhere('activo', true)['nombre'] ?? '' }}
                    @endif
                </span>
                <span class="sh-valor">
                    {{ number_format($usos) }} {{ $usos === 1 ? 'uso' : 'usos' }}
                </span>
                <span class="sh-nota">
                    {{ count($productos) }} {{ count($productos) === 1 ? 'producto' : 'productos' }}
                    @if ($infinitos > 0)
                        · {{ $infinitos }} con stock infinito: se cuentan acá, no descuentan del inventario
                    @endif
                </span>
            </div>

            @if (count($productos) > 0)
                <table class="sh-tabla">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="sh-num sh-ocultable">Veces</th>
                            <th class="sh-num">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productos as $indice => $producto)
                            <tr
                                wire:key="producto-{{ $producto['id'] }}"
                                wire:click="verProducto({{ $producto['id'] }})"
                                wire:keydown.enter="verProducto({{ $producto['id'] }})"
                                tabindex="0"
                                @class(['sh-fila', 'sh-fila-activa' => $producto['activo']])
                                @if ($producto['activo']) aria-selected="true" @endif
                                @if ($indice >= $aLaVista && ! $producto['activo']) x-show="todos" x-cloak @endif
                                title="{{ $producto['activo'] ? 'Tocá otra vez para ver todos' : 'Ver solo este producto' }}"
                            >
                                <td>
                                    <span class="sh-tabla-nombre">
                                        {{ $producto['nombre'] }}
                                        @if ($producto['infinito'])
                                            <span class="sh-chip">Stock infinito</span>
                                        @endif
                                    </span>
                                    <span class="sh-tabla-detalle">
                                        {{ $producto['codigo'] }}
                                        @if ($producto['deDonde'] !== '')
                                            · {{ $producto['deDonde'] }}
                                        @endif
                                        <span class="sh-solo-cel">
                                            · {{ $producto['veces'] }} {{ $producto['veces'] === 1 ? 'vez' : 'veces' }}
                                        </span>
                                    </span>
                                </td>
                                <td class="sh-num sh-ocultable">{{ number_format($producto['veces']) }}</td>
                                <td class="sh-num sh-total">{{ $producto['cantidad'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if (count($productos) > $aLaVista)
                    <button type="button" class="sh-mas" x-on:click="todos = ! todos">
                        <span x-show="! todos">Ver los {{ count($productos) }} productos</span>
                        <span x-show="todos" x-cloak>Ver solo los {{ $aLaVista }} más usados</span>
                    </button>
                @endif
            @endif
        </div>

        {{-- 4. Uso por uso --}}
        <div class="sh-detalle">
            <div class="sh-detalle-cabeza">
                <h3 class="sh-detalle-titulo">
                    {{ $activo ? $activo['nombre'] : 'Cada uso, uno por uno' }}
                </h3>

                @if ($activo)
                    <button type="button" wire:click="verTodosLosProductos" class="sh-todos">
                        <x-filament::icon icon="heroicon-m-x-mark" class="sh-todos-icono" />
                        Ver todos los productos
                    </button>
                @endif
            </div>

            {{ $this->table }}
        </div>
    </div>

    @include('filament.partials.estilos-de-reporte')
</x-filament-panels::page>
