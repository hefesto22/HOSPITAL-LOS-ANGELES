{{--
    La columna «Dónde está» de Existencias (13-oct-2026).

    Un renglón por almacén: el nombre a la izquierda —con un punto, de
    color si es el elegido arriba— y a la derecha cuánto hay (lo real) o
    qué hay (lo infinito: CAJA, BLISTER, TAB, en etiquetas chicas; el
    nombre entero al pasar el mouse). Antes era un chip largo por almacén
    y tres seguidos desbordaban la tabla.

    El estado lo arma `Existencias::dondeEstaDe()`. Los estilos están en
    `filament.partials.estilos-de-reporte`, que la página ya incluye.
--}}
@php
    $lugares = $getState();
    $lugares = is_array($lugares) ? $lugares : [];
@endphp

<div class="sh-donde">
    @foreach ($lugares as $lugar)
        <span @class(['sh-donde-almacen', 'sh-donde-elegido' => $lugar['elegido']])>{{ $lugar['nombre'] }}</span>

        <span class="sh-donde-que">
            @if ($lugar['cantidad'] !== null)
                <span class="sh-donde-cantidad">{{ $lugar['cantidad'] }}</span>
            @endif

            @foreach ($lugar['envases'] as $envase)
                <span class="sh-donde-tag" title="{{ $envase['largo'] }}">{{ $envase['corto'] }}</span>
            @endforeach

            @if ($lugar['sueltas'] !== null)
                <span class="sh-donde-tag sh-donde-tag-suelta" title="Unidades sueltas">{{ $lugar['sueltas'] }}</span>
            @endif
        </span>
    @endforeach
</div>
