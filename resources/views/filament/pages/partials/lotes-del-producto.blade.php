{{--
    El detalle lote por lote de un producto, dentro del modal «Lotes» de
    Existencias (13-oct-2026).

    Solo para leer: dónde está cada lote, en qué envase vino, cuándo vence
    y cuánto queda. Para moverlo está el botón «Mover» del renglón.

    Usa los estilos de `filament.partials.estilos-de-reporte`, que ya
    están en la página.
--}}
<div class="sh">
    @if (count($lotes) === 0)
        <p class="sh-nota">No le queda nada en ningún almacén.</p>
    @else
        <div class="sh-panel">
            <table class="sh-tabla sh-tabla-sin-borde">
                <thead>
                    <tr>
                        <th>Dónde</th>
                        <th>Lote</th>
                        <th>Vence</th>
                        <th class="sh-num">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lotes as $lote)
                        <tr>
                            <td>
                                <span class="sh-tabla-nombre">{{ $lote['almacen'] }}</span>
                            </td>
                            <td>
                                <span class="sh-tabla-nombre">{{ $lote['lote'] }}</span>
                                @if ($lote['envase'] !== null)
                                    <span class="sh-tabla-detalle">{{ $lote['envase'] }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($lote['vence'] !== null)
                                    <span class="sh-vence sh-vence-{{ $lote['color'] }}">{{ $lote['vence'] }}</span>
                                    @if ($lote['cuanto'] !== null)
                                        <span class="sh-tabla-detalle">{{ $lote['cuanto'] }}</span>
                                    @endif
                                @else
                                    <span class="sh-tabla-detalle">—</span>
                                @endif
                            </td>
                            <td class="sh-num">
                                <span class="sh-tabla-nombre">{{ $lote['cantidad'] }}</span>
                                @if ($lote['enEnvases'] !== null)
                                    <span class="sh-tabla-detalle">{{ $lote['enEnvases'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
