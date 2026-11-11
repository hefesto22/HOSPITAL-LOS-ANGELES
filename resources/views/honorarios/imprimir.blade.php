{{--
    Los honorarios del período, para papel (Mauricio, 11-nov-2026).

    Dos reportes con la misma hoja:

      · De UN médico: su hoja, con lo que le toca, el reparto por pagador,
        renglón por renglón y la firma de «Recibí conforme».
      · General: todos los médicos de la A a la Z con cuántos honorarios
        y cuánto, y el gran total. Con «el detalle de cada médico», después
        del resumen sale la hoja de cada uno, cada una en su propia página,
        para entregársela.

    Se ve en pantalla como hojas sobre un fondo gris y se imprime con
    Ctrl+P. Nada se arma en el servidor (§13.4).

    Al pie de cada hoja, qué reporte es y «Página X de Y», con las cajas de
    margen de `@page` (Chrome 131+), como la factura: si las hojas se
    separan, se vuelven a juntar.

    ⚠️ El total del pie de la tabla NO se repite en cada hoja: un `tfoot`
    se imprime al final de cada página por omisión, y un «Total» a mitad
    de la lista se leería como el total del médico.
--}}
@php
    $etiqueta = $reporte['etiqueta'];
    $alPie = str_replace(['"', '\\', '<', '>', "\n", "\r"], '', 'Honorarios médicos · '.$etiqueta);
    $comun = [
        'sede'       => $sede,
        'logo'       => $sede?->logoIncrustado(),
        'telefono'   => $sede?->telefonoImpreso(),
        'etiqueta'   => $etiqueta,
        'impresoEl'  => $impresoEl,
        'impresoPor' => $impresoPor,
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Honorarios · {{ $deUnaHoja && isset($reporte['hojas'][0]) ? $reporte['hojas'][0]['nombre'].' · ' : '' }}{{ $etiqueta }}</title>
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

        /* En pantalla, cada página es una hoja; al imprimir, el papel. */
        .pagina {
            width: 216mm; min-height: 279mm; margin: 0 auto 10mm; padding: 12mm;
            background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / .12);
        }

        @media print {
            body { background: #fff; }
            .no-imprime { display: none !important; }
            .pagina { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .pagina + .pagina { break-before: page; }
            .tabla tr { break-inside: avoid; }
            .cierre { break-inside: avoid; }
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }

        /* ── El encabezado: el hospital a la izquierda, el reporte a la derecha ── */
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

        /* ── Las cifras del período (reporte general) ── */
        .cifras { display: grid; grid-template-columns: 1.6fr 1fr 1fr; border: 1px solid var(--raya); border-radius: 6px; background: var(--fondo); }
        .cifras > div { padding: 3mm 4mm; }
        .cifras > div + div { border-left: 1px solid var(--raya); }
        .cifras .valor { font-size: 15px; font-weight: 700; color: var(--tinta); font-variant-numeric: tabular-nums; }
        .cifras .principal .valor { font-size: 19px; color: var(--marca); }

        /* ── El médico (su hoja) ── */
        .medico {
            display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm;
            padding: 3.5mm 4mm; border: 1px solid var(--raya); border-radius: 6px;
        }
        .medico-nombre { font-size: 14px; font-weight: 700; color: var(--tinta); letter-spacing: .01em; }
        .medico-datos { display: flex; flex-wrap: wrap; gap: .5mm 5mm; margin-top: 1mm; font-size: 9px; color: var(--suave); }
        .medico-datos b { font-weight: 600; color: var(--texto); }
        .medico-total { flex: none; text-align: right; }
        .medico-monto {
            margin-top: .5mm; padding: 1.5mm 3.5mm; border-radius: 5px; background: var(--marca); color: #fff;
            font-size: 17px; font-weight: 700; letter-spacing: .01em; font-variant-numeric: tabular-nums;
        }
        .medico-cuantos { margin-top: 1mm; font-size: 8.5px; color: var(--suave); }

        /* ── Las tablas ── */
        .tabla { margin-top: 4mm; table-layout: fixed; }
        .tabla thead th {
            padding: 2mm 2mm; text-align: left; font-size: 7.2px; font-weight: 700; letter-spacing: .12em;
            text-transform: uppercase; color: var(--marca); background: var(--fondo);
            border-top: 1.5px solid var(--marca); border-bottom: 1px solid var(--raya);
        }
        .tabla tbody td { padding: 1.4mm 2mm; border-bottom: 1px solid var(--raya-fina); font-size: 9px; overflow-wrap: anywhere; }
        .tabla tbody tr.par td { background: #f8fafc; }
        .tabla tfoot { display: table-row-group; }
        .tabla tfoot td {
            padding: 2.2mm 2mm; font-size: 9.5px; font-weight: 700; color: var(--tinta);
            border-top: 1.5px solid var(--marca); background: var(--fondo);
        }
        .pagadores { width: 62%; }
        .num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .num-izq { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fuerte { font-weight: 600; color: var(--tinta); }
        .tenue { color: var(--suave); }
        .debajo { display: block; margin-top: .3mm; font-size: 7.8px; color: var(--suave); }

        .grupo {
            margin: 6mm 0 0; font-size: 10.5px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--tinta);
        }
        .grupo span { margin-left: 2mm; font-size: 8px; font-weight: 400; letter-spacing: .04em; color: var(--suave); }
        .grupo + .tabla { margin-top: 2mm; }
        .sin-medico {
            margin: 5mm 0 0; padding: 2.5mm 3.5mm; border: 1px solid #f59e0b; border-radius: 6px;
            background: #fffbeb; font-size: 8.8px; color: #78350f;
        }
        .gran-total {
            display: flex; justify-content: space-between; align-items: center; margin-top: 5mm;
            padding: 2.6mm 3.5mm; border-radius: 6px; background: var(--marca); color: #fff;
        }
        .gran-total span { font-size: 8px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
        .gran-total strong { font-size: 14px; letter-spacing: .02em; font-variant-numeric: tabular-nums; }

        .vacio {
            margin-top: 6mm; padding: 6mm; border: 1px dashed var(--raya); border-radius: 6px;
            text-align: center; font-size: 10px; color: var(--suave);
        }

        /* ── El cierre: la nota y las firmas ── */
        .cierre { margin-top: 6mm; }
        .nota { margin: 0; padding: 2.2mm 3.5mm; border-left: 3px solid var(--marca-viva); background: var(--fondo); font-size: 8.4px; color: var(--suave); }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 14mm; margin-top: 4mm; }
        .firma { padding-top: 16mm; border-bottom: 1px solid var(--texto); }
        .firma-rotulo { margin-top: 1.2mm; text-align: center; font-size: 8px; color: var(--suave); }
    </style>
</head>
<body>
    <div class="barra no-imprime">
        <span class="barra-titulo">
            {{ $titulo }} · {{ $etiqueta }}
        </span>

        @if ($otraVersion !== null)
            <a href="{{ $otraVersion }}">
                {{ $conDetalle ? 'Solo el resumen' : 'Con la hoja de cada uno' }}
            </a>
        @endif

        <a href="{{ $volver }}">Volver a la pantalla</a>
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
    </div>

    @unless ($deUnaHoja)
        <section class="pagina">
            @include('honorarios.partials.membrete', $comun + ['titulo' => 'Reporte general'])

            <section class="cifras">
                <div class="principal">
                    <span class="rotulo">Total facturado · {{ $reporte['cuantos'] }} {{ $reporte['cuantos'] === 1 ? 'honorario' : 'honorarios' }}</span>
                    <span class="valor">{{ $reporte['total'] }}</span>
                </div>
                <div>
                    <span class="rotulo">{{ count($reporte['resumen']) }} {{ count($reporte['resumen']) === 1 ? 'médico' : 'médicos' }}</span>
                    <span class="valor">{{ $reporte['totalMedicos'] }}</span>
                </div>
                <div>
                    <span class="rotulo">Enfermería y personal</span>
                    <span class="valor">{{ $reporte['totalPersonal'] }}</span>
                </div>
            </section>

            @if ($reporte['resumen'] === [] && $reporte['personal'] === [] && $reporte['sinMedico'] === null)
                <p class="vacio">Sin honorarios facturados en el período.</p>
            @endif

            @if ($reporte['resumen'] !== [])
                <h2 class="grupo">Médicos</h2>

                <table class="tabla">
                    <colgroup>
                        <col style="width: 9mm">
                        <col>
                        <col style="width: 48mm">
                        <col style="width: 22mm">
                        <col style="width: 30mm">
                        <col style="width: 15mm">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>Médico</th>
                            <th>Especialidad</th>
                            <th class="num">Honorarios</th>
                            <th class="num">Importe</th>
                            <th class="num">Parte</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reporte['resumen'] as $fila)
                            <tr @class(['par' => $loop->even])>
                                <td class="num tenue">{{ $loop->iteration }}</td>
                                <td class="fuerte">{{ $fila['nombre'] }}</td>
                                <td>{{ $fila['especialidad'] !== '' ? $fila['especialidad'] : '—' }}</td>
                                <td class="num">{{ $fila['cuantos'] }}</td>
                                <td class="num fuerte">{{ $fila['total'] }}</td>
                                <td class="num tenue">{{ $fila['parte'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Total de los médicos</td>
                            <td class="num">{{ $reporte['cuantosMedicos'] }}</td>
                            <td class="num">{{ $reporte['totalMedicos'] }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            @if ($reporte['personal'] !== [])
                <h2 class="grupo">Enfermería y personal <span>por turno o área</span></h2>

                <table class="tabla">
                    <colgroup>
                        <col style="width: 9mm">
                        <col>
                        <col style="width: 22mm">
                        <col style="width: 30mm">
                        <col style="width: 15mm">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>Turno o área</th>
                            <th class="num">Honorarios</th>
                            <th class="num">Importe</th>
                            <th class="num">Parte</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reporte['personal'] as $fila)
                            <tr @class(['par' => $loop->even])>
                                <td class="num tenue">{{ $loop->iteration }}</td>
                                <td class="fuerte">{{ $fila['nombre'] }}</td>
                                <td class="num">{{ $fila['cuantos'] }}</td>
                                <td class="num fuerte">{{ $fila['total'] }}</td>
                                <td class="num tenue">{{ $fila['parte'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total de enfermería y personal</td>
                            <td class="num">{{ $reporte['cuantosPersonal'] }}</td>
                            <td class="num">{{ $reporte['totalPersonal'] }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            @if ($reporte['sinMedico'] !== null)
                <p class="sin-medico">
                    <b>Honorarios de médico sin médico asignado:</b>
                    {{ $reporte['sinMedico']['cuantos'] }}
                    {{ $reporte['sinMedico']['cuantos'] === 1 ? 'honorario' : 'honorarios' }} ·
                    <b>{{ $reporte['sinMedico']['total'] }}</b>.
                    Se cobraron sin elegir al médico y no entran en la liquidación de nadie; están en el total para
                    que cuadre.
                </p>
            @endif

            @if ($reporte['resumen'] !== [] || $reporte['personal'] !== [] || $reporte['sinMedico'] !== null)
                <div class="gran-total">
                    <span>Total facturado en honorarios · {{ $reporte['cuantos'] }} {{ $reporte['cuantos'] === 1 ? 'honorario' : 'honorarios' }}</span>
                    <strong>{{ $reporte['total'] }}</strong>
                </div>
            @endif

            <div class="cierre">
                <p class="nota">
                    Son los honorarios de las facturas emitidas en el período, por la fecha de la factura. Una
                    factura anulada no cuenta. Es lo facturado: no descuenta nada de lo que el hospital retenga
                    ni dice qué ya pagó el seguro.
                    @unless ($conDetalle)
                        La hoja de cada médico y de cada turno sale con «Con la hoja de cada uno», arriba.
                    @endunless
                </p>
            </div>
        </section>
    @endunless

    @foreach ($reporte['hojas'] as $hoja)
        <section class="pagina">
            @include('honorarios.partials.hoja', $comun + ['hoja' => $hoja, 'reporte' => $reporte])
        </section>
    @endforeach
</body>
</html>
