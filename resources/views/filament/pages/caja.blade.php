{{--
    La gaveta de quien está cobrando (rediseño del 12-oct-2026).

    Se lee de arriba abajo, en el orden en que la cajera se pregunta las
    cosas:

      1. ¿De quién es esta gaveta y desde cuándo está abierta?
      2. ¿Cuánto efectivo tiene que haber? — escrito como la suma que es
         (fondo + efectivo recibido = gaveta), no como cinco cuadros
         sueltos del mismo tamaño donde el que importa no se distingue.
      3. ¿Qué entró que NO está en la gaveta? — tarjeta y transferencia,
         cada una con dónde se cuadra.
      4. ¿Qué recibos hice? — con el nombre del paciente, que es lo que
         se reconoce; el número de cuenta va debajo.

    Los anulados se esconden por defecto (siguen a un clic, con su
    motivo): eran cinco tachados entre dos vigentes.

    ⚠️ Colores literales y no `rgb(var(--gray-200))`: en Filament 5 esas
    variables traen `oklch(…)` completo, el `rgb()` queda inválido y el
    navegador descarta la regla sin avisar — por eso esta pantalla se veía
    sin bordes ni fondos. Las variables propias de abajo cambian solas con
    `.dark`.

    El CSS de Filament viene precompilado (§9.A7): lo que no usa el panel
    no existe acá, de ahí el `<style>` propio.
--}}
<x-filament-panels::page>
    @php($resumen = $this->resumen())
    @php($lempiras = static fn (\App\Domain\ValueObjects\Decimal $monto): string => 'L '.number_format((float) $monto->redondeado(2), 2))

    <div class="sihla-caja">
        @if ($resumen === null)
            <x-filament::empty-state
                heading="No tenés un turno abierto"
                description="Abrí el turno con el fondo que hay en la gaveta para empezar a cobrar. Sin turno no se recibe plata: el efectivo tiene que caer en una gaveta que alguien cuente al final."
                icon="heroicon-o-lock-closed"
                icon-color="gray"
            >
                <x-slot name="footer">
                    {{ $this->abrirTurnoAction }}
                </x-slot>
            </x-filament::empty-state>
        @else
            @php($turno = $resumen['turno'])
            @php($vigentes = $resumen['vigentes'])
            @php($anulados = $resumen['anulados'])
            @php($tieneNombre = filled($turno->nombre))

            {{-- 1 · De quién es la gaveta --}}
            <header class="sihla-caja-turno">
                <div>
                    <h2 class="sihla-caja-turno-nombre">{{ $tieneNombre ? $turno->nombre : $turno->numero }}</h2>
                    <p class="sihla-caja-turno-detalle">
                        @if ($tieneNombre)
                            {{ $turno->numero }} ·
                        @endif
                        Abierto por {{ $turno->usuario?->name ?? 'vos' }}
                        el {{ $turno->abierto_en->format('d/m/Y') }} a las {{ $turno->abierto_en->format('H:i') }}
                    </p>
                </div>

                {{ $this->cerrarTurnoAction }}
            </header>

            @if ($resumen['abiertoHaceMucho'])
                <div class="sihla-caja-aviso" role="status">
                    <x-filament::icon icon="heroicon-o-clock" class="sihla-caja-aviso-icono" />
                    <p>
                        Este turno está abierto desde {{ haceCuanto($turno->abierto_en) }}.
                        Si ya terminó tu jornada, cerralo: si no, el arqueo mezcla el efectivo de varios días.
                    </p>
                </div>
            @endif

            <div class="sihla-caja-cuadre">
                {{-- 2 · La gaveta, como la suma que es --}}
                <section class="sihla-caja-bloque sihla-caja-gaveta" aria-labelledby="sihla-caja-gaveta-titulo">
                    <h3 id="sihla-caja-gaveta-titulo" class="sihla-caja-bloque-titulo">En la gaveta</h3>

                    <dl class="sihla-caja-cinta">
                        <div class="sihla-caja-cinta-linea">
                            <dt><span class="sihla-caja-signo" aria-hidden="true"></span>Fondo con que se abrió</dt>
                            <dd>{{ $lempiras($resumen['fondo']) }}</dd>
                        </div>

                        @foreach ($resumen['enLaGaveta'] as $linea)
                            <div class="sihla-caja-cinta-linea">
                                <dt><span class="sihla-caja-signo" aria-hidden="true">+</span>{{ $linea['etiqueta'] }}</dt>
                                <dd>{{ $lempiras($linea['monto']) }}</dd>
                            </div>
                        @endforeach

                        {{--
                            🔴 El número del arqueo. Solo efectivo: lo de
                            tarjeta lo liquida el POS y lo de transferencia
                            el banco; contarlos acá daría un sobrante que
                            no existe todas las noches.
                        --}}
                        <div class="sihla-caja-cinta-linea sihla-caja-cinta-total">
                            <dt><span class="sihla-caja-signo" aria-hidden="true">=</span>Tiene que haber</dt>
                            <dd>{{ $lempiras($resumen['gaveta']) }}</dd>
                        </div>
                    </dl>

                    <p class="sihla-caja-nota">Es el efectivo que vas a contar al cerrar el turno.</p>
                </section>

                {{-- 3 · Lo cobrado que no se cuenta en la gaveta --}}
                <section class="sihla-caja-bloque" aria-labelledby="sihla-caja-fuera-titulo">
                    <h3 id="sihla-caja-fuera-titulo" class="sihla-caja-bloque-titulo">Fuera de la gaveta</h3>

                    <dl class="sihla-caja-fuera">
                        @foreach ($resumen['fueraDeLaGaveta'] as $linea)
                            <div class="sihla-caja-fuera-linea">
                                <dt>
                                    {{ $linea['etiqueta'] }}
                                    <span class="sihla-caja-nota">{{ $linea['nota'] }}</span>
                                </dt>
                                <dd>{{ $lempiras($linea['monto']) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="sihla-caja-cobrado">
                        <span>
                            Total cobrado en el turno
                            <span class="sihla-caja-nota">
                                {{ count($vigentes) }} {{ count($vigentes) === 1 ? 'recibo' : 'recibos' }}, todas las formas de pago
                            </span>
                        </span>
                        <strong>{{ $lempiras($resumen['cobrado']) }}</strong>
                    </div>
                </section>
            </div>

            {{-- 4 · Los recibos --}}
            <section class="sihla-caja-bloque sihla-caja-recibos" aria-labelledby="sihla-caja-recibos-titulo">
                <header class="sihla-caja-recibos-cabecera">
                    <h3 id="sihla-caja-recibos-titulo" class="sihla-caja-bloque-titulo">
                        Recibos del turno
                        <span class="sihla-caja-cuantos">{{ count($vigentes) }}</span>
                    </h3>

                    @if (count($anulados) > 0)
                        <x-filament::link
                            tag="button"
                            color="gray"
                            size="sm"
                            :icon="$this->verAnulados ? 'heroicon-o-eye-slash' : 'heroicon-o-eye'"
                            wire:click="mostrarAnulados({{ $this->verAnulados ? 'false' : 'true' }})"
                        >
                            {{ $this->verAnulados ? 'Ocultar los anulados' : 'Ver los anulados ('.count($anulados).')' }}
                        </x-filament::link>
                    @endif
                </header>

                @if (count($vigentes) === 0 && ! ($this->verAnulados && count($anulados) > 0))
                    <p class="sihla-caja-nada">
                        Todavía no hay recibos en este turno. Los abonos se reciben desde la cuenta del paciente,
                        en <a href="{{ \App\Filament\Pages\CuentasAbiertas::getUrl() }}">Cuentas abiertas</a>.
                    </p>
                @else
                    <div class="sihla-caja-tabla-ctn">
                        <table class="sihla-caja-tabla">
                            <thead>
                                <tr>
                                    <th>Hora</th>
                                    <th>Recibo</th>
                                    <th>Paciente</th>
                                    <th>Cómo pagó</th>
                                    <th class="sihla-caja-num">Monto</th>
                                    <th><span class="sr-only">Acciones</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                @if (count($vigentes) === 0)
                                    <tr>
                                        <td colspan="6" class="sihla-caja-sin-vigentes">No quedó ningún recibo vigente en este turno.</td>
                                    </tr>
                                @endif

                                @foreach ($vigentes as $abono)
                                    @include('filament.pages.partials.recibo-de-caja', ['abono' => $abono, 'anulado' => false])
                                @endforeach

                                @if ($this->verAnulados && count($anulados) > 0)
                                    <tr class="sihla-caja-separador">
                                        <td colspan="6">Anulados — no cuentan en la gaveta ni en el saldo de la cuenta</td>
                                    </tr>

                                    @foreach ($anulados as $abono)
                                        @include('filament.pages.partials.recibo-de-caja', ['abono' => $abono, 'anulado' => true])
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>

    <style>
        /* ── Fichas de color de la pantalla (claro y oscuro) ─────────── */
        .sihla-caja {
            --sc-fondo: rgb(255 255 255);
            --sc-suave: rgb(250 250 250);
            --sc-linea: rgb(228 228 231);
            --sc-linea-suave: rgb(244 244 245);
            --sc-texto: rgb(24 24 27);
            --sc-texto-2: rgb(63 63 70);
            --sc-tenue: rgb(113 113 122);
            --sc-ambar: rgb(180 83 9);
            --sc-ambar-fondo: rgb(255 251 235);
            --sc-ambar-borde: rgb(253 230 138);
            --sc-rojo: rgb(185 28 28);
            --sc-motivo-fondo: rgb(254 252 232);

            display: flex; flex-direction: column; gap: 1rem;
            color: var(--sc-texto);
        }
        .dark .sihla-caja {
            --sc-fondo: rgb(24 24 27);
            --sc-suave: rgb(39 39 42);
            --sc-linea: rgb(63 63 70);
            --sc-linea-suave: rgb(39 39 42);
            --sc-texto: rgb(244 244 245);
            --sc-texto-2: rgb(212 212 216);
            --sc-tenue: rgb(161 161 170);
            --sc-ambar: rgb(251 191 36);
            --sc-ambar-fondo: rgb(251 191 36 / .07);
            --sc-ambar-borde: rgb(251 191 36 / .35);
            --sc-rojo: rgb(248 113 113);
            --sc-motivo-fondo: rgb(251 191 36 / .06);
        }

        .sihla-caja-nota { display: block; font-size: .75rem; font-weight: 400; color: var(--sc-tenue); }

        /* ── 1 · El turno ────────────────────────────────────────────── */
        .sihla-caja-turno {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .sihla-caja-turno-nombre { font-size: 1.25rem; font-weight: 700; line-height: 1.3; }
        .sihla-caja-turno-detalle { font-size: .85rem; color: var(--sc-tenue); }

        .sihla-caja-aviso {
            display: flex; gap: .6rem; align-items: flex-start;
            padding: .7rem .9rem; border-radius: .6rem;
            border: 1px solid var(--sc-ambar-borde); background: var(--sc-ambar-fondo);
            font-size: .85rem; color: var(--sc-texto-2);
        }
        .sihla-caja-aviso-icono { width: 1.15rem; height: 1.15rem; flex-shrink: 0; margin-top: .1rem; color: var(--sc-ambar); }

        /* ── Bloques ─────────────────────────────────────────────────── */
        .sihla-caja-cuadre { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 56rem) {
            .sihla-caja-cuadre { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); }
        }

        .sihla-caja-bloque {
            padding: 1.1rem 1.25rem; border-radius: .75rem;
            border: 1px solid var(--sc-linea); background: var(--sc-fondo);
        }
        .sihla-caja-bloque-titulo { font-size: .95rem; font-weight: 600; margin-bottom: .6rem; }

        /* ── 2 · La gaveta: una suma que se lee como en papel ────────── */
        .sihla-caja-gaveta { border-color: var(--sc-ambar-borde); }

        .sihla-caja-cinta { font-variant-numeric: tabular-nums; }
        .sihla-caja-cinta-linea {
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            padding: .5rem 0; border-bottom: 1px dashed var(--sc-linea);
            font-size: .95rem; color: var(--sc-texto-2);
        }
        .sihla-caja-cinta-linea dd { font-weight: 600; white-space: nowrap; color: var(--sc-texto); }
        .sihla-caja-signo {
            display: inline-block; width: 1.25rem;
            font-weight: 700; color: var(--sc-tenue);
        }
        .sihla-caja-cinta-total {
            margin-top: .35rem; padding-top: .75rem; border-bottom: none;
            border-top: 2px solid var(--sc-texto-2);
            align-items: center;
        }
        .sihla-caja-cinta-total dt { font-weight: 600; color: var(--sc-texto); }
        .sihla-caja-cinta-total dd { font-size: 2rem; font-weight: 700; line-height: 1.1; color: var(--sc-ambar); }
        .sihla-caja-gaveta > .sihla-caja-nota { margin-top: .5rem; }

        /* ── 3 · Fuera de la gaveta ──────────────────────────────────── */
        .sihla-caja-fuera { font-variant-numeric: tabular-nums; }
        .sihla-caja-fuera-linea {
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            padding: .5rem 0; border-bottom: 1px solid var(--sc-linea-suave);
            font-size: .9rem; color: var(--sc-texto-2);
        }
        .sihla-caja-fuera-linea dd { font-weight: 600; white-space: nowrap; color: var(--sc-texto); }

        .sihla-caja-cobrado {
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            margin-top: .75rem; padding: .7rem .85rem; border-radius: .5rem;
            background: var(--sc-suave); font-size: .9rem; font-variant-numeric: tabular-nums;
        }
        .sihla-caja-cobrado strong { font-size: 1.15rem; white-space: nowrap; }

        /* ── 4 · Recibos ─────────────────────────────────────────────── */
        .sihla-caja-recibos { padding: 0; overflow: hidden; }
        .sihla-caja-recibos-cabecera {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            padding: .9rem 1.25rem;
        }
        .sihla-caja-recibos-cabecera .sihla-caja-bloque-titulo { margin-bottom: 0; }
        .sihla-caja-cuantos {
            display: inline-block; min-width: 1.5rem; margin-left: .35rem; padding: 0 .45rem;
            border-radius: 999px; background: var(--sc-suave);
            font-size: .75rem; font-weight: 600; text-align: center; color: var(--sc-tenue);
        }

        .sihla-caja-nada { padding: 0 1.25rem 1.25rem; font-size: .9rem; color: var(--sc-tenue); }
        .sihla-caja-nada a { text-decoration: underline; color: var(--sc-ambar); }

        .sihla-caja-tabla-ctn { overflow-x: auto; }
        .sihla-caja-tabla { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .sihla-caja-tabla > thead > tr > th {
            padding: .55rem 1rem; text-align: start; white-space: nowrap;
            font-size: .75rem; font-weight: 600; color: var(--sc-tenue);
            background: var(--sc-suave);
            border-top: 1px solid var(--sc-linea); border-bottom: 1px solid var(--sc-linea);
        }
        .sihla-caja-tabla > tbody > tr > td {
            padding: .7rem 1rem; vertical-align: top;
            border-bottom: 1px solid var(--sc-linea-suave);
        }
        .sihla-caja-tabla > tbody > tr:last-child > td { border-bottom: none; }
        .sihla-caja-tabla .sihla-caja-num { text-align: end; }

        .sihla-caja-hora { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; }
        .sihla-caja-hora span { display: block; font-size: .75rem; font-weight: 400; color: var(--sc-tenue); }
        .sihla-caja-recibo { white-space: nowrap; color: var(--sc-texto-2); font-variant-numeric: tabular-nums; }
        .sihla-caja-paciente-nombre { display: block; font-weight: 600; }
        .sihla-caja-sub { display: block; font-size: .75rem; color: var(--sc-tenue); }
        .sihla-caja-motivo-anulado { display: block; margin-top: .15rem; font-size: .75rem; color: var(--sc-rojo); }
        .sihla-caja-medios span { display: block; color: var(--sc-texto-2); }
        .sihla-caja-medios em { font-style: normal; color: var(--sc-tenue); font-variant-numeric: tabular-nums; }
        .sihla-caja-monto { font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sihla-caja-acc { text-align: end; white-space: nowrap; }

        .sihla-caja-sin-vigentes { color: var(--sc-tenue); }

        .sihla-caja-tabla > tbody > tr.sihla-caja-separador > td {
            padding: .45rem 1rem;
            font-size: .75rem; font-weight: 600; color: var(--sc-tenue);
            background: var(--sc-suave);
        }
        .sihla-caja-fila-anulada > td { color: var(--sc-tenue); }
        .sihla-caja-fila-anulada .sihla-caja-paciente-nombre,
        .sihla-caja-fila-anulada .sihla-caja-medios span { color: var(--sc-tenue); }
        .sihla-caja-fila-anulada .sihla-caja-monto { text-decoration: line-through; font-weight: 500; }

        /* La fila del motivo se lee como parte del recibo que se anula. */
        .sihla-caja-fila-motivo > td { background: var(--sc-motivo-fondo); }
        .sihla-caja-fila-motivo label { display: block; margin-bottom: .45rem; font-size: .85rem; font-weight: 600; }
        .sihla-caja-motivo-fila { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .sihla-caja-motivo-campo { flex: 1 1 16rem; }
        .sihla-caja-fila-motivo .sihla-caja-nota { margin-top: .45rem; }

        /*
         * En el celular la tabla de seis columnas no cabe: cada recibo se
         * vuelve una ficha — paciente y monto arriba, cómo pagó y el botón
         * en medio, hora y número de recibo abajo, en chico.
         */
        @media (max-width: 40rem) {
            .sihla-caja-cinta-total dd { font-size: 1.6rem; }

            .sihla-caja-tabla > thead { display: none; }
            .sihla-caja-tabla,
            .sihla-caja-tabla > tbody { display: block; }
            .sihla-caja-tabla > tbody > tr {
                display: grid; gap: .15rem .75rem;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "paciente monto"
                    "medios   acc"
                    "hora     acc"
                    "recibo   acc";
                padding: .8rem 1rem; border-bottom: 1px solid var(--sc-linea-suave);
            }
            .sihla-caja-tabla > tbody > tr:last-child { border-bottom: none; }
            .sihla-caja-tabla > tbody > tr > td { display: block; padding: 0; border-bottom: none; }
            .sihla-caja-tabla > tbody > tr > td[colspan] { grid-column: 1 / -1; }
            .sihla-caja-tabla > tbody > tr.sihla-caja-separador > td { padding: 0; background: transparent; }
            .sihla-caja-tabla > tbody > tr.sihla-caja-separador { background: var(--sc-suave); padding: .45rem 1rem; }
            .sihla-caja-tabla > tbody > tr.sihla-caja-fila-motivo { background: var(--sc-motivo-fondo); }

            .sihla-caja-paciente { grid-area: paciente; }
            .sihla-caja-monto { grid-area: monto; }
            .sihla-caja-medios { grid-area: medios; font-size: .8rem; }
            .sihla-caja-acc { grid-area: acc; align-self: center; }
            .sihla-caja-hora { grid-area: hora; font-size: .75rem; font-weight: 400; color: var(--sc-tenue); }
            .sihla-caja-hora span { display: inline; margin-left: .3rem; }
            .sihla-caja-recibo { grid-area: recibo; font-size: .75rem; color: var(--sc-tenue); }
        }
    </style>
</x-filament-panels::page>
