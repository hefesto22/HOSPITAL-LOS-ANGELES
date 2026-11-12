{{--
    El consumo de productos, para papel (Mauricio, 11-nov-2026: «que se
    pueda imprimir»).

    Lo mismo que la pantalla y en el mismo orden: cuántos usos y cuántos
    productos, y la lista de lo que se usó, del más usado al menos usado,
    con de qué estantes salió. Con «Con cada uso» van además todos los
    usos uno por uno —cuándo, cuánto, de dónde y a qué paciente—, con el
    producto y el filtro «Origen» que estaban elegidos en la pantalla.

    Los números salen de `ConsumoDelPeriodo`, igual que la pantalla: el
    papel no hace cuentas, solo las escribe.

    Se ve en pantalla como hojas sobre fondo gris y se imprime con Ctrl+P
    (§13.4). Al pie de cada hoja, qué reporte es y «Página X de Y», con las
    cajas de margen de `@page` (Chrome 131+), como el Reporte del mes, del
    que copia la hoja.

    ⚠️ Lo de PHP va en un bloque, nunca en una línea, y esas directivas no
    se nombran en un comentario: el compilador se tragaría el HTML (§9.M18).
--}}
@php
    $totalDeUsos = array_sum(array_column($productos, 'veces'));
    $infinitos = count(array_filter($productos, fn (array $producto): bool => $producto['infinito']));
    $conDetalle = $usos !== null;

    $titulo = 'Consumo de productos'.($nombreDelAlmacen === null ? '' : ' · '.$nombreDelAlmacen);
    $alPie = str_replace(['"', '\\', '<', '>', "\n", "\r"], '', $titulo.' · '.$etiqueta);

    $soloDe = array_values(array_filter([
        $nombreDelProducto,
        $origen === 'sin_inventario' ? 'solo stock infinito' : null,
        $origen === 'inventario' ? 'solo lo que descontó inventario' : null,
    ]));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titulo }} · {{ $etiqueta }}</title>
    <style>
        @page {
            size: letter;
            margin: 12mm 12mm 14mm;

            @bottom-left {
                content: "{!! $alPie !!}";
                font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 7px; color: #64748b;
            }

            @bottom-right {
                content: "Página " counter(page) " de " counter(pages);
                font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 7px; color: #64748b;
            }
        }

        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        :root {
            --tinta: #0f172a;
            --texto: #1e293b;
            --suave: #64748b;
            --raya: #cbd5e1;
            --raya-fina: #e2e8f0;
            --fondo: #f1f5f9;
            --marca: #0c4a6e;
            --marca-viva: #0ea5e9;
        }

        body {
            margin: 0; background: #e5e7eb; color: var(--texto);
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 9.5px; line-height: 1.4;
        }

        .barra {
            position: sticky; top: 0; z-index: 1;
            display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; align-items: center;
            max-width: 216mm; margin: 0 auto; padding: 10px 0;
        }
        .barra-titulo { margin-right: auto; font-size: 13px; font-weight: 600; color: #3f3f46; }
        .barra button, .barra a {
            padding: 7px 14px; font-size: 12px; font-weight: 500; border-radius: 7px; cursor: pointer;
            border: 1px solid #d4d4d8; background: #fff; color: #18181b; text-decoration: none;
        }
        .barra .principal { border-color: var(--marca); background: var(--marca); color: #fff; }

        .pagina {
            width: 216mm; min-height: 279mm; margin: 0 auto 10mm; padding: 12mm;
            background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / .12);
        }

        /* Adentro del modal: sin barra, y sin barra horizontal. */
        body.incrustada { background: #f4f4f5; overflow-x: hidden; }
        body.incrustada .pagina { width: auto; max-width: 216mm; margin: 0 0 6mm; }

        @media print {
            body { background: #fff; }
            .no-imprime { display: none !important; }
            .pagina { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .tabla tr, .bloque, .cierre { break-inside: avoid; }
            .titulo-de-seccion { break-after: avoid; }
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }

        /* ── El encabezado (`honorarios.partials.membrete`) ── */
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 10mm; }
        .membrete { min-width: 0; }
        .logo { display: block; max-height: 16mm; max-width: 72mm; margin-bottom: 2.5mm; }
        .membrete p { margin: 1px 0 0; color: var(--suave); font-size: 8.8px; }
        .membrete .razon { margin: 0; font-size: 11.5px; font-weight: 700; color: var(--tinta); letter-spacing: .02em; }
        .membrete.sin-logo .razon { font-size: 16px; }
        .membrete .rtn { color: var(--texto); font-weight: 600; }

        .documento { flex: none; min-width: 60mm; text-align: right; }
        .documento .tipo { font-size: 21px; font-weight: 300; letter-spacing: .28em; margin-right: -.28em; color: var(--marca); line-height: 1; }
        .documento .subtipo { margin-top: 2mm; font-size: 8px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--suave); }
        .fechas { width: auto; margin: 2.5mm 0 0 auto; }
        .fechas td { padding: 1px 0 1px 6mm; text-align: right; font-size: 9px; }
        .fechas td:first-child { padding-top: 2px; font-size: 7.5px; letter-spacing: .08em; text-transform: uppercase; color: var(--suave); }
        .fechas td:last-child { font-weight: 600; color: var(--tinta); font-variant-numeric: tabular-nums; }

        .franja {
            height: 3px; margin: 4mm 0 4mm; border-radius: 2px;
            background: linear-gradient(90deg, var(--marca) 0 70%, var(--marca-viva) 70% 100%);
        }

        .rotulo {
            display: block; margin-bottom: 1px; font-size: 7px; font-weight: 700; letter-spacing: .14em;
            text-transform: uppercase; color: var(--suave);
        }

        /* ── Las cifras ── */
        .cifras { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--raya); border-radius: 6px; background: var(--fondo); }
        .cifras > div { padding: 3mm 3.5mm; min-width: 0; }
        .cifras > div + div { border-left: 1px solid var(--raya); }
        .cifras .valor { display: block; font-size: 14px; font-weight: 700; color: var(--tinta); white-space: nowrap; }
        .cifras .debajo { display: block; margin-top: .5mm; font-size: 7.8px; color: var(--suave); }
        .cifras .principal { background: var(--marca); border-radius: 5px 0 0 5px; }
        .cifras .principal .rotulo, .cifras .principal .debajo { color: #cbd5e1; }
        .cifras .principal .valor { color: #fff; font-size: 16px; }

        .seccion { margin-top: 5mm; }

        .titulo-de-seccion {
            margin: 0 0 1.5mm; font-size: 10px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--tinta);
        }
        .titulo-de-seccion span { margin-left: 1.5mm; font-size: 7.8px; font-weight: 400; letter-spacing: .02em; text-transform: none; color: var(--suave); }

        /* ── Las tablas ── */
        .tabla { table-layout: fixed; }
        .tabla thead { display: table-header-group; }
        .tabla thead th {
            padding: 1.8mm 2mm; text-align: left; font-size: 7px; font-weight: 700; letter-spacing: .12em;
            text-transform: uppercase; color: var(--marca); background: var(--fondo);
            border-top: 1.5px solid var(--marca); border-bottom: 1px solid var(--raya);
        }
        .tabla tbody td { padding: 1.4mm 2mm; border-bottom: 1px solid var(--raya-fina); font-size: 9px; overflow-wrap: break-word; }
        .tabla tbody tr.par td { background: #f8fafc; }
        .tabla tfoot { display: table-row-group; }
        .tabla tfoot td {
            padding: 2mm 2mm; font-size: 9.5px; font-weight: 700; color: var(--tinta);
            border-top: 1.5px solid var(--marca); background: var(--fondo);
        }

        .num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .numero { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 8.4px !important; }
        .orden { color: var(--suave); font-variant-numeric: tabular-nums; }
        .fuerte { font-weight: 600; color: var(--tinta); }
        .debajo { display: block; margin-top: .3mm; font-size: 7.6px; color: var(--suave); font-weight: 400; }
        .chip {
            display: inline-block; margin-left: 1.2mm; padding: 0 1.2mm; border-radius: 2px; vertical-align: .2mm;
            font-size: 6.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
            color: #075985; background: #e0f2fe;
        }

        .aviso {
            margin: 0 0 2mm; padding: 2.2mm 3.5mm; border: 1px solid #f59e0b; border-radius: 6px;
            background: #fffbeb; font-size: 8.6px; color: #78350f;
        }

        .vacio {
            margin: 0; padding: 4mm; border: 1px dashed var(--raya); border-radius: 6px;
            text-align: center; font-size: 9px; color: var(--suave);
        }

        /* ── El cierre ── */
        .cierre { margin-top: 6mm; }
        .nota { margin: 0; padding: 2.2mm 3.5mm; border-left: 3px solid var(--marca-viva); background: var(--fondo); font-size: 8.2px; color: var(--suave); }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 14mm; margin-top: 4mm; }
        .firma { padding-top: 14mm; border-bottom: 1px solid var(--texto); }
        .firma-rotulo { margin-top: 1.2mm; text-align: center; font-size: 8px; color: var(--suave); }
    </style>
</head>
<body @class(['incrustada' => $incrustada])>
    @unless ($incrustada)
        <div class="barra no-imprime">
            <span class="barra-titulo">{{ $titulo }} · {{ $etiqueta }}</span>
            <a href="{{ $otraVersion }}">{{ $conDetalle ? 'Solo el resumen' : 'Con cada uso' }}</a>
            <a href="{{ $volver }}">Volver a la pantalla</a>
            <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        </div>
    @endunless

    <section class="pagina">
        @include('honorarios.partials.membrete', [
            'sede'       => $sede,
            'logo'       => $sede?->logoIncrustado(),
            'telefono'   => $sede?->telefonoImpreso(),
            'tipo'       => 'REPORTE',
            'titulo'     => $titulo,
            'etiqueta'   => $etiqueta,
            'impresoEl'  => $impresoEl,
            'impresoPor' => $impresoPor,
        ])

        {{-- 1. Las cifras --}}
        <section class="cifras bloque">
            <div class="principal">
                <span class="rotulo">Usos</span>
                <span class="valor">{{ number_format($totalDeUsos) }}</span>
                <span class="debajo">{{ $totalDeUsos === 1 ? 'vez que se cargó un producto' : 'veces que se cargó un producto' }}</span>
            </div>
            <div>
                <span class="rotulo">Productos</span>
                <span class="valor">{{ number_format(count($productos)) }}</span>
                <span class="debajo">distintos, del más usado al menos usado</span>
            </div>
            <div>
                <span class="rotulo">Con stock infinito</span>
                <span class="valor">{{ number_format($infinitos) }}</span>
                <span class="debajo">se cuentan acá; no descuentan del inventario</span>
            </div>
        </section>

        {{-- 2. Lo que se usó --}}
        <section class="seccion">
            <h2 class="titulo-de-seccion">
                Lo que se usó
                <span>cantidades en la unidad en que se despacha y se cobra</span>
            </h2>

            @if ($productos === [])
                <p class="vacio">No se le cargó ningún producto a un paciente en el período.</p>
            @else
                <table class="tabla">
                    <colgroup>
                        <col style="width: 8mm">
                        <col>
                        <col style="width: 52mm">
                        <col style="width: 15mm">
                        <col style="width: 26mm">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Producto</th>
                            <th>De dónde salió</th>
                            <th class="num">Veces</th>
                            <th class="num">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productos as $producto)
                            <tr @class(['par' => $loop->even])>
                                <td class="orden">{{ $loop->iteration }}</td>
                                <td>
                                    <span class="fuerte">{{ $producto['nombre'] }}</span>
                                    @if ($producto['infinito'])
                                        <span class="chip">Stock infinito</span>
                                    @endif
                                    <span class="debajo">{{ $producto['codigo'] }}</span>
                                </td>
                                <td>{{ $producto['deDonde'] }}</td>
                                <td class="num">{{ number_format($producto['veces']) }}</td>
                                <td class="num fuerte">{{ $producto['cantidad'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">{{ count($productos) }} {{ count($productos) === 1 ? 'producto' : 'productos' }}</td>
                            <td class="num">{{ number_format($totalDeUsos) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </section>

        {{-- 3. Cada uso, uno por uno --}}
        @if ($conDetalle)
            <section class="seccion">
                <h2 class="titulo-de-seccion">
                    Cada uso, uno por uno
                    <span>
                        {{ number_format($usos['total']) }} {{ $usos['total'] === 1 ? 'uso' : 'usos' }}, del más reciente al más viejo
                        @if ($soloDe !== [])
                            · {{ implode(' · ', $soloDe) }}
                        @endif
                    </span>
                </h2>

                @if ($usos['total'] > count($usos['filas']))
                    <p class="aviso">
                        Salen los {{ number_format(count($usos['filas'])) }} más recientes de {{ number_format($usos['total']) }}.
                        Para tenerlos todos en papel, elegí un período más corto o un solo estante.
                    </p>
                @endif

                @if ($usos['filas'] === [])
                    <p class="vacio">Sin usos con lo elegido.</p>
                @else
                    <table class="tabla">
                        <colgroup>
                            <col style="width: 24mm">
                            @if ($nombreDelProducto === null)
                                <col>
                            @endif
                            <col style="width: 20mm">
                            <col style="width: 34mm">
                            <col @if ($nombreDelProducto === null) style="width: 50mm" @endif>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Cuándo</th>
                                @if ($nombreDelProducto === null)
                                    <th>Producto</th>
                                @endif
                                <th class="num">Cantidad</th>
                                <th>De dónde</th>
                                <th>Paciente</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($usos['filas'] as $uso)
                                <tr @class(['par' => $loop->even])>
                                    <td class="numero">{{ $uso['cuando'] }}</td>
                                    @if ($nombreDelProducto === null)
                                        <td>
                                            {{ $uso['producto'] }}
                                            <span class="debajo">{{ $uso['codigo'] }}</span>
                                        </td>
                                    @endif
                                    <td class="num fuerte">{{ $uso['cantidad'] }}</td>
                                    <td>
                                        {{ $uso['almacen'] }}
                                        <span class="debajo">{{ $uso['origen'] }}</span>
                                    </td>
                                    <td>
                                        {{ $uso['paciente'] }}
                                        <span class="debajo">{{ $uso['cuenta'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @endif

        <div class="cierre">
            <p class="nota">
                Cuenta lo que se le cargó a los pacientes en el período, por el día del hospital en que se cargó.
                Lo que se cargó por error y se anuló no cuenta; lo que se pasó a otra cuenta cuenta una vez. Los
                productos con stock infinito se cuentan acá aunque no descuenten del inventario.
                @unless ($conDetalle)
                    Para ver los usos uno por uno, con el paciente, elegí «Con cada uso».
                @endunless
            </p>

            <div class="firmas">
                <div>
                    <div class="firma"></div>
                    <div class="firma-rotulo">Elaboró</div>
                </div>
                <div>
                    <div class="firma"></div>
                    <div class="firma-rotulo">Revisó</div>
                </div>
            </div>
        </div>
    </section>
</body>
</html>
