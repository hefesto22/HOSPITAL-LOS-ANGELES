{{--
    Etiquetas del hospital, en dos formatos.

    · GRANDE — media hoja A4 (210 × 148,5 mm) con UN código, para pegar en
      la gaveta o el estante donde vive el medicamento. Se lee y se escanea
      de lejos, sin agacharse.

    · HOJA — treinta etiquetas chicas en una A4, para el frasco, el blíster
      reenvasado o la caja de compra.

    ─────────────────────────────────────────────────────────────────────
    🔴 LA VISTA RECIBE TEXTO, NO MODELOS
    ─────────────────────────────────────────────────────────────────────

    Antes recibía `$item` y armaba sola el enlace al otro formato con
    `route('etiquetas.item', ...)`. Servía mientras hubo un solo tipo de
    etiqueta; con el principio activo empezó a mentir —le pasaban un
    PrincipioActivo y el enlace apuntaba al ÍTEM con ese id, que es otra
    cosa— y no daba ningún error: llevaba a una etiqueta ajena.

    Ahora quien llama arma su propio enlace y esto solo dibuja. Tres
    etiquetas distintas, una sola hoja de estilos de impresión.

    @param string      $codigo          lo que se codifica en barras
    @param string      $nombre          el renglón grande
    @param string|null $subtitulo       debajo, chico: principio activo, equivalencia
    @param string      $formato         'media' | 'hoja'
    @param int         $copias
    @param string      $urlOtroFormato
--}}
@php
    $esGrande = $formato === 'media';
    $svg = \App\Support\CodigoDeBarras::svg(
        $codigo,
        modulo: $esGrande ? 4 : 2,
        alto: $esGrande ? 120 : 44,
    );
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiqueta · {{ $codigo }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 12px;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #000;
            background: #fff;
        }

        .barra-superior {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 16px;
            margin-bottom: 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }

        .barra-superior h1 { font-size: 15px; margin: 0; font-weight: 600; }
        .barra-superior p  { font-size: 12px; margin: 4px 0 0; color: #555; }
        .barra-superior a  { font-size: 12px; color: #b45309; }

        button {
            font: inherit;
            font-size: 13px;
            padding: 8px 18px;
            border: 0;
            border-radius: 6px;
            background: #b45309;
            color: #fff;
            cursor: pointer;
        }

        /* ── Media A4, un solo código ─────────────────────────────── */
        .grande {
            width: 210mm;
            height: 148.5mm;
            padding: 12mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6mm;
            text-align: center;
            border: 1px dashed #bbb;
            page-break-after: always;
        }

        .grande .nombre     { font-size: 30px; font-weight: 800; line-height: 1.15; }
        .grande .subtitulo  { font-size: 16px; color: #333; }
        .grande svg         { max-width: 160mm; height: auto; }

        /* ── Hoja de etiquetas chicas ─────────────────────────────── */
        .hoja { display: flex; flex-wrap: wrap; gap: 8px; }

        .etiqueta {
            width: 175px;
            padding: 8px 6px;
            border: 1px dashed #bbb;
            border-radius: 4px;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .etiqueta .nombre {
            font-size: 9px;
            font-weight: 700;
            line-height: 1.2;
            min-height: 22px;
            margin-bottom: 4px;
            overflow: hidden;
        }

        .etiqueta .pie { font-size: 8px; color: #444; margin-top: 2px; }
        .etiqueta svg  { width: 100%; height: auto; }

        @media print {
            body { padding: 0; }
            .barra-superior { display: none; }
            .etiqueta, .grande { border-color: transparent; }
        }
    </style>
</head>
<body>
    <div class="barra-superior">
        <div>
            <h1>{{ $codigo }} — {{ $nombre }}</h1>
            <p>
                @if ($esGrande)
                    Media hoja A4, un solo código. Para la gaveta o el estante.
                    <a href="{{ $urlOtroFormato }}">Ver hoja de 30 chicas</a>
                @else
                    {{ $copias }} {{ $copias === 1 ? 'etiqueta' : 'etiquetas' }} para frasco, blíster o caja.
                    <a href="{{ $urlOtroFormato }}">Ver etiqueta grande</a>
                @endif
            </p>
        </div>
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    @if ($svg === '')
        <p>Este código no se puede imprimir en barras: tiene caracteres fuera del ASCII imprimible.</p>
    @elseif ($esGrande)
        <div class="grande">
            <div class="nombre">{{ $nombre }}</div>

            @if ($subtitulo)
                <div class="subtitulo">{{ $subtitulo }}</div>
            @endif

            {!! $svg !!}
        </div>
    @else
        <div class="hoja">
            @for ($i = 0; $i < $copias; $i++)
                <div class="etiqueta">
                    <div class="nombre">{{ $nombre }}</div>
                    {!! $svg !!}
                    @if ($subtitulo)
                        <div class="pie">{{ $subtitulo }}</div>
                    @endif
                </div>
            @endfor
        </div>
    @endif

    <script>
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
