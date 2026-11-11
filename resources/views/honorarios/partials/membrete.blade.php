{{--
    El encabezado de cada hoja del reporte de honorarios: el hospital a la
    izquierda y, a la derecha, qué reporte es y de qué período.

    Igual que la factura: el logo va incrustado (una imagen que todavía
    se está descargando sale como un hueco al imprimir) y el nombre del
    hospital se imprime aunque haya logo. Sin sede —una instalación recién
    armada— sale el nombre de la aplicación.

    Recibe: $sede (?Sede), $logo y $telefono —calculados UNA vez por la
    vista: con el detalle de noventa médicos son noventa encabezados, y el
    logo en base64 noventa veces era megas de más—, $titulo, $etiqueta,
    $impresoEl y $impresoPor.
--}}
<header class="encabezado">
    <div @class(['membrete', 'sin-logo' => ! $logo])>
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $sede?->nombre }}" class="logo">
        @endif

        <p class="razon">{{ mb_strtoupper($sede?->razon_social ?? config('app.name', 'SIHLA')) }}</p>
        @if ($sede?->direccion)
            <p>{{ $sede->direccion }}</p>
        @endif
        @if ($telefono)
            <p>Tel. {{ $telefono }}</p>
        @endif
        @if ($sede?->rtn)
            <p class="rtn">R.T.N. {{ $sede->rtn }}</p>
        @endif
    </div>

    <div class="documento">
        <div class="tipo">HONORARIOS</div>
        <div class="subtipo">{{ $titulo }}</div>
        <table class="fechas">
            <tr>
                <td>Período</td>
                <td>{{ $etiqueta }}</td>
            </tr>
            <tr>
                <td>Impreso</td>
                <td>{{ $impresoEl }}</td>
            </tr>
            @if ($impresoPor !== '')
                <tr>
                    <td>Por</td>
                    <td>{{ $impresoPor }}</td>
                </tr>
            @endif
        </table>
    </div>
</header>

<div class="franja"></div>
