{{--
    «Así se va a vender», dentro de «Fijar / Cambiar el precio»
    (13-oct-2026).

    Mauricio: «debería decir en cuánto se venderá cada cosa, una tabla
    resumen al crear un nuevo precio». Una fila por forma de venta, de la
    más grande a la suelta:

      · lo que cuesta HOY (si ya tenía precio) y lo NUEVO, con una marca
        que dice si sube o baja;
      · lo que paga cada edad con descuento de ley, con la ley que rige el
        día que se elige en «Rige desde».

    Se arma en el servidor (`FijarPrecioDeVentaAction::tabla()`) cada vez
    que cambia un número. Estilos propios: el CSS del panel viene
    compilado (§9.A7).
--}}
<div class="av">
    <div class="av-cabeza">
        <span class="av-titulo">Así se va a vender</span>
        @if ($filas !== [] && $hayHoy)
            <span class="av-tenue">Lo de hoy al lado de lo nuevo</span>
        @endif
    </div>

    @if ($filas === [])
        <div class="av-vacio">Escribí lo mínimo y acá aparece a cuánto se vende cada cosa.</div>
    @else
        <div class="av-marco">
            <table class="av-tabla">
                <thead>
                    <tr>
                        <th class="av-izq">Se vende</th>
                        @if ($hayHoy)
                            <th>Hoy</th>
                        @endif
                        <th>{{ $hayHoy ? 'Nuevo' : 'Precio' }}</th>
                        @foreach ($edades as $edad)
                            <th>{{ $edad }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($filas as $fila)
                        <tr @class(['av-principal' => $fila['principal']])>
                            <td class="av-izq">
                                <span class="av-forma">{{ $fila['rotulo'] }}</span>
                                @if ($fila['principal'])
                                    <span class="av-chip av-chip-acento">★ principal</span>
                                @elseif ($fila['recargo'] !== null)
                                    <span class="av-chip">{{ $fila['recargo'] }}</span>
                                @endif
                                @if ($fila['porUnidad'] !== null)
                                    <span class="av-unidad">{{ $fila['porUnidad']->formateado() }} c/u</span>
                                @endif
                            </td>

                            @if ($hayHoy)
                                <td class="av-tenue">{{ $fila['antes']?->formateado() ?? '—' }}</td>
                            @endif

                            <td>
                                <span class="av-nuevo">{{ $fila['lista']->formateado() }}</span>
                                @if ($hayHoy && $fila['cambio'] === 'sube')
                                    <span class="av-marca av-sube" title="Sube">▲</span>
                                @elseif ($hayHoy && $fila['cambio'] === 'baja')
                                    <span class="av-marca av-baja" title="Baja">▼</span>
                                @elseif ($hayHoy && $fila['cambio'] === 'nuevo')
                                    <span class="av-marca av-sube" title="No tenía precio propio">nuevo</span>
                                @endif
                            </td>

                            @foreach ($fila['porEdad'] as $paga)
                                <td>{{ $paga->formateado() }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="av-nota">
            «Precio» es lo que paga cualquiera, y lo que pagan los seguros. La lista se redondea hacia arriba:
            la cuarta edad nunca paga menos que lo mínimo.
        </p>
    @endif
</div>

<style>
    .av {
        --av-fondo: rgb(255 255 255);
        --av-anillo: rgb(9 9 11 / .1);
        --av-linea: rgb(9 9 11 / .07);
        --av-texto: rgb(9 9 11);
        --av-tenue: rgb(113 113 122);
        --av-acento: rgb(217 119 6);
        --av-acento-fondo: rgb(255 251 235);
        --av-sube: rgb(22 163 74);
        --av-baja: rgb(220 38 38);

        display: flex; flex-direction: column; gap: .5rem; color: var(--av-texto);
    }
    .dark .av {
        --av-fondo: rgb(24 24 27);
        --av-anillo: rgb(255 255 255 / .1);
        --av-linea: rgb(255 255 255 / .07);
        --av-texto: rgb(255 255 255);
        --av-tenue: rgb(161 161 170);
        --av-acento: rgb(251 191 36);
        --av-acento-fondo: rgb(251 191 36 / .08);
        --av-sube: rgb(74 222 128);
        --av-baja: rgb(248 113 113);
    }
    .av-cabeza { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; }
    .av-titulo { font-size: .875rem; font-weight: 600; }
    .av-tenue { color: var(--av-tenue); font-size: .75rem; }
    .av-vacio {
        padding: 1rem; border-radius: .75rem; text-align: center; font-size: .8125rem; color: var(--av-tenue);
        box-shadow: 0 0 0 1px var(--av-anillo);
    }
    .av-marco { overflow-x: auto; border-radius: .75rem; background: var(--av-fondo); box-shadow: 0 0 0 1px var(--av-anillo); }
    .av-tabla { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .av-tabla th {
        padding: .5rem .875rem; text-align: right; white-space: nowrap;
        font-size: .75rem; font-weight: 600; color: var(--av-tenue);
        border-bottom: 1px solid var(--av-linea);
    }
    .av-tabla td {
        padding: .625rem .875rem; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;
        border-top: 1px solid var(--av-linea);
    }
    .av-tabla tbody tr:first-child td { border-top: 0; }
    .av-tabla .av-izq { text-align: left; white-space: normal; }
    .av-principal td { background: var(--av-acento-fondo); }
    .av-forma { font-weight: 600; }
    .av-unidad { display: block; font-size: .75rem; color: var(--av-tenue); }
    .av-nuevo { font-weight: 700; }
    .av-chip {
        display: inline-block; margin-left: .25rem; padding: 0 .375rem; border-radius: 9999px;
        font-size: .6875rem; font-weight: 600; color: var(--av-tenue); box-shadow: inset 0 0 0 1px var(--av-anillo);
    }
    .av-chip-acento { color: var(--av-acento); box-shadow: inset 0 0 0 1px var(--av-acento); }
    .av-marca { margin-left: .25rem; font-size: .6875rem; font-weight: 700; }
    .av-sube { color: var(--av-sube); }
    .av-baja { color: var(--av-baja); }
    .av-nota { margin: 0; font-size: .75rem; color: var(--av-tenue); }
</style>
