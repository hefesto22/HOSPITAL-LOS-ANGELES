{{--
    «Precios» en la ficha del producto (rediseño del 13-oct-2026).

    Mauricio: «se ve demasiado saturado y no se entiende cómo funciona»,
    «¿cuál es el precio vigente? No se sabe cuáles son del historial».
    Ahora se lee de arriba abajo:

      1. Precio vigente: a cuánto se vende HOY cada forma —caja, blíster,
         suelta— y lo que paga cada edad con la ley de hoy. Es lo que el
         cargo cobra, resuelto con la misma regla.
      2. Si con la ley de hoy alguna edad paga menos que lo mínimo que se
         escribió —porque el descuento subió—, un aviso en rojo.
      3. Cómo sale el precio, en una línea, y por qué cambió la última vez.
      4. Lo demás, plegado: lo que ya no rige, lo de otra sede, lo que
         acá no se usa porque le gana el de la sede, y lo que empieza más
         adelante. Agrupado por cambio, no fila por fila.

    Los datos los arma `ResumenDePrecios`. El botón es de
    `PreciosRelationManager`, y como esta vista no lleva la tabla de
    Filament, las ventanas de las acciones se pintan acá abajo a mano.

    Estilos propios: el CSS del panel viene compilado (§9.A7).
--}}
<div class="fi-resource-relation-manager">
    <div class="pv">
        <header class="pv-cabeza">
            <div>
                <h3 class="pv-titulo">Precio vigente</h3>
                <p class="pv-sub">
                    Lo que se cobra hoy{{ $sede === null ? '' : ' en '.$sede }}
                    @if ($resumen !== null && $resumen['desde'] !== null)
                        · rige desde el {{ $resumen['desde']->format('d/m/Y') }}
                    @endif
                    @if ($llevaIsv)
                        · antes del ISV
                    @endif
                </p>
            </div>

            {{--
                Solo las que se ven: una acción escondida, pintada a mano,
                sale igual como un botón apagado.
            --}}
            <div class="pv-acciones">
                @foreach ([$this->fijarPrecioDeVentaAction, $this->fijarPrecioAction] as $accion)
                    @if ($accion->isVisible())
                        {{ $accion }}
                    @endif
                @endforeach
            </div>
        </header>

        @if ($resumen !== null && $resumen['debajoDelMinimo'] !== null)
            <div class="pv-alerta" role="alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="pv-alerta-icono" />
                <p>
                    Con el descuento de ley de hoy, la <strong>{{ mb_strtolower($resumen['debajoDelMinimo']['rango']) }}</strong>
                    paga <strong>{{ $resumen['debajoDelMinimo']['paga']->formateado() }}</strong> por {{ $resumen['debajoDelMinimo']['rotulo'] }}:
                    menos que lo mínimo, <strong>{{ $resumen['debajoDelMinimo']['minimo']->formateado() }}</strong>.
                    Cuando cambia el descuento los precios se ajustan solos en unos segundos; si este aviso sigue,
                    cambiá el precio con el mismo mínimo.
                </p>
            </div>
        @endif

        @if ($resumen === null || $resumen['formas'] === [])
            <div class="pv-vacio">
                <x-filament::icon icon="heroicon-o-banknotes" class="pv-vacio-icono" />
                <strong>Todavía no tiene precio</strong>
                <span>Sin precio no se puede cargar a una cuenta: el sistema no inventa uno.</span>
            </div>
        @else
            <div class="pv-marco">
                <table class="pv-tabla">
                    <thead>
                        <tr>
                            <th class="pv-izq">Se vende</th>
                            <th>Precio</th>
                            @if ($resumen['hayDescuentoPorEdad'])
                                @foreach ($resumen['formas'][0]['porEdad'] as $edad)
                                    <th>{{ $edad['rango'] }}</th>
                                @endforeach
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resumen['formas'] as $forma)
                            <tr @class(['pv-principal' => $forma['esLaPrincipal']])>
                                <td class="pv-izq">
                                    <span class="pv-forma">{{ $forma['rotulo'] }}</span>
                                    @if ($forma['esLaPrincipal'])
                                        <span class="pv-chip pv-chip-acento">★ principal</span>
                                    @elseif ($forma['recargo'] !== null)
                                        <span class="pv-chip">{{ $forma['recargo'] }}</span>
                                    @endif

                                    @if ($forma['porUnidad'] !== null && $forma['propio'])
                                        <span class="pv-detalle">{{ $forma['porUnidad']->formateado() }} c/u</span>
                                    @elseif (! $forma['propio'])
                                        <span class="pv-detalle pv-ojo">Sin precio propio: se cobra como {{ $forma['contenido'] }} sueltas</span>
                                    @endif
                                </td>
                                <td><span class="pv-precio">{{ $forma['lista']->formateado() }}</span></td>
                                @if ($resumen['hayDescuentoPorEdad'])
                                    @foreach ($forma['porEdad'] as $edad)
                                        <td>{{ $edad['paga']->formateado() }}</td>
                                    @endforeach
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($seVendePorFormas)
                <div class="pv-como">
                    <x-filament::icon icon="heroicon-o-information-circle" class="pv-como-icono" />
                    <p>
                        @if ($resumen['origen'] !== null)
                            <strong>Cómo sale.</strong>
                            Se escribió lo mínimo de {{ $resumen['principal'] }}:
                            <strong>{{ $resumen['minimo']?->formateado() }}</strong>,
                            lo que paga la cuarta edad. De ahí sale el precio para todos, redondeado hacia arriba,
                            y lo demás con su recargo por unidad. Los seguros pagan este mismo precio.
                        @else
                            <strong>Cómo funciona.</strong>
                            Se escribe lo mínimo del envase principal —lo que paga la cuarta edad— y de ahí salen el
                            precio para todos y el de cada forma de venta. Los seguros pagan este mismo precio.
                        @endif
                    </p>
                </div>
            @endif

            @if ($resumen['porQue'] !== null)
                <p class="pv-porque"><span>Por qué cambió:</span> {{ $resumen['porQue'] }}</p>
            @endif

            @if ($resumen['sinPrecio'] !== [])
                <p class="pv-porque pv-ojo">Sin precio todavía: {{ implode(', ', $resumen['sinPrecio']) }}.</p>
            @endif
        @endif

        @if ($resumen !== null && $resumen['seguros'] !== [])
            <div class="pv-bloque">
                <span class="pv-bloque-titulo">Precios propios de seguros</span>
                <ul class="pv-seguros">
                    @foreach ($resumen['seguros'] as $seguro)
                        <li>
                            <span>{{ $seguro['pagador'] }} <span class="pv-tenue">· {{ $seguro['rotulo'] }}</span></span>
                            <strong>{{ $seguro['monto']->formateado() }}</strong>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($resumen !== null && $resumen['otros'] !== [])
            <details class="pv-historial">
                <summary>
                    <span>Historial y otros precios</span>
                    <span class="pv-cuenta">{{ count($resumen['otros']) }}</span>
                    <span class="pv-tenue pv-chico">No se cobran hoy acá</span>
                </summary>

                <ol class="pv-cambios">
                    @foreach ($resumen['otros'] as $cambio)
                        <li class="pv-cambio">
                            <div class="pv-cambio-cabeza">
                                <span class="pv-fechas">
                                    {{ $cambio['desde']->format('d/m/Y') }}
                                    @if ($cambio['hasta'] !== null)
                                        – {{ $cambio['hasta']->format('d/m/Y') }}
                                    @endif
                                </span>

                                <span class="pv-estado pv-estado-{{ $cambio['estado'] }}">
                                    @switch ($cambio['estado'])
                                        @case ('proximo')
                                            Empieza más adelante
                                            @break
                                        @case ('tapado')
                                            Acá no se usa: manda el de la sede
                                            @break
                                        @case ('otraSede')
                                            Rige en otra sede
                                            @break
                                        @default
                                            Ya no rige
                                    @endswitch
                                </span>

                                <span class="pv-tenue pv-chico">
                                    {{ $cambio['donde'] }}{{ $cambio['pagador'] === 'Precio de lista' ? '' : ' · '.$cambio['pagador'] }}
                                </span>
                            </div>

                            <div class="pv-montos">
                                @foreach ($cambio['precios'] as $precio)
                                    <span><span class="pv-tenue">{{ $precio['rotulo'] }}</span> {{ $precio['monto']->formateado() }}</span>
                                @endforeach
                            </div>

                            @if ($cambio['porQue'] !== null)
                                <div class="pv-tenue pv-chico">{{ $cambio['porQue'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    </div>

    <x-filament-actions::modals />

    <style>
        .pv {
            --pv-fondo: rgb(255 255 255);
            --pv-suave: rgb(250 250 250);
            --pv-anillo: rgb(9 9 11 / .1);
            --pv-linea: rgb(9 9 11 / .07);
            --pv-texto: rgb(9 9 11);
            --pv-tenue: rgb(113 113 122);
            --pv-acento: rgb(217 119 6);
            --pv-acento-fondo: rgb(255 251 235);
            --pv-mal: rgb(220 38 38);
            --pv-mal-fondo: rgb(254 242 242);
            --pv-ojo: rgb(161 98 7);
            --pv-bien: rgb(22 163 74);
            --pv-bien-fondo: rgb(240 253 244);

            display: flex; flex-direction: column; gap: 1rem;
            padding: 1.25rem; border-radius: .875rem;
            background: var(--pv-fondo); box-shadow: 0 0 0 1px var(--pv-anillo);
            color: var(--pv-texto);
        }
        .dark .pv {
            --pv-fondo: rgb(24 24 27);
            --pv-suave: rgb(255 255 255 / .03);
            --pv-anillo: rgb(255 255 255 / .1);
            --pv-linea: rgb(255 255 255 / .07);
            --pv-texto: rgb(255 255 255);
            --pv-tenue: rgb(161 161 170);
            --pv-acento: rgb(251 191 36);
            --pv-acento-fondo: rgb(251 191 36 / .07);
            --pv-mal: rgb(248 113 113);
            --pv-mal-fondo: rgb(248 113 113 / .08);
            --pv-ojo: rgb(250 204 21);
            --pv-bien: rgb(74 222 128);
            --pv-bien-fondo: rgb(74 222 128 / .08);
        }
        .pv-cabeza { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .pv-titulo { margin: 0; font-size: 1rem; font-weight: 600; }
        .pv-sub { margin: .125rem 0 0; font-size: .8125rem; color: var(--pv-tenue); }
        .pv-acciones { display: flex; flex-wrap: wrap; gap: .5rem; }
        .pv-tenue { color: var(--pv-tenue); }
        .pv-chico { font-size: .75rem; }
        .pv-ojo { color: var(--pv-ojo); }

        .pv-alerta {
            display: flex; gap: .625rem; align-items: flex-start;
            padding: .75rem .875rem; border-radius: .75rem;
            background: var(--pv-mal-fondo); box-shadow: 0 0 0 1px var(--pv-mal);
            font-size: .8125rem; line-height: 1.3rem;
        }
        .pv-alerta p { margin: 0; }
        .pv-alerta-icono { flex: none; width: 1.25rem; height: 1.25rem; color: var(--pv-mal); }

        .pv-vacio {
            display: flex; flex-direction: column; align-items: center; gap: .25rem;
            padding: 1.5rem 1rem; border-radius: .75rem; text-align: center;
            font-size: .8125rem; color: var(--pv-tenue); box-shadow: 0 0 0 1px var(--pv-anillo);
        }
        .pv-vacio strong { color: var(--pv-texto); font-size: .875rem; }
        .pv-vacio-icono { width: 1.75rem; height: 1.75rem; opacity: .6; }

        .pv-marco { overflow-x: auto; border-radius: .75rem; box-shadow: 0 0 0 1px var(--pv-anillo); }
        .pv-tabla { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .pv-tabla th {
            padding: .5rem 1rem; text-align: right; white-space: nowrap;
            font-size: .75rem; font-weight: 600; color: var(--pv-tenue);
            background: var(--pv-suave); border-bottom: 1px solid var(--pv-linea);
        }
        .pv-tabla td {
            padding: .75rem 1rem; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;
            border-top: 1px solid var(--pv-linea);
        }
        .pv-tabla tbody tr:first-child td { border-top: 0; }
        .pv-tabla .pv-izq { text-align: left; white-space: normal; }
        .pv-principal td { background: var(--pv-acento-fondo); }
        .pv-forma { font-weight: 600; }
        .pv-precio { font-size: 1rem; font-weight: 700; }
        .pv-detalle { display: block; margin-top: .125rem; font-size: .75rem; color: var(--pv-tenue); }
        .pv-detalle.pv-ojo { color: var(--pv-ojo); }
        .pv-chip {
            display: inline-block; margin-left: .375rem; padding: 0 .4375rem; border-radius: 9999px;
            font-size: .6875rem; font-weight: 600; line-height: 1.1rem; vertical-align: middle;
            color: var(--pv-tenue); box-shadow: inset 0 0 0 1px var(--pv-anillo);
        }
        .pv-chip-acento { color: var(--pv-acento); box-shadow: inset 0 0 0 1px var(--pv-acento); }

        .pv-como {
            display: flex; gap: .625rem; align-items: flex-start;
            padding: .75rem .875rem; border-radius: .75rem; background: var(--pv-suave);
            font-size: .8125rem; line-height: 1.3rem; color: var(--pv-tenue);
        }
        .pv-como p { margin: 0; }
        .pv-como strong { color: var(--pv-texto); }
        .pv-como-icono { flex: none; width: 1.125rem; height: 1.125rem; margin-top: .0625rem; }
        .pv-porque { margin: 0; font-size: .8125rem; }
        .pv-porque span { color: var(--pv-tenue); }

        .pv-bloque { display: flex; flex-direction: column; gap: .5rem; }
        .pv-bloque-titulo { font-size: .8125rem; font-weight: 600; }
        .pv-seguros { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; font-size: .8125rem; }
        .pv-seguros li { display: flex; justify-content: space-between; gap: 1rem; padding: .375rem 0; border-top: 1px solid var(--pv-linea); }
        .pv-seguros li:first-child { border-top: 0; }

        .pv-historial { border-top: 1px solid var(--pv-linea); padding-top: .75rem; }
        .pv-historial summary {
            display: flex; align-items: center; gap: .5rem; cursor: pointer; list-style: none;
            font-size: .8125rem; font-weight: 600;
        }
        .pv-historial summary::-webkit-details-marker { display: none; }
        .pv-historial summary::before {
            content: '›'; display: inline-block; width: .75rem; font-size: 1rem; line-height: 1;
            color: var(--pv-tenue); transition: transform .12s ease;
        }
        .pv-historial[open] summary::before { transform: rotate(90deg); }
        .pv-cuenta {
            min-width: 1.25rem; padding: 0 .375rem; border-radius: 9999px; text-align: center;
            font-size: .6875rem; line-height: 1.1rem; color: var(--pv-tenue); box-shadow: inset 0 0 0 1px var(--pv-anillo);
        }
        .pv-cambios { display: flex; flex-direction: column; gap: .5rem; margin: .75rem 0 0; padding: 0; list-style: none; }
        .pv-cambio {
            display: flex; flex-direction: column; gap: .375rem;
            padding: .625rem .875rem; border-radius: .625rem; background: var(--pv-suave);
        }
        .pv-cambio-cabeza { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .625rem; }
        .pv-fechas { font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .pv-estado {
            padding: 0 .4375rem; border-radius: 9999px; font-size: .6875rem; font-weight: 600; line-height: 1.1rem;
            color: var(--pv-tenue); box-shadow: inset 0 0 0 1px var(--pv-anillo);
        }
        .pv-estado-proximo { color: var(--pv-bien); background: var(--pv-bien-fondo); box-shadow: none; }
        .pv-estado-tapado, .pv-estado-otraSede { color: var(--pv-ojo); box-shadow: inset 0 0 0 1px var(--pv-ojo); }
        .pv-montos { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .8125rem; font-variant-numeric: tabular-nums; }
    </style>
</div>
