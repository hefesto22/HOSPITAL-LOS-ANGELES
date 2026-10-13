{{--
    El historial de precios de un servicio, en un modal de su ficha
    (13-oct-2026). Reemplaza la pestaña «Precios» de abajo: los precios se
    escriben en la ficha, y acá se lee lo que rigió antes.

    Las filas las arma `EditItem::historialDe()`, la más nueva arriba.
    Estilos propios: el CSS del panel está precompilado (§9.A7).
--}}
<div class="hp">
    @if ($filas === [])
        <p class="hp-vacio">Todavía no tiene ningún precio.</p>
    @else
        <table class="hp-tabla">
            <thead>
                <tr>
                    <th>Pagador</th>
                    <th class="hp-derecha">Precio</th>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Por qué</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($filas as $fila)
                    <tr @class(['hp-vigente' => $fila['hasta'] === null])>
                        <td>
                            <span class="hp-pagador">{{ $fila['pagador'] }}</span>
                            @if ($fila['donde'] !== null)
                                <span class="hp-donde">{{ $fila['donde'] }}</span>
                            @endif
                        </td>
                        <td class="hp-derecha hp-precio">{{ $fila['precio'] }}</td>
                        <td class="hp-fecha">{{ $fila['desde'] }}</td>
                        <td class="hp-fecha">
                            @if ($fila['hasta'] === null)
                                <span class="hp-chip">Vigente</span>
                            @else
                                {{ $fila['hasta'] }}
                            @endif
                        </td>
                        <td class="hp-motivo">{{ $fila['motivo'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<style>
    .hp {
        --hp-linea: rgb(9 9 11 / .07);
        --hp-texto: rgb(9 9 11);
        --hp-tenue: rgb(113 113 122);
        --hp-acento: rgb(21 128 61);
        --hp-acento-fondo: rgb(220 252 231);

        overflow-x: auto;
    }
    .dark .hp {
        --hp-linea: rgb(255 255 255 / .08);
        --hp-texto: rgb(255 255 255);
        --hp-tenue: rgb(161 161 170);
        --hp-acento: rgb(134 239 172);
        --hp-acento-fondo: rgb(74 222 128 / .12);
    }
    .hp-vacio { font-size: .875rem; color: var(--hp-tenue); }
    .hp-tabla { width: 100%; border-collapse: collapse; }
    .hp-tabla th {
        padding: .5rem .75rem; text-align: left; white-space: nowrap;
        font-size: .75rem; font-weight: 500; color: var(--hp-tenue);
        border-bottom: 1px solid var(--hp-linea);
    }
    .hp-tabla td {
        padding: .6rem .75rem; vertical-align: top;
        font-size: .8125rem; color: var(--hp-tenue);
        border-bottom: 1px solid var(--hp-linea);
    }
    .hp-vigente td { color: var(--hp-texto); }
    .hp-derecha { text-align: right !important; }
    .hp-pagador { display: block; font-weight: 600; }
    .hp-donde { display: block; margin-top: .1rem; font-size: .75rem; color: var(--hp-tenue); }
    .hp-precio { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .hp-fecha { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .hp-chip {
        padding: .1rem .45rem; border-radius: .3rem; font-size: .6875rem; font-weight: 600;
        color: var(--hp-acento); background: var(--hp-acento-fondo);
    }
    .hp-motivo { min-width: 16rem; }
</style>
