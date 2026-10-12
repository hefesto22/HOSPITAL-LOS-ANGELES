{{--
    La factura impresa.

    ─────────────────────────────────────────────────────────────────────
    🔴 ELEGANTE, PERO CON TODO LO QUE EL SAR PIDE (12-oct-2026)
    ─────────────────────────────────────────────────────────────────────

    Hasta acá era el formulario viejo del hospital calcado: recuadros
    negros y una cuadrícula que se leía como una planilla. Mauricio: «se
    sigue viendo feo, que sea más elegante, empresarial y profesional».

    Se rediseñó la presentación, no el contenido. Siguen TODOS los datos
    del Acuerdo 481-2017: R.T.N. del emisor, C.A.I., número, fecha de
    emisión, rango autorizado y fecha límite de emisión; cliente con su
    documento; los tres números de exoneración (orden de compra exenta,
    constancia de registro exonerado, registro SAG); el detalle; las
    casillas separadas de exonerado, exento, gravado 15 % y 18 %, ISV 15 %
    y 18 %; el total, el total en letras, original y copia, y la leyenda.

    Lo fiscal va junto en una franja (C.A.I., rango y fecha límite), las
    partes en dos tarjetas —quien paga y el paciente—, y el TOTAL A PAGAR
    en una banda de color, que es lo que se busca con el dedo.

    🔴 EL ESPACIO DEL SELLO DE PAGADO (Mauricio, 12-oct-2026): «cada
    factura debe tener un espacio para firmar o colocar sello para
    marcarla como pagada». Va abajo a la derecha, junto a las firmas.

    ─────────────────────────────────────────────────────────────────────
    🔴 TODO SALE DE LA FILA DE LA FACTURA
    ─────────────────────────────────────────────────────────────────────

    Ni el cliente, ni el C.A.I., ni los montos, ni el código del producto
    se leen por relación: están congelados en `facturas` y
    `factura_lineas`. Una reimpresión de hace ocho meses sale idéntica
    aunque el catálogo y los precios hayan cambiado diez veces. Lo único
    que se lee del sistema son los datos del EMISOR.
--}}
@php($lineas = $factura->detalle)
@php($leyendas = config('sihla.facturacion.leyendas', []))
@php($logo = $sede->logoIncrustado())
@php($telefono = $sede->telefonoImpreso())
@php($descuentos = (float) $factura->descuento_legal + (float) $factura->descuento_comercial)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $factura->numero }}</title>
    <style>
        @page { size: letter; margin: 11mm 12mm; }
        * { box-sizing: border-box; }

        /*
         * Los fondos se imprimen aunque el diálogo tenga apagado
         * «Gráficos en segundo plano»: sin esto la banda del total y la
         * franja fiscal salen en blanco.
         */
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /*
         * Los colores salen del logo: el azul profundo para lo que
         * manda y el celeste para el acento. En una impresora de blanco
         * y negro el azul sale gris oscuro y la jerarquía se mantiene.
         */
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
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 9.5px; line-height: 1.35;
        }

        /*
         * Incrustada en el modal la hoja arranca pegada arriba: el
         * margen del visor lo pone el modal, y duplicarlo deja una
         * franja gris que parece un error de carga.
         */
        body.incrustada { background: #fff; }
        body.incrustada .hoja { margin: 0 auto; }

        .hoja { position: relative; width: 216mm; min-height: 279mm; margin: 0 auto; padding: 11mm 12mm; background: #fff; }

        .barra { max-width: 216mm; margin: 8px auto; display: flex; gap: 8px; justify-content: flex-end; }
        .barra button, .barra a {
            padding: 6px 14px; font-size: 12px; border-radius: 6px; cursor: pointer;
            border: 1px solid #d4d4d8; background: #fff; color: #111; text-decoration: none;
        }

        @media print {
            body { background: #fff; }
            .hoja { width: auto; min-height: 0; margin: 0; padding: 0; }
            .no-imprime { display: none !important; }

            /*
             * LA SEGUNDA HOJA CONTINÚA, SIN REPETIR NADA. Una cirugía
             * desglosada pasa de una hoja y el navegador repetía el
             * encabezado de la tabla: la segunda parecía otro documento.
             * Decisión de Mauricio (2-sep-2026): continúa sin encabezado.
             */
            .detalle thead { display: table-row-group; }

            /*
             * Lo que no se parte a la mitad: un renglón cortado entre dos
             * hojas, o los totales separados de su detalle.
             */
            .detalle tr, .cierre, .firmas, .pie { break-inside: avoid; }

            /* La marca de anulada, en todas las hojas. */
            .anulada-marca { position: fixed; }
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }

        /*
         * ─────────────────────────────────────────────────────────────
         * EL ENCABEZADO: EL MEMBRETE A LA IZQUIERDA, EL DOCUMENTO A LA DERECHA
         * ─────────────────────────────────────────────────────────────
         *
         * 🔴 EL NOMBRE SE IMPRIME AUNQUE HAYA LOGO. La razón social del
         * emisor es obligatoria en el papel; un logotipo no la sustituye
         * ante el SAR ni aunque la lleve dibujada adentro.
         *
         * ⚠️ FLEX Y NO UNA TABLA. Un logo SVG —que trae `viewBox` y no
         * `width`— sale de 0×0 adentro de una celda de tabla: el
         * navegador calcula el ancho mínimo antes de resolver la
         * proporción, y la factura salía SIN logo y sin error a la vista.
         */
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 10mm; }
        .membrete { min-width: 0; }
        .logo { display: block; max-height: 19mm; max-width: 78mm; margin-bottom: 3mm; }
        .membrete p { margin: 1px 0 0; color: var(--suave); font-size: 8.8px; }
        .membrete .razon { margin: 0; font-size: 11.5px; font-weight: 700; color: var(--tinta); letter-spacing: .02em; }
        .membrete.sin-logo .razon { font-size: 17px; }
        .membrete .rtn { color: var(--texto); font-weight: 600; }

        .documento { flex: none; min-width: 64mm; text-align: right; }
        .documento .tipo { font-size: 24px; font-weight: 300; letter-spacing: .32em; margin-right: -.32em; color: var(--marca); line-height: 1; }
        .documento .numero { margin-top: 3mm; font-size: 8px; letter-spacing: .14em; text-transform: uppercase; color: var(--suave); }
        .documento .numero strong {
            display: block; margin-top: 1px; font-size: 13px; letter-spacing: .03em; color: var(--tinta);
            font-variant-numeric: tabular-nums;
        }
        .fechas { width: auto; margin: 3mm 0 0 auto; }
        .fechas td { padding: 1px 0 1px 6mm; text-align: right; font-size: 9px; }
        .fechas td:first-child { padding-top: 2px; font-size: 7.5px; letter-spacing: .08em; text-transform: uppercase; color: var(--suave); }
        .fechas td:last-child { font-weight: 600; color: var(--tinta); font-variant-numeric: tabular-nums; }

        .franja {
            height: 3px; margin: 5mm 0 4mm; border-radius: 2px;
            background: linear-gradient(90deg, var(--marca) 0 70%, var(--marca-viva) 70% 100%);
        }

        .anulada {
            margin: 0 0 4mm; padding: 2.2mm 3.5mm; border: 1.5px solid #b91c1c; border-radius: 6px;
            color: #b91c1c; font-weight: 700; letter-spacing: .08em; text-align: center;
        }
        .anulada-marca { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .anulada-marca span { transform: rotate(-28deg); font-size: 120px; font-weight: 800; letter-spacing: .1em; color: rgba(185, 28, 28, .1); }

        .rotulo {
            display: block; margin-bottom: 1px; font-size: 7px; font-weight: 700; letter-spacing: .14em;
            text-transform: uppercase; color: var(--suave);
        }
        .valor { font-size: 9.5px; font-weight: 600; color: var(--tinta); font-variant-numeric: tabular-nums; }

        /* Lo fiscal, junto: C.A.I., rango autorizado y fecha límite. */
        .fiscal { display: grid; grid-template-columns: 1.35fr 1.35fr .8fr; border: 1px solid var(--raya); border-radius: 6px; background: var(--fondo); }
        .fiscal > div { padding: 2.2mm 3.5mm; }
        .fiscal > div + div { border-left: 1px solid var(--raya); }
        .fiscal .cai .valor { letter-spacing: .03em; }

        /*
         * Las partes: quien paga y quien se atendió (Mauricio,
         * 11-oct-2026). Con seguro el cliente es la aseguradora y el
         * paciente está siempre en su tarjeta, con su identidad y su
         * póliza; el «Código de Cliente» es su expediente.
         */
        .partes { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; margin-top: 4mm; }
        .parte { padding: 3mm 3.5mm; border: 1px solid var(--raya); border-radius: 6px; }
        .parte .titulo {
            margin-bottom: 1.5mm; padding-bottom: 1.2mm; border-bottom: 1px solid var(--raya-fina);
            font-size: 7px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: var(--marca);
        }
        .parte .nombre { font-size: 11px; font-weight: 700; color: var(--tinta); }
        .parte .linea { display: flex; flex-wrap: wrap; gap: .5mm 5mm; margin-top: 1.2mm; font-size: 9px; color: var(--suave); }
        .parte .linea b { font-weight: 600; color: var(--texto); }

        .meta { display: grid; grid-template-columns: repeat(5, 1fr); margin-top: 3mm; border-top: 1px solid var(--raya-fina); border-bottom: 1px solid var(--raya-fina); }
        .meta > div { padding: 1.8mm 2.5mm 1.8mm 0; }
        .meta .valor { font-size: 9px; font-weight: 500; }

        /* El detalle. */
        .detalle { margin-top: 4mm; }
        .detalle thead th {
            padding: 2.2mm 2mm; text-align: left; font-size: 7.2px; font-weight: 700; letter-spacing: .12em;
            text-transform: uppercase; color: var(--marca); background: var(--fondo);
            border-top: 1.5px solid var(--marca); border-bottom: 1px solid var(--raya);
        }
        .detalle tbody td { padding: 2mm; border-bottom: 1px solid var(--raya-fina); font-size: 9.2px; }
        .detalle tbody tr:last-child td { border-bottom: 1px solid var(--raya); }
        .detalle .codigo { white-space: nowrap; font-size: 8.5px; color: var(--suave); }
        .detalle td.total { font-weight: 600; color: var(--tinta); }

        /*
         * El renglón que nombra una cirugía desglosada: en negrita y SIN
         * columnas numéricas. Los que cobran son los de abajo, y un
         * «0.00» al lado del procedimiento se leería como una línea que
         * falló. Lo garantiza el CHECK `factura_lineas_encabezado_no_cobra`.
         */
        .detalle tbody tr.titulo td { font-weight: 700; color: var(--tinta); background: #f8fafc; }

        .num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }

        /*
         * El cierre: en letras y comentarios a la izquierda, las casillas
         * a la derecha. Las casillas van SEPARADAS porque el SAR las pide
         * separadas: el impuesto en la casilla equivocada es un hallazgo
         * con multa. El subtotal y los descuentos van con su nombre, y no
         * sumados al pie de cada columna: un número sin rótulo era lo que
         * confundía.
         */
        .cierre { display: grid; grid-template-columns: 1fr 74mm; gap: 6mm; align-items: start; margin-top: 4mm; }
        .letras { padding: 2.5mm 3.5mm; border-left: 3px solid var(--marca-viva); border-radius: 0 6px 6px 0; background: var(--fondo); }
        .comentarios { min-height: 16mm; margin-top: 3mm; padding: 2.5mm 3.5mm; border: 1px solid var(--raya); border-radius: 6px; }
        .comentarios p { margin: 0; white-space: pre-line; }

        .totales td { padding: 1.3mm 0; border-bottom: 1px solid var(--raya-fina); font-size: 9px; color: var(--suave); }
        .totales td.num { font-weight: 500; color: var(--tinta); }
        .totales tr.base td { font-weight: 600; color: var(--tinta); }
        .pagar {
            display: flex; justify-content: space-between; align-items: center; margin-top: 2mm; padding: 2.6mm 3.5mm;
            border-radius: 6px; background: var(--marca); color: #fff;
        }
        .pagar span { font-size: 8px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
        .pagar strong { font-size: 14px; letter-spacing: .02em; font-variant-numeric: tabular-nums; }

        /* Las firmas y el sello de pagado. */
        .firmas { display: grid; grid-template-columns: 1fr 1fr 58mm; gap: 8mm; align-items: end; margin-top: 8mm; }
        .firma { padding-top: 13mm; border-bottom: 1px solid var(--texto); }
        .firma-rotulo { margin-top: 1.2mm; text-align: center; font-size: 8px; color: var(--suave); }
        .sello {
            display: flex; flex-direction: column; justify-content: space-between; height: 30mm; padding: 2mm 3mm;
            border: 1.5px dashed #94a3b8; border-radius: 8px;
        }
        .sello .rotulo { text-align: center; color: var(--marca); }
        .sello .renglon { display: flex; gap: 2mm; font-size: 7.5px; color: var(--suave); }
        .sello .renglon i { flex: 1; border-bottom: 1px solid var(--raya); }

        .pie { margin-top: 6mm; padding-top: 3mm; border-top: 1px solid var(--raya); text-align: center; }
        .pie .copias { font-size: 8px; letter-spacing: .04em; color: var(--suave); }
        .pie .exijala { margin-top: 1.5mm; font-size: 9.5px; font-weight: 700; letter-spacing: .12em; color: var(--marca); }
    </style>
</head>
<body class="{{ ($incrustada ?? false) ? 'incrustada' : '' }}">
    @unless ($incrustada ?? false)
        <div class="barra no-imprime">
            <button type="button" onclick="window.print()">Imprimir</button>
            <a href="{{ url()->previous() }}">Volver</a>
        </div>
    @endunless

    <div class="hoja">
        @if (! $factura->estaViva())
            <div class="anulada-marca" aria-hidden="true"><span>ANULADA</span></div>
        @endif

        {{-- ── Encabezado ───────────────────────────────────────────── --}}
        <header class="encabezado">
            <div @class(['membrete', 'sin-logo' => ! $logo])>
                {{--
                    🔴 EL LOGO VA INCRUSTADO, NO ENLAZADO. La factura se
                    imprime desde un iframe y el navegador abre el diálogo
                    apenas carga: una imagen que todavía se está
                    descargando sale como un hueco blanco. En base64 ya
                    está ahí. Sin logo, el membrete sale con el nombre más
                    grande — una factura no se cae por una imagen.
                --}}
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $sede->nombre }}" class="logo">
                @endif

                <p class="razon">{{ mb_strtoupper($sede->razon_social) }}</p>
                @if ($sede->direccion)
                    <p>{{ $sede->direccion }}</p>
                @endif
                @if ($telefono || $sede->email)
                    <p>
                        @if ($telefono)
                            Tel. {{ $telefono }}
                        @endif
                        @if ($telefono && $sede->email)
                            &nbsp;·&nbsp;
                        @endif
                        @if ($sede->email)
                            {{ $sede->email }}
                        @endif
                    </p>
                @endif
                <p class="rtn">R.T.N. {{ $sede->rtn ?? '—' }}</p>
            </div>

            <div class="documento">
                <div class="tipo">{{ mb_strtoupper($factura->tipo->etiqueta()) }}</div>
                <div class="numero">Número<strong>{{ $factura->numero }}</strong></div>
                <table class="fechas">
                    <tr>
                        <td>Emisión</td>
                        <td>{{ $factura->emitida_en->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td>Vencimiento</td>
                        <td>{{ $factura->vence_el?->format('d/m/Y') ?? $factura->emitida_en->format('d/m/Y') }}</td>
                    </tr>
                </table>
            </div>
        </header>

        <div class="franja"></div>

        @if (! $factura->estaViva())
            <p class="anulada">ANULADA — {{ $factura->motivo_anulacion }}</p>
        @endif

        {{--
            🔴 SIN EL C.A.I. IMPRESO EL DOCUMENTO NO ES UNA FACTURA: es un
            papel sin valor fiscal. El rango autorizado va al lado: es lo
            que permite verificar de un vistazo que este número cae
            adentro de lo que el SAR autorizó.
        --}}
        <section class="fiscal">
            <div class="cai">
                <span class="rotulo">C.A.I.</span>
                <span class="valor">{{ $factura->cai }}</span>
            </div>
            <div>
                <span class="rotulo">Rango autorizado</span>
                <span class="valor">{{ $rango }}</span>
            </div>
            <div>
                <span class="rotulo">Fecha límite de emisión</span>
                <span class="valor">{{ $factura->fecha_limite_emision->format('d/m/Y') }}</span>
            </div>
        </section>

        {{-- ── Quien paga y quien se atendió ────────────────────────── --}}
        <section class="partes">
            <div class="parte">
                <div class="titulo">Facturar a</div>
                <div class="nombre">{{ $factura->cliente_nombre }}</div>
                @if (filled($factura->cliente_documento))
                    <div class="linea">
                        <span>{{ $factura->rotuloDelDocumento() }} <b>{{ $factura->cliente_documento }}</b></span>
                    </div>
                @endif
                @if (filled($factura->cliente_direccion) || filled($factura->cliente_telefono))
                    <div class="linea">
                        <span>{{ $factura->cliente_direccion }}{{ filled($factura->cliente_direccion) && filled($factura->cliente_telefono) ? ' · ' : '' }}{{ $factura->cliente_telefono }}</span>
                    </div>
                @endif
            </div>

            <div class="parte">
                <div class="titulo">Paciente</div>
                {{--
                    Las facturas emitidas antes del 11-oct-2026 no tienen
                    paciente congelado —lo llevaban dentro del nombre del
                    cliente— y salen con la raya, como se emitieron.
                --}}
                <div class="nombre">{{ filled($factura->paciente_nombre) ? $factura->paciente_nombre : '—' }}</div>
                <div class="linea">
                    @if (filled($factura->paciente_documento))
                        <span>{{ $factura->rotuloDelDocumentoDelPaciente() }} <b>{{ $factura->documentoDelPacienteImpreso() }}</b></span>
                    @endif
                    @if (filled($factura->paciente_poliza))
                        <span>Póliza <b>{{ $factura->paciente_poliza }}</b></span>
                    @endif
                    <span>Código de cliente <b>{{ filled($factura->cliente_codigo) ? $factura->cliente_codigo : '—' }}</b></span>
                </div>
            </div>
        </section>

        <section class="meta">
            <div>
                <span class="rotulo">Facturador</span>
                <span class="valor">{{ filled($factura->facturador) ? $factura->facturador : '—' }}</span>
            </div>
            <div>
                <span class="rotulo">Términos</span>
                <span class="valor">{{ filled($factura->terminos) ? $factura->terminos : '—' }}</span>
            </div>
            <div>
                <span class="rotulo">No. orden compra exenta</span>
                <span class="valor">{{ filled($factura->cliente_orden_exenta) ? $factura->cliente_orden_exenta : '—' }}</span>
            </div>
            <div>
                <span class="rotulo">No. constancia reg. exonerado</span>
                <span class="valor">{{ filled($factura->cliente_constancia_exonerado) ? $factura->cliente_constancia_exonerado : '—' }}</span>
            </div>
            <div>
                <span class="rotulo">No. registro SAG</span>
                <span class="valor">{{ filled($factura->cliente_registro_sag) ? $factura->cliente_registro_sag : '—' }}</span>
            </div>
        </section>

        {{-- ── El detalle ───────────────────────────────────────────── --}}
        <table class="detalle">
            <thead>
                <tr>
                    <th style="width: 19mm">Código</th>
                    <th>Descripción</th>
                    <th class="num" style="width: 15mm">Cant.</th>
                    <th class="num" style="width: 21mm">Precio unit.</th>
                    <th class="num" style="width: 22mm">Descuento</th>
                    <th class="num" style="width: 22mm">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lineas as $linea)
                    @if ($linea->encabezado)
                        <tr class="titulo">
                            <td class="codigo">{{ $linea->codigo }}</td>
                            <td colspan="5">{{ $linea->descripcion }}</td>
                        </tr>
                    @else
                        <tr>
                            <td class="codigo">{{ $linea->codigo }}</td>
                            <td>{{ $linea->descripcion }}</td>
                            <td class="num">{{ number_format((float) $linea->cantidad, 2) }}</td>
                            <td class="num">{{ number_format((float) $linea->precio_unitario, 2) }}</td>
                            <td class="num">{{ number_format((float) $linea->descuento()->redondeado(2), 2) }}</td>
                            <td class="num total">{{ number_format((float) $linea->total, 2) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        {{-- ── En letras, comentarios y las casillas ────────────────── --}}
        <section class="cierre">
            <div>
                {{-- El total en letras: la defensa contra el dígito alterado. --}}
                <div class="letras">
                    <span class="rotulo">Son</span>
                    <span class="valor">{{ $enLetras }}</span>
                </div>

                <div class="comentarios">
                    <span class="rotulo">Comentarios</span>
                    <p>{{ $factura->comentarios }}</p>
                </div>
            </div>

            <div>
                <table class="totales">
                    <tr class="base">
                        <td>Subtotal</td>
                        <td class="num">{{ number_format((float) $factura->bruto, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Descuentos y rebajas</td>
                        <td class="num">{{ $descuentos > 0 ? '− ' : '' }}{{ number_format($descuentos, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe exonerado</td>
                        <td class="num">{{ number_format((float) $factura->exonerado, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe exento</td>
                        <td class="num">{{ number_format((float) $factura->exento, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe gravado 15 %</td>
                        <td class="num">{{ number_format((float) $factura->gravado_15, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe gravado 18 %</td>
                        <td class="num">{{ number_format((float) $factura->gravado_18, 2) }}</td>
                    </tr>
                    <tr>
                        <td>ISV 15 %</td>
                        <td class="num">{{ number_format((float) $factura->isv_15, 2) }}</td>
                    </tr>
                    <tr>
                        <td>ISV 18 %</td>
                        <td class="num">{{ number_format((float) $factura->isv_18, 2) }}</td>
                    </tr>
                </table>

                <div class="pagar">
                    <span>Total a pagar</span>
                    <strong>L {{ number_format((float) $factura->total, 2) }}</strong>
                </div>
            </div>
        </section>

        {{-- ── Firmas y el sello de pagado ──────────────────────────── --}}
        <section class="firmas">
            <div>
                <div class="firma"></div>
                <div class="firma-rotulo">Firma por {{ mb_strtoupper($sede->razon_social) }}</div>
            </div>
            <div>
                <div class="firma"></div>
                <div class="firma-rotulo">Recibido de conformidad</div>
            </div>
            <div class="sello">
                <span class="rotulo">Pagado · sello y firma</span>
                <div class="renglon">Fecha <i></i></div>
                <div class="renglon">Firma <i></i></div>
            </div>
        </section>

        <footer class="pie">
            <div class="copias">Original: Cliente &nbsp;·&nbsp; Copia: Obligado Tributario Emisor</div>
            @foreach ($leyendas as $leyenda)
                <div class="exijala">{{ mb_strtoupper($leyenda) }}</div>
            @endforeach
        </footer>
    </div>
</body>
</html>
