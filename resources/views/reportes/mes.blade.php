{{--
    El reporte del mes, para papel (Mauricio, 11-nov-2026: «que se pueda
    imprimir»).

    Lo mismo que la pantalla y en el mismo orden: las cuatro cifras, lo
    facturado, lo que ENTRÓ de verdad separado de lo que falta cobrar a los
    seguros, lo que queda después de medicamentos y honorarios —partido en
    lo que sale de lo que ya entró y lo que está por cobrar— y lo que quedó
    a crédito de cada seguro. Con
    «Con las facturas», al final van todas, una por una, las anuladas
    tachadas: el papel tiene que poder cuadrarse contra el talonario.

    Los números salen de `ResumenDeFacturacion`, igual que la pantalla: el
    papel no hace cuentas, solo las escribe.

    Se ve en pantalla como hojas sobre fondo gris y se imprime con Ctrl+P
    (§13.4). Al pie de cada hoja, qué reporte es y «Página X de Y», con las
    cajas de margen de `@page` (Chrome 131+), como la factura.

    ⚠️ Lo de PHP va en bloques, nunca en una línea, y esas directivas no se
    nombran en un comentario: el compilador se tragaría el HTML (§9.M18).
--}}
@php
    $facturado = $resumen['facturado'];
    $anuladas = $resumen['anuladas'];
    $cobrado = $resumen['cobrado'];
    $seguros = $resumen['seguros'];
    $resultado = $resumen['resultado'];
    $medicamentos = $resultado['medicamentos'];
    $honorarios = $resultado['honorarios'];

    $pendiente = \App\Domain\ValueObjects\Decimal::de($cobrado['pendiente']);
    $queda = \App\Domain\ValueObjects\Decimal::de($resultado['despues_de_honorarios']);
    $reparto = \App\Services\ResumenDeFacturacion::repartoDeLosIngresos($resultado);
    $dinero = $resumen['dinero'];
    $repartoDelDinero = \App\Services\ResumenDeFacturacion::repartoDeLoFacturado($resumen);

    $alPie = str_replace(['"', '\\', '<', '>', "\n", "\r"], '', 'Reporte del mes · '.$etiqueta);
    $conFacturas = $facturas !== null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Reporte del mes · {{ $etiqueta }}</title>
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

            /* La barra del reparto: los tres primeros tonos de la paleta validada. */
            --parte-medicamentos: #2a78d6;
            --parte-honorarios: #eb6834;
            --parte-queda: #1baf7a;

            /* Lo que entró y lo que falta: aqua, amarillo y magenta de la misma paleta. */
            --dinero-entro: #1baf7a;
            --dinero-seguros: #eda100;
            --dinero-pacientes: #e87ba4;
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

        /* Adentro del modal: sin barra, y sin barra horizontal (ver `honorarios.imprimir`). */
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

        /* ── Las cuatro cifras ── */
        .cifras { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid var(--raya); border-radius: 6px; background: var(--fondo); }
        .cifras > div { padding: 3mm 3.5mm; min-width: 0; }
        .cifras > div + div { border-left: 1px solid var(--raya); }
        .cifras .valor { display: block; font-size: 14px; font-weight: 700; color: var(--tinta); white-space: nowrap; }
        .cifras .debajo { display: block; margin-top: .5mm; font-size: 7.8px; color: var(--suave); }
        .cifras .principal { background: var(--marca); border-radius: 0 5px 5px 0; }
        .cifras .principal .rotulo, .cifras .principal .debajo { color: #cbd5e1; }
        .cifras .principal .valor { color: #fff; font-size: 16px; }

        /* ── Dos columnas ── */
        .dos { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-top: 5mm; align-items: start; }
        .seccion { margin-top: 5mm; }
        .dos .seccion { margin-top: 0; }

        .titulo-de-seccion {
            margin: 0 0 1.5mm; font-size: 10px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--tinta);
        }
        .titulo-de-seccion span { margin-left: 1.5mm; font-size: 7.8px; font-weight: 400; letter-spacing: .02em; text-transform: none; color: var(--suave); }

        /* ── Las tablas ── */
        .tabla { table-layout: fixed; }
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

        /* Una tabla de dos columnas, rótulo y monto, sin encabezado. */
        .lista { border-top: 1.5px solid var(--marca); }
        .lista td { padding: 1.5mm 2mm; border-bottom: 1px solid var(--raya-fina); font-size: 9px; }
        .lista tr.fuerte td { font-weight: 700; color: var(--tinta); }
        .lista tr.subtotal td { background: var(--fondo); font-weight: 700; color: var(--tinta); }
        .lista tr.final td { background: var(--marca); color: #fff; font-size: 10.5px; font-weight: 700; border-bottom: 0; }
        .lista tr.final .debajo { color: #cbd5e1; }

        .num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .numero { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 8.4px !important; }
        .resta::before { content: '− '; }
        .fuerte { font-weight: 600; color: var(--tinta); }
        .tenue { color: var(--suave); }
        .debajo { display: block; margin-top: .3mm; font-size: 7.6px; color: var(--suave); font-weight: 400; }
        .tachada td { color: var(--suave); }
        .tachada .num { text-decoration: line-through; }
        .marca-anulada {
            display: block; width: max-content; margin-top: .6mm; padding: 0 1.2mm; border-radius: 2px;
            font-size: 6.5px; font-weight: 700; letter-spacing: .08em; color: #b91c1c; background: #fee2e2;
            text-decoration: none;
        }

        /* ── La barra del reparto de los ingresos ── */
        .reparto { margin: 2mm 0 2.5mm; }
        .reparto-barra { display: flex; gap: 2px; height: 7mm; }
        .reparto-parte {
            display: flex; align-items: center; min-width: 0; padding: 0 1.5mm; border-radius: 1mm;
            font-size: 8px; font-weight: 700; color: #0b0b0b; white-space: nowrap; overflow: hidden;
        }
        .reparto-medicamentos { background: var(--parte-medicamentos); }
        .reparto-honorarios { background: var(--parte-honorarios); }
        .reparto-queda { background: var(--parte-queda); }
        .dinero-entro { background: var(--dinero-entro); }
        .dinero-seguros { background: var(--dinero-seguros); }
        .dinero-pacientes { background: var(--dinero-pacientes); }
        .tabla tbody tr.entro td { background: #ecfdf5; font-weight: 700; color: var(--tinta); border-left: 0; }
        .tabla tbody tr.entro td:first-child { box-shadow: inset 2.5px 0 0 var(--dinero-entro); }
        .lista tr.de-eso td { font-size: 8.6px; }
        .lista tr.de-eso td:first-child { padding-left: 6mm; }
        .punto { display: inline-block; width: 2.2mm; height: 2.2mm; margin-right: 1.2mm; border-radius: .5mm; vertical-align: -.2mm; }
        .reparto-leyenda { display: flex; flex-wrap: wrap; gap: 1mm 5mm; margin-top: 1.5mm; font-size: 8px; color: var(--suave); }
        .reparto-leyenda i { display: inline-block; width: 2.4mm; height: 2.4mm; margin-right: 1.2mm; border-radius: .6mm; vertical-align: -.3mm; }

        .aviso {
            margin: 3mm 0 0; padding: 2.2mm 3.5mm; border: 1px solid #f59e0b; border-radius: 6px;
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
            <span class="barra-titulo">Reporte del mes · {{ $etiqueta }}</span>
            <a href="{{ $otraVersion }}">{{ $conFacturas ? 'Solo el resumen' : 'Con las facturas' }}</a>
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
            'titulo'     => 'Facturación del período',
            'etiqueta'   => $etiqueta,
            'impresoEl'  => $impresoEl,
            'impresoPor' => $impresoPor,
        ])

        {{-- 1. Las cuatro cifras --}}
        <section class="cifras bloque">
            <div>
                <span class="rotulo">Facturado</span>
                <span class="valor">{{ lempiras($facturado['total']) }}</span>
                <span class="debajo">{{ $facturado['cuantas'] }} {{ $facturado['cuantas'] === 1 ? 'factura' : 'facturas' }}</span>
            </div>
            <div>
                <span class="rotulo">Entró</span>
                <span class="valor">{{ lempiras($dinero['entro']) }}</span>
                <span class="debajo">efectivo, tarjeta y transferencia</span>
            </div>
            <div>
                <span class="rotulo">Por cobrar a seguros</span>
                <span class="valor">{{ lempiras($dinero['por_cobrar_seguros']) }}</span>
                <span class="debajo">todavía no entra · {{ count($seguros) }} {{ count($seguros) === 1 ? 'seguro' : 'seguros' }}</span>
            </div>
            <div class="principal">
                <span class="rotulo">Queda</span>
                <span class="valor">{{ lempiras($resultado['despues_de_honorarios']) }}</span>
                <span class="debajo">{{ lempiras($dinero['queda_de_lo_que_entro']) }} de lo que ya entró</span>
            </div>
        </section>

        <div class="dos">
            {{-- 2. Lo facturado --}}
            <section class="seccion bloque">
                <h2 class="titulo-de-seccion">Lo facturado</h2>
                <table class="lista">
                    <colgroup><col><col style="width: 30mm"></colgroup>
                    <tr class="fuerte"><td>A cargo de los pacientes</td><td class="num">{{ lempiras($facturado['pacientes']) }}</td></tr>
                    <tr class="fuerte"><td>A crédito de los seguros</td><td class="num">{{ lempiras($facturado['seguros']) }}</td></tr>
                    <tr><td>Importe exento</td><td class="num">{{ lempiras($facturado['exento']) }}</td></tr>
                    <tr><td>Importe gravado</td><td class="num">{{ lempiras($facturado['gravado']) }}</td></tr>
                    <tr><td>ISV</td><td class="num">{{ lempiras($facturado['isv']) }}</td></tr>
                    <tr><td>Descuentos dados</td><td class="num">{{ lempiras($facturado['descuentos']) }}</td></tr>
                    @if ($anuladas['cuantas'] > 0)
                        <tr class="tachada">
                            <td>
                                Anuladas
                                <span class="debajo">{{ $anuladas['cuantas'] }} {{ $anuladas['cuantas'] === 1 ? 'factura' : 'facturas' }} · no suman</span>
                            </td>
                            <td class="num">{{ lempiras($anuladas['total']) }}</td>
                        </tr>
                    @endif
                    <tr class="subtotal"><td>Total facturado</td><td class="num">{{ lempiras($facturado['total']) }}</td></tr>
                </table>
            </section>

            {{-- 3. Lo que entró y lo que falta --}}
            <section class="seccion bloque">
                <h2 class="titulo-de-seccion">Lo que entró y lo que falta</h2>

                @if ($repartoDelDinero !== [])
                    <div class="reparto" aria-label="Lo facturado: lo que entró y lo que falta">
                        <div class="reparto-barra">
                            @foreach ($repartoDelDinero as $parte)
                                @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                    <div
                                        class="reparto-parte dinero-{{ $parte['clave'] }}"
                                        style="flex: {{ $parte['parte'] }} 1 0"
                                    >@if (\App\Domain\ValueObjects\Decimal::de($parte['parte'])->comparar('12') >= 0){{ $parte['parte'] }} %@endif</div>
                                @endunless
                            @endforeach
                        </div>
                        <div class="reparto-leyenda">
                            @foreach ($repartoDelDinero as $parte)
                                @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                    <span><i class="dinero-{{ $parte['clave'] }}"></i>{{ $parte['nombre'] }}</span>
                                @endunless
                            @endforeach
                        </div>
                    </div>
                @endif

                <table class="tabla">
                    <colgroup><col><col style="width: 14mm"><col style="width: 28mm"></colgroup>
                    <thead>
                        <tr>
                            <th>Forma de pago</th>
                            <th class="num">Recibos</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cobrado['formas'] as $forma)
                            <tr>
                                <td>
                                    {{ $forma['etiqueta'] }}
                                    @foreach ($forma['bancos'] as $banco)
                                        <span class="debajo">{{ $banco['banco'] }} · {{ lempiras($banco['monto']) }}</span>
                                    @endforeach
                                </td>
                                <td class="num">{{ $forma['recibos'] }}</td>
                                <td class="num">{{ lempiras($forma['monto']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="entro">
                            <td>Entró <span class="debajo">El dinero que sí llegó a caja y a los bancos</span></td>
                            <td class="num">{{ $cobrado['recibos'] }}</td>
                            <td class="num">{{ lempiras($dinero['entro']) }}</td>
                        </tr>
                        <tr>
                            <td colspan="2"><span class="fuerte">Por cobrar a los seguros</span> <span class="debajo">Todavía no entra</span></td>
                            <td class="num fuerte">{{ lempiras($dinero['por_cobrar_seguros']) }}</td>
                        </tr>
                        @unless ($pendiente->esCero())
                            <tr>
                                <td colspan="2">
                                    <span class="fuerte">{{ $pendiente->esNegativo() ? 'Cobrado de más a los pacientes' : 'Por cobrar a los pacientes' }}</span>
                                    <span class="debajo">Lo que les tocó menos lo que pagaron</span>
                                </td>
                                <td class="num fuerte">{{ lempiras($dinero['por_cobrar_pacientes']) }}</td>
                            </tr>
                        @endunless
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Facturado</td>
                            <td class="num">{{ lempiras($facturado['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        </div>

        {{-- 4. Lo que queda --}}
        <section class="seccion bloque">
            <h2 class="titulo-de-seccion">Lo que queda <span>después de medicamentos y honorarios de médicos</span></h2>

            @if ($reparto !== [])
                <div class="reparto" aria-label="A dónde se fueron los ingresos">
                    <div class="reparto-barra">
                        @foreach ($reparto as $parte)
                            @unless (\App\Domain\ValueObjects\Decimal::de($parte['monto'])->esCero())
                                <div
                                    class="reparto-parte reparto-{{ $parte['clave'] }}"
                                    style="flex: {{ $parte['parte'] }} 1 0"
                                    title="{{ $parte['nombre'] }} · {{ lempiras($parte['monto']) }} · {{ $parte['parte'] }} %"
                                >@if (\App\Domain\ValueObjects\Decimal::de($parte['parte'])->comparar('9') >= 0){{ $parte['parte'] }} %@endif</div>
                            @endunless
                        @endforeach
                    </div>
                    <div class="reparto-leyenda">
                        @foreach ($reparto as $parte)
                            <span><i class="reparto-{{ $parte['clave'] }}"></i>{{ $parte['nombre'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <table class="lista">
                <colgroup><col><col style="width: 34mm"></colgroup>
                <tr><td>Facturado</td><td class="num">{{ lempiras($facturado['total']) }}</td></tr>
                <tr>
                    <td>ISV <span class="debajo">Se cobra para el SAR, no es del hospital</span></td>
                    <td class="num resta">{{ lempiras($resultado['isv']) }}</td>
                </tr>
                <tr class="subtotal"><td>Ingresos</td><td class="num">{{ lempiras($resultado['ingresos']) }}</td></tr>
                <tr>
                    <td>
                        Costo de medicamentos y materiales
                        <span class="debajo">
                            {{ $medicamentos['renglones'] }} {{ $medicamentos['renglones'] === 1 ? 'renglón' : 'renglones' }}
                            · se vendieron en {{ lempiras($medicamentos['vendido']) }}
                        </span>
                    </td>
                    <td class="num resta">{{ lempiras($medicamentos['costo']) }}</td>
                </tr>
                <tr class="subtotal"><td>Después de medicamentos</td><td class="num">{{ lempiras($resultado['despues_de_medicamentos']) }}</td></tr>
                <tr>
                    <td>
                        Honorarios de médicos
                        <span class="debajo">
                            {{ $honorarios['renglones'] }} {{ $honorarios['renglones'] === 1 ? 'renglón' : 'renglones' }}
                            · completos al médico
                        </span>
                    </td>
                    <td class="num resta">{{ lempiras($honorarios['total']) }}</td>
                </tr>
                <tr class="final">
                    <td>
                        Después de honorarios
                        <span class="debajo">Todavía no resta los honorarios del personal ni salarios</span>
                    </td>
                    <td class="num">{{ lempiras($resultado['despues_de_honorarios']) }}</td>
                </tr>
                <tr class="de-eso">
                    <td>
                        <i class="punto dinero-entro"></i>De lo que ya entró
                        <span class="debajo">Lo que entró, menos ISV, medicamentos y honorarios</span>
                    </td>
                    <td class="num fuerte">{{ lempiras($dinero['queda_de_lo_que_entro']) }}</td>
                </tr>
                <tr class="de-eso">
                    <td>
                        <i class="punto dinero-seguros"></i>Por cobrar
                        <span class="debajo">Lo que todavía deben los seguros{{ $pendiente->esCero() ? '' : ' y los pacientes' }}</span>
                    </td>
                    <td class="num fuerte">{{ lempiras($dinero['queda_por_cobrar']) }}</td>
                </tr>
            </table>

            @if ($medicamentos['sin_costo'] > 0)
                <p class="aviso">
                    <b>{{ $medicamentos['sin_costo'] }} {{ $medicamentos['sin_costo'] === 1 ? 'renglón' : 'renglones' }} de medicamentos sin costo</b>
                    —se vendieron en {{ lempiras($medicamentos['vendido_sin_costo']) }}—. Entraron sin costo a bodega
                    y no restan nada: lo que queda de verdad es menos.
                </p>
            @endif
        </section>

        {{-- 5. Lo que quedó a crédito de cada seguro --}}
        <section class="seccion">
            <h2 class="titulo-de-seccion">A crédito de cada seguro <span>sin plazo fijo, se sigue desde la fecha de cada factura</span></h2>

            @if ($seguros === [])
                <p class="vacio">Ninguna factura del período quedó a crédito de un seguro.</p>
            @else
                <table class="tabla">
                    <colgroup>
                        <col>
                        <col style="width: 22mm">
                        <col style="width: 15mm">
                        <col style="width: 26mm">
                        <col style="width: 24mm">
                        <col style="width: 26mm">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Seguro</th>
                            <th>Plazo</th>
                            <th class="num">Facturas</th>
                            <th class="num">Facturado</th>
                            <th class="num">Copago</th>
                            <th class="num">A crédito</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($seguros as $seguro)
                            <tr @class(['par' => $loop->even])>
                                <td class="fuerte">{{ $seguro['nombre'] }}</td>
                                <td class="tenue">{{ $seguro['plazo'] }}</td>
                                <td class="num">{{ $seguro['facturas'] }}</td>
                                <td class="num">{{ lempiras($seguro['facturado']) }}</td>
                                <td class="num">{{ lempiras($seguro['copago']) }}</td>
                                <td class="num fuerte">{{ lempiras($seguro['a_credito']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">Total a crédito de los seguros</td>
                            <td class="num">{{ lempiras($facturado['seguros']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </section>

        {{-- 6. Las facturas, una por una --}}
        @if ($conFacturas)
            <section class="seccion">
                <h2 class="titulo-de-seccion">
                    Facturas del período
                    <span>{{ count($facturas) }} {{ count($facturas) === 1 ? 'factura' : 'facturas' }} · las anuladas, tachadas, no suman</span>
                </h2>

                @if ($facturas === [])
                    <p class="vacio">Sin facturas en el período.</p>
                @else
                    <table class="tabla">
                        <colgroup>
                            <col style="width: 30mm">
                            <col style="width: 17mm">
                            <col>
                            <col style="width: 26mm">
                            <col style="width: 20mm">
                            <col style="width: 20mm">
                            <col style="width: 20mm">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Factura</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Pagador</th>
                                <th class="num">Total</th>
                                <th class="num">Seguro</th>
                                <th class="num">Paciente</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($facturas as $factura)
                                <tr @class(['par' => $loop->even && ! $factura['anulada'], 'tachada' => $factura['anulada']])>
                                    <td class="numero">
                                        {{ $factura['numero'] }}
                                        @if ($factura['anulada'])
                                            <span class="marca-anulada">ANULADA</span>
                                        @endif
                                    </td>
                                    <td class="numero">{{ $factura['fecha'] }}</td>
                                    <td>
                                        {{ $factura['cliente'] }}
                                        @if ($factura['paciente'] !== null && $factura['paciente'] !== $factura['cliente'])
                                            <span class="debajo">Paciente: {{ $factura['paciente'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $factura['pagador'] ?? 'Particular' }}</td>
                                    <td class="num">{{ lempiras($factura['total']) }}</td>
                                    <td class="num">{{ $factura['seguro'] === '0.00' ? '—' : lempiras($factura['seguro']) }}</td>
                                    <td class="num">{{ lempiras($factura['paciente_paga']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">Total de las {{ $facturado['cuantas'] }} emitidas</td>
                                <td class="num">{{ lempiras($facturado['total']) }}</td>
                                <td class="num">{{ lempiras($facturado['seguros']) }}</td>
                                <td class="num">{{ lempiras($facturado['pacientes']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </section>
        @endif

        <div class="cierre">
            <p class="nota">
                Todo sale de las facturas emitidas en el período, por la fecha de la factura; las cuentas abiertas
                no están. Lo que entró son los recibos de esas cuentas, del día que se hayan recibido; lo de los
                seguros queda por cobrar hasta que paguen. El costo de los medicamentos es el que quedó guardado en
                cada renglón al cargarse.
                @unless ($conFacturas)
                    Las facturas una por una salen con «Con las facturas».
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
