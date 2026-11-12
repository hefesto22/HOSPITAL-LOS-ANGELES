{{--
    La ficha de «Ver cuenta», en una sola hoja (Mauricio, 11-nov-2026:
    «mejorar el diseño»). Los datos los arma `FichaDeLaCuenta`; acá solo se
    pintan. Debajo van la bitácora y los cargos, cada uno en su lugar.

    Lleva su propio estilo: el CSS de Filament está precompilado y las
    clases de Tailwind nuevas no existen en el panel (§9.A7). Los colores
    salen de las variables de Filament y van con var() solo, nunca dentro
    de rgb() (§9.M28).

    ⚠️ Lo de PHP va en un bloque, nunca en una línea suelta (§9.M18).
--}}
@php
    $cuentaDeLaFicha = $getRecord();
    $ficha = $cuentaDeLaFicha instanceof \App\Models\Cuenta ? \App\Support\FichaDeLaCuenta::de($cuentaDeLaFicha) : null;
@endphp

@if ($ficha !== null)
    <section class="sihla-ficha">
        <header class="sihla-ficha-cabeza">
            <div class="sihla-ficha-quien">
                <span class="sihla-ficha-tipo" style="--tono: var(--{{ $ficha['tipo']->color() }}-500)">
                    <x-filament::icon :icon="$ficha['tipo']->icono()" class="sihla-ficha-tipo-icono" />
                </span>

                <div class="sihla-ficha-nombres">
                    <h2 class="sihla-ficha-nombre">{{ $ficha['paciente'] }}</h2>
                    <p class="sihla-ficha-atencion">
                        {{ $ficha['tipo']->etiqueta() }}
                        @if ($ficha['servicio'] !== null)
                            · {{ $ficha['servicio'] }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="sihla-ficha-sellos">
                <x-filament::badge :color="$ficha['estado']->color()" :icon="$ficha['iconoDelEstado']">
                    {{ $ficha['estadoEnPalabras'] }}
                </x-filament::badge>

                <x-filament::badge :color="$ficha['colorDelPagador']">
                    {{ $ficha['pagador'] }}
                </x-filament::badge>
            </div>
        </header>

        <p class="sihla-ficha-explica">{{ $ficha['queQuiereDecir'] }}</p>

        @if ($ficha['anuladaPorque'] !== null)
            <p class="sihla-ficha-anulada">
                <strong>Por qué se anuló:</strong> {{ $ficha['anuladaPorque'] }}
            </p>
        @endif

        <dl class="sihla-ficha-datos">
            @foreach ($ficha['datos'] as $dato)
                <div>
                    <dt>{{ $dato['rotulo'] }}</dt>
                    <dd @class(['sihla-ficha-mono' => $dato['mono']])>{{ $dato['valor'] }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="sihla-ficha-cifras">
            @foreach ($ficha['cifras'] as $cifra)
                <div class="sihla-cifra sihla-cifra-{{ $cifra['tono'] }}">
                    <span class="sihla-cifra-rotulo">{{ $cifra['rotulo'] }}</span>
                    <span class="sihla-cifra-valor">{{ $cifra['valor'] }}</span>
                    @if ($cifra['nota'] !== null)
                        <span class="sihla-cifra-nota">{{ $cifra['nota'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="sihla-ficha-desglose">
            @foreach ($ficha['desglose'] as $parte)
                <span><span class="sihla-ficha-desglose-rotulo">{{ $parte['rotulo'] }}</span> {{ $parte['valor'] }}</span>
            @endforeach
        </p>

        @if ($ficha['facturas'] !== [])
            <div class="sihla-ficha-facturas">
                <span class="sihla-ficha-facturas-rotulo">{{ count($ficha['facturas']) === 1 ? 'Factura' : 'Facturas' }}</span>

                @foreach ($ficha['facturas'] as $factura)
                    <span @class(['sihla-factura', 'sihla-factura-anulada' => $factura['anulada']])>
                        <span class="sihla-ficha-mono">{{ $factura['numero'] }}</span>
                        <span class="sihla-factura-detalle">{{ $factura['total'] }} · {{ $factura['cuando'] }}</span>
                        @if ($factura['anulada'])
                            <span class="sihla-factura-sello">Anulada</span>
                        @endif
                        @if ($factura['enlace'] !== null)
                            <a href="{{ $factura['enlace'] }}" target="_blank" rel="noopener" class="sihla-factura-enlace">Imprimir</a>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </section>

    <style>
        .sihla-ficha {
            --sf-borde: rgb(0 0 0 / .08);
            --sf-fondo: #fff;
            --sf-suave: var(--gray-50);
            --sf-texto: var(--gray-950);
            --sf-tenue: var(--gray-500);

            padding: 1.1rem 1.25rem 1.2rem;
            border: 1px solid var(--sf-borde);
            border-radius: .85rem;
            background: var(--sf-fondo);
            box-shadow: 0 1px 2px rgb(0 0 0 / .04);
            color: var(--sf-texto);
        }
        .dark .sihla-ficha {
            --sf-borde: rgb(255 255 255 / .08);
            --sf-fondo: var(--gray-900);
            --sf-suave: rgb(255 255 255 / .03);
            --sf-texto: var(--gray-50);
            --sf-tenue: var(--gray-400);

            box-shadow: none;
        }

        /* ── Quién y en qué etapa ── */
        .sihla-ficha-cabeza { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem 1rem; }
        .sihla-ficha-quien { display: flex; align-items: center; gap: .8rem; min-width: 0; }
        .sihla-ficha-tipo {
            display: grid; place-items: center; flex: none;
            width: 2.6rem; height: 2.6rem; border-radius: .7rem;
            color: var(--tono);
            background: color-mix(in oklab, var(--tono) 14%, transparent);
        }
        .dark .sihla-ficha-tipo { background: color-mix(in oklab, var(--tono) 22%, transparent); }
        .sihla-ficha-tipo-icono { width: 1.35rem; height: 1.35rem; }
        .sihla-ficha-nombres { min-width: 0; }
        .sihla-ficha-nombre { margin: 0; font-size: 1.15rem; font-weight: 700; line-height: 1.3; letter-spacing: -.01em; }
        .sihla-ficha-atencion { margin: .1rem 0 0; font-size: .8rem; color: var(--sf-tenue); }
        .sihla-ficha-sellos { display: flex; flex-wrap: wrap; gap: .4rem; }

        .sihla-ficha-explica { margin: .55rem 0 0; font-size: .8rem; color: var(--sf-tenue); }
        .sihla-ficha-anulada {
            margin: .6rem 0 0; padding: .5rem .75rem; border-radius: .5rem;
            font-size: .8rem; color: var(--danger-700);
            background: color-mix(in oklab, var(--danger-500) 10%, transparent);
        }
        .dark .sihla-ficha-anulada { color: var(--danger-300); }

        /* ── Los datos, en renglones cortos ── */
        .sihla-ficha-datos {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: .7rem 1.25rem;
            margin: 1rem 0 0; padding: .9rem 0 0; border-top: 1px solid var(--sf-borde);
        }
        .sihla-ficha-datos dt { font-size: .7rem; font-weight: 600; letter-spacing: .02em; color: var(--sf-tenue); }
        .sihla-ficha-datos dd { margin: .15rem 0 0; font-size: .85rem; overflow-wrap: anywhere; }
        .sihla-ficha-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }

        /* ── Las cinco cifras ── */
        .sihla-ficha-cifras {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: .6rem;
            margin-top: 1rem;
        }
        .sihla-cifra {
            --cifra: var(--sf-texto);

            display: flex; flex-direction: column; gap: .1rem; min-width: 0;
            padding: .7rem .85rem; border-radius: .65rem;
            border: 1px solid var(--sf-borde); background: var(--sf-suave);
        }
        .sihla-cifra-rotulo { font-size: .72rem; font-weight: 600; color: var(--sf-tenue); }
        .sihla-cifra-valor {
            font-size: 1.1rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap;
            color: var(--cifra);
        }
        .sihla-cifra-nota { font-size: .7rem; color: var(--sf-tenue); }
        .sihla-cifra-success { --cifra: var(--success-600); }
        .sihla-cifra-warning { --cifra: var(--warning-600); border-color: color-mix(in oklab, var(--warning-500) 35%, transparent); background: color-mix(in oklab, var(--warning-500) 8%, transparent); }
        .sihla-cifra-info { --cifra: var(--info-600); border-color: color-mix(in oklab, var(--info-500) 35%, transparent); background: color-mix(in oklab, var(--info-500) 8%, transparent); }
        .dark .sihla-cifra-success { --cifra: var(--success-400); }
        .dark .sihla-cifra-warning { --cifra: var(--warning-400); }
        .dark .sihla-cifra-info { --cifra: var(--info-400); }

        .sihla-ficha-desglose {
            display: flex; flex-wrap: wrap; gap: .25rem 1.1rem;
            margin: .6rem 0 0; font-size: .75rem; font-variant-numeric: tabular-nums; color: var(--sf-tenue);
        }
        .sihla-ficha-desglose-rotulo { font-weight: 600; }

        /* ── Las facturas ── */
        .sihla-ficha-facturas {
            display: flex; flex-wrap: wrap; align-items: center; gap: .45rem .6rem;
            margin-top: .9rem; padding-top: .8rem; border-top: 1px solid var(--sf-borde);
        }
        .sihla-ficha-facturas-rotulo { font-size: .72rem; font-weight: 600; color: var(--sf-tenue); }
        .sihla-factura {
            display: inline-flex; flex-wrap: wrap; align-items: center; gap: .2rem .55rem;
            padding: .3rem .6rem; border-radius: .5rem;
            border: 1px solid var(--sf-borde); background: var(--sf-suave); font-size: .8rem;
        }
        .sihla-factura-detalle { font-size: .75rem; color: var(--sf-tenue); font-variant-numeric: tabular-nums; }
        .sihla-factura-anulada .sihla-ficha-mono { text-decoration: line-through; color: var(--sf-tenue); }
        .sihla-factura-sello { font-size: .7rem; font-weight: 600; color: var(--danger-600); }
        .dark .sihla-factura-sello { color: var(--danger-400); }
        .sihla-factura-enlace { font-size: .75rem; font-weight: 600; color: var(--primary-600); }
        .dark .sihla-factura-enlace { color: var(--primary-400); }
        .sihla-factura-enlace:hover { text-decoration: underline; }

        @media (max-width: 40rem) {
            .sihla-ficha { padding: .9rem; }
            .sihla-ficha-cifras { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .sihla-cifra-valor { font-size: 1rem; }
        }
    </style>
@endif
