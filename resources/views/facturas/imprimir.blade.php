{{--
    La factura, calcada del formulario que el hospital ya usa.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ SE COPIA EL PAPEL VIEJO Y NO SE DISEÑA UNO NUEVO
    ─────────────────────────────────────────────────────────────────────

    Porque la cajera, el contador y el auditor del SAR ya saben dónde
    mirar. Un formulario «mejor» obliga a tres personas a reaprender
    dónde está el CAI el día que estrenen el sistema, y a la cuarta
    factura alguien va a decir que el sistema anterior era mejor.

    ─────────────────────────────────────────────────────────────────────
    🔴 TODO SALE DE LA FILA DE LA FACTURA
    ─────────────────────────────────────────────────────────────────────

    Ni el cliente, ni el CAI, ni los montos, ni el código del producto se
    leen por relación: están congelados en `facturas` y `factura_lineas`.
    Una reimpresión de hace ocho meses sale idéntica aunque el catálogo y
    los precios hayan cambiado diez veces.

    Lo único que se lee del sistema son los datos del EMISOR — el
    hospital no cambia de nombre entre dos impresiones.
--}}
@php($lineas = $factura->detalle)
@php($leyendas = config('sihla.facturacion.leyendas', []))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $factura->numero }}</title>
    <style>
        @page { size: letter; margin: 10mm; }
        * { box-sizing: border-box; }

        body {
            margin: 0; background: #f4f4f5; color: #000;
            font-family: Arial, Helvetica, sans-serif; font-size: 10px;
        }

        /*
         * Incrustada en el modal la hoja arranca pegada arriba: el
         * margen del visor lo pone el modal, y duplicarlo deja una
         * franja gris que parece un error de carga.
         */
        body.incrustada { background: #fff; }
        body.incrustada .hoja { margin: 0 auto; box-shadow: none; }

        .hoja { width: 216mm; margin: 0 auto; padding: 10mm; background: #fff; }

        @media print {
            body { background: #fff; }
            .hoja { width: auto; margin: 0; padding: 0; }
            .no-imprime { display: none !important; }

            /*
             * ─────────────────────────────────────────────────────────
             * LA SEGUNDA HOJA CONTINÚA, SIN REPETIR NADA
             * ─────────────────────────────────────────────────────────
             *
             * Una cirugía desglosada son veinte renglones y pasa de una
             * hoja. Por omisión el navegador REPITE el `<thead>` de la
             * tabla en cada página: la segunda salía encabezada como si
             * fuera otro documento, y un papel fiscal que parece empezar
             * de nuevo se lee como una factura distinta.
             *
             * `table-row-group` lo baja a una fila común y deja de
             * repetirse. Decisión de Mauricio (2-sep-2026): continúa
             * sin encabezado.
             */
            .detalle thead { display: table-row-group; }

            /*
             * Y lo que no se puede partir a la mitad: un renglón cortado
             * entre dos hojas deja media cifra arriba y media abajo, y
             * los totales separados de su detalle obligan a dar vuelta
             * la hoja para saber de qué son.
             */
            .detalle tr, .detalle tfoot, .totales, .firmas { break-inside: avoid; }
        }

        .barra { max-width: 216mm; margin: 8px auto; display: flex; gap: 8px; justify-content: flex-end; }
        .barra button, .barra a {
            padding: 6px 14px; font-size: 12px; border-radius: 6px; cursor: pointer;
            border: 1px solid #d4d4d8; background: #fff; color: #111; text-decoration: none;
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }

        /*
         * ─────────────────────────────────────────────────────────────
         * EL MISMO FORMULARIO, MEJOR VESTIDO (12-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Mauricio: «que el logo se vea más grande, que se vea mejor».
         * Los recuadros siguen donde estaban —la cajera, el contador y
         * el SAR ya saben dónde mirar—; lo que cambia es cómo se leen:
         * los rótulos en gris claro y los datos en blanco, el
         * encabezado de la tabla y el TOTAL A PAGAR con fondo, y el
         * membrete separado del resto por una raya.
         *
         * ⚠️ Los grises se imprimen aunque el diálogo tenga apagado
         * «Gráficos en segundo plano»: `print-color-adjust: exact`. Sin
         * eso el papel sale sin fondos y se pierde qué es rótulo y qué
         * es dato.
         */
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .marco td, .marco th { border: 1px solid #1f1f1f; padding: 3px 6px; }
        .marco td.etiqueta { background: #f0f0f0; }

        .encabezado { padding-bottom: 4mm; margin-bottom: 3mm; border-bottom: 2px solid #111; }

        /*
         * ─────────────────────────────────────────────────────────────
         * EL MEMBRETE: EL LOGO ARRIBA, GRANDE; LA RAZÓN SOCIAL DEBAJO
         * ─────────────────────────────────────────────────────────────
         *
         * Al lado del nombre el logo no podía pasar de 52 px sin partir
         * la razón social en dos renglones. Encima del nombre tiene todo
         * el ancho del membrete, y la columna queda del mismo alto que
         * la del CAI.
         *
         * 🔴 EL NOMBRE SE IMPRIME AUNQUE HAYA LOGO. La razón social del
         * emisor es obligatoria en el papel; un logotipo no la
         * sustituye ante el SAR ni aunque la lleve dibujada adentro.
         *
         * ⚠️ FLEX Y NO UNA TABLA ADENTRO DE LA CELDA. Un logo SVG —que
         * trae `viewBox` y no `width`— sale de 0×0 adentro de una celda
         * de tabla: el navegador calcula el ancho mínimo antes de
         * resolver la proporción. La factura salía SIN logo y sin error.
         */
        .membrete { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }
        .logo { display: block; max-height: 22mm; max-width: 85mm; flex: none; }

        .emisor h1 { margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .02em; line-height: 1.25; }
        .membrete.sin-logo h1 { font-size: 18px; }
        .emisor p { margin: 0; font-size: 9px; line-height: 1.45; color: #333; }

        .rotulo-factura { text-align: right; font-size: 20px; font-weight: 700; letter-spacing: .3em; margin-right: -.3em; }
        .rtn-emisor { margin: 2px 0 6px; text-align: right; font-size: 10.5px; font-weight: 700; }
        .cai { text-align: center; font-size: 10.5px; font-weight: 700; letter-spacing: .03em; }
        .cai .rotulo-cai { display: block; font-size: 7.5px; font-weight: 400; letter-spacing: .12em; color: #444; }

        .etiqueta { font-size: 9px; }
        .dato { font-weight: 700; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .centro { text-align: center; }

        .detalle thead th {
            border: 1px solid #1f1f1f; padding: 5px 4px; font-size: 8.5px; text-align: center;
            font-weight: 700; background: #e6e6e6;
        }
        .detalle tbody td { border-left: 1px solid #1f1f1f; border-right: 1px solid #1f1f1f; padding: 3px 6px; }

        /*
         * El renglón que nombra una cirugía desglosada. Va en negrita y
         * SIN columnas numéricas: los renglones que cobran son los de
         * abajo, y un «0.00» al lado del nombre del procedimiento se lee
         * como una línea que falló. Lo garantiza el CHECK
         * `factura_lineas_encabezado_no_cobra`, no esta hoja de estilo.
         */
        .detalle tbody tr.titulo td { font-weight: 700; }
        .detalle tbody tr:last-child td { border-bottom: 1px solid #1f1f1f; }

        .detalle tfoot td { border: 1px solid #1f1f1f; padding: 4px 6px; font-weight: 700; background: #f0f0f0; }
        .detalle .rotulo-sumas { text-align: right; font-size: 9px; letter-spacing: .08em; }

        .totales td { border: 1px solid #1f1f1f; padding: 3px 6px; }
        .totales .rot { text-align: right; font-size: 9px; background: #f0f0f0; }
        .totales tr.final td { padding: 5px 6px; font-size: 11.5px; font-weight: 700; background: #dedede; }

        .letras { margin-top: 8px; text-align: center; font-size: 10px; }
        .rango { margin-top: 6px; font-size: 9px; }
        .copias { margin-top: 2px; font-size: 9px; }
        .exijala {
            margin-top: 8px; padding-top: 5px; border-top: 1px solid #bbb;
            text-align: center; font-size: 11px; font-weight: 700; letter-spacing: .04em;
        }

        .firmas td { padding-top: 14mm; font-size: 9px; text-align: center; }
        .firmas .linea { border-top: 1px solid #000; margin: 0 4mm; padding-top: 2px; }

        .anulada {
            margin: 6px 0; padding: 4px; border: 2px solid #b91c1c; color: #b91c1c;
            text-align: center; font-weight: 700; letter-spacing: .12em;
        }
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
        {{-- ── Encabezado ───────────────────────────────────────────── --}}
        <div class="encabezado">
            <table>
                <tr>
                    <td style="width: 56%; padding-right: 8mm" class="emisor">
                        {{--
                            🔴 EL LOGO VA INCRUSTADO, NO ENLAZADO.

                            La factura se imprime desde un iframe y el
                            navegador abre el diálogo apenas carga: una
                            imagen que todavía se está descargando sale
                            como un hueco blanco en el papel. En base64 ya
                            está ahí. Si no hay logo o el archivo
                            desapareció, el membrete se imprime solo con el
                            nombre, más grande — una factura no se cae por
                            una imagen.
                        --}}
                        @php($logo = $sede->logoIncrustado())
                        @php($telefono = $sede->telefonoImpreso())

                        <div @class(['membrete', 'sin-logo' => ! $logo])>
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $sede->nombre }}" class="logo">
                            @endif

                            <div>
                                <h1>{{ mb_strtoupper($sede->razon_social) }}</h1>
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
                            </div>
                        </div>
                    </td>

                    <td style="width: 44%">
                        <div class="rotulo-factura">{{ mb_strtoupper($factura->tipo->etiqueta()) }}</div>
                        <p class="rtn-emisor">R.T.N. {{ $sede->rtn ?? '—' }}</p>

                        {{--
                            🔴 EL CAI VA ARRIBA DEL NÚMERO, EN SU RECUADRO.
                            Sin él impreso, el documento no es una factura:
                            es un papel sin valor fiscal.
                        --}}
                        <table class="marco">
                            <tr>
                                <td colspan="2" class="cai">
                                    <span class="rotulo-cai">C.A.I.</span>
                                    {{ $factura->cai }}
                                </td>
                            </tr>
                            <tr>
                                <td class="etiqueta">Número Factura</td>
                                <td class="dato centro">{{ $factura->numero }}</td>
                            </tr>
                            <tr>
                                <td class="etiqueta">Fecha</td>
                                <td class="centro">{{ $factura->emitida_en->format('d/m/Y') }}</td>
                            </tr>
                            <tr>
                                <td class="etiqueta">Vencimiento</td>
                                <td class="centro">{{ $factura->vence_el?->format('d/m/Y') ?? $factura->emitida_en->format('d/m/Y') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        @if (! $factura->estaViva())
            <p class="anulada">ANULADA — {{ $factura->motivo_anulacion }}</p>
        @endif

        {{-- ── Cliente, facturador y términos ───────────────────────── --}}
        <table style="margin-top: 4px">
            <tr>
                <td style="width: 55%; padding-right: 4px">
                    {{--
                        🔴 El cliente es QUIEN PAGA; el paciente va aparte
                        (Mauricio, 11-oct-2026). Con seguro el cliente es la
                        aseguradora, sin seguro suele ser «CONSUMIDOR FINAL»,
                        y el paciente está siempre en su propio renglón, con
                        su identidad y su póliza si las tiene. El «Código de
                        Cliente» de abajo es su número de expediente.

                        Las facturas emitidas antes de ese día no tienen
                        paciente congelado —lo llevaban dentro del nombre del
                        cliente— y salen sin el renglón, como se emitieron.
                    --}}
                    <table class="marco">
                        <tr>
                            <td>
                                <span class="etiqueta">Nombre Cliente:</span><br>
                                <span class="dato">{{ $factura->cliente_nombre }}</span>
                                @if (filled($factura->cliente_direccion) || filled($factura->cliente_telefono))
                                    <br>{{ $factura->cliente_direccion }}{{ filled($factura->cliente_direccion) && filled($factura->cliente_telefono) ? ' / ' : '' }}{{ $factura->cliente_telefono }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="etiqueta">{{ $factura->rotuloDelDocumento() }}:</span>
                                <span class="dato">{{ $factura->cliente_documento }}</span>
                            </td>
                        </tr>
                        @if (filled($factura->paciente_nombre))
                            <tr>
                                <td>
                                    <span class="etiqueta">Paciente:</span>
                                    <span class="dato">{{ $factura->paciente_nombre }}</span>
                                    @if (filled($factura->paciente_documento) || filled($factura->paciente_poliza))
                                        <br>
                                        @if (filled($factura->paciente_documento))
                                            <span class="etiqueta">{{ $factura->rotuloDelDocumentoDelPaciente() }}:</span>
                                            <span class="dato">{{ $factura->documentoDelPacienteImpreso() }}</span>
                                        @endif
                                        @if (filled($factura->paciente_documento) && filled($factura->paciente_poliza))
                                            &nbsp;&nbsp;&nbsp;
                                        @endif
                                        @if (filled($factura->paciente_poliza))
                                            <span class="etiqueta">Póliza:</span>
                                            <span class="dato">{{ $factura->paciente_poliza }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endif
                    </table>
                </td>

                <td style="width: 45%">
                    <table class="marco">
                        <tr>
                            <td class="etiqueta centro">Facturador</td>
                            <td class="etiqueta centro">Términos</td>
                        </tr>
                        <tr>
                            <td>{{ $factura->facturador }}</td>
                            <td>{{ $factura->terminos }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="marco" style="margin-top: 2px">
            <tr>
                <td class="etiqueta">Código de Cliente</td>
                <td class="etiqueta">No. Orden Compra Exenta</td>
                <td class="etiqueta">No. de Constancia Reg. Exonerada</td>
                <td class="etiqueta">No. Registro SAG</td>
            </tr>
            <tr>
                <td>{{ $factura->cliente_codigo }}&nbsp;</td>
                <td>{{ $factura->cliente_orden_exenta }}&nbsp;</td>
                <td>{{ $factura->cliente_constancia_exonerado }}&nbsp;</td>
                <td>{{ $factura->cliente_registro_sag }}&nbsp;</td>
            </tr>
        </table>

        {{-- ── El detalle ───────────────────────────────────────────── --}}
        <table class="detalle" style="margin-top: 4px">
            <thead>
                <tr>
                    <th style="width: 22mm">Cod. Producto</th>
                    <th>D E S C R I P C I Ó N</th>
                    <th style="width: 16mm">Cantidad</th>
                    <th style="width: 20mm">Precio Unitario</th>
                    <th style="width: 24mm">Descuentos y Rebajas Otorgados</th>
                    <th style="width: 22mm">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lineas as $linea)
                    @if ($linea->encabezado)
                        <tr class="titulo">
                            <td>{{ $linea->codigo }}</td>
                            <td colspan="5">{{ $linea->descripcion }}</td>
                        </tr>
                    @else
                        <tr>
                            <td>{{ $linea->codigo }}</td>
                            <td>{{ $linea->descripcion }}</td>
                            <td class="num">{{ number_format((float) $linea->cantidad, 2) }}</td>
                            <td class="num">{{ number_format((float) $linea->precio_unitario, 2) }}</td>
                            <td class="num">{{ number_format((float) $linea->descuento()->redondeado(2), 2) }}</td>
                            <td class="num">{{ number_format((float) $linea->total, 2) }}</td>
                        </tr>
                    @endif
                @endforeach

                {{--
                    Renglones en blanco para que el recuadro tenga cuerpo
                    y no quede una tira de una línea flotando arriba de
                    los totales.

                    ⚠️ OCHO Y NO DIECIOCHO. Con dieciocho, una factura de
                    un solo renglón —el caso normal cuando se cobra un
                    paquete— salía con media hoja en blanco adentro del
                    recuadro y parecía que la impresión se había cortado.
                --}}
                @for ($i = $lineas->count(); $i < 8; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>

            {{--
                🔴 LAS SUMAS DE COLUMNA VAN BAJO SU COLUMNA.

                Estaban en el bloque de totales, en una tabla aparte y
                más angosta: los dos números caían corridos respecto de
                las columnas que sumaban y quien revisaba el papel no
                tenía cómo saber que ese «0.00» era el de los descuentos.
                Acá caen exactamente debajo, que es donde el ojo los
                busca.
            --}}
            <tfoot>
                <tr class="sumas">
                    <td></td>
                    <td class="rotulo-sumas">T O T A L E S</td>
                    <td></td>
                    <td></td>
                    <td class="num">{{ number_format((float) $factura->descuento_legal + (float) $factura->descuento_comercial, 2) }}</td>
                    <td class="num">{{ number_format((float) $factura->bruto, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- ── Comentarios, firmas y totales ────────────────────────── --}}
        <table style="margin-top: 4px">
            <tr>
                <td style="width: 58%; padding-right: 4px">
                    <table class="marco" style="height: 100%">
                        <tr>
                            <td style="height: 26mm">
                                <span class="etiqueta"><em>Comentarios:</em></span><br>
                                {{ $factura->comentarios }}
                            </td>
                        </tr>
                    </table>

                    <table class="firmas">
                        <tr>
                            <td style="width: 50%"><div class="linea">Firma Por {{ mb_strtoupper($sede->razon_social) }}</div></td>
                            <td style="width: 50%"><div class="linea">Firma Recibido de Conformidad</div></td>
                        </tr>
                    </table>
                </td>

                <td style="width: 42%">
                    {{--
                        Las seis casillas van SEPARADAS porque el SAR las
                        pide separadas: el impuesto declarado en la
                        casilla equivocada es un hallazgo con multa.
                    --}}
                    <table class="totales">
                        <tr>
                            <td class="rot">IMPORTE EXONERADO L.</td>
                            <td class="num">{{ number_format((float) $factura->exonerado, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">IMPORTE EXENTO L.</td>
                            <td class="num">{{ number_format((float) $factura->exento, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">DESCUENTO L.</td>
                            <td class="num">{{ number_format((float) $factura->descuento_legal + (float) $factura->descuento_comercial, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">IMPORTE GRAVADO 15% L.</td>
                            <td class="num">{{ number_format((float) $factura->gravado_15, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">IMPORTE GRAVADO 18% L.</td>
                            <td class="num">{{ number_format((float) $factura->gravado_18, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">ISV 15% L.</td>
                            <td class="num">{{ number_format((float) $factura->isv_15, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="rot">ISV 18% L.</td>
                            <td class="num">{{ number_format((float) $factura->isv_18, 2) }}</td>
                        </tr>
                        <tr class="final">
                            <td class="rot dato">T O T A L &nbsp; A &nbsp; P A G A R &nbsp; L.</td>
                            <td class="num dato">{{ number_format((float) $factura->total, 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- El total en letras: la defensa contra el dígito alterado. --}}
        <p class="letras">La Cantidad de: <strong>{{ $enLetras }}</strong></p>

        <p class="rango">
            Rango Autorizado de Facturas: {{ $rango }} &nbsp;
            Fecha Límite Emisión {{ $factura->fecha_limite_emision->format('d/m/Y') }}
        </p>

        <p class="copias">Original: Cliente &nbsp;&nbsp;&nbsp; Copia: Obligado Tributario Emisor</p>

        @foreach ($leyendas as $leyenda)
            <p class="exijala">{{ mb_strtoupper($leyenda) }}</p>
        @endforeach
    </div>
</body>
</html>
