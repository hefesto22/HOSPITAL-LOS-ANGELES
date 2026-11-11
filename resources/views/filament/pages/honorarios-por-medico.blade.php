{{--
    Honorarios por médico — tercer rediseño, 11-nov-2026.

    Cuenta SOLO lo facturado en el período (ver `ReporteDeHonorarios`):
    sin negativos y sin mezclar un mes con otro.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ TARJETAS (Mauricio, 11-nov-2026)
    ─────────────────────────────────────────────────────────────────────

    «Cuando sean 400 honorarios se verá feo uno encima de otro … sería
    bueno que los médicos se vean en cards así como las cuentas.» Con la
    lista del hospital son noventa médicos, y cuatrocientos renglones al
    mes debajo de la tabla de médicos no se podían leer. Ahora:

    1. La barra del período, igual que antes.
    2. Sin médico elegido: el total del período con el botón del reporte
       general, y una tarjeta por médico —cuánto lleva, cuántos
       honorarios, qué parte del período— con un buscador que filtra en
       el navegador y el orden A–Z o de mayor a menor. Los renglones de
       todos solo si se piden: los usa auditoría para buscar un paciente.
    3. Con un médico elegido: su ficha —lo que le toca, de qué pagadores
       y el botón de su reporte impreso— y sus renglones.

    ENFERMERÍA Y PERSONAL (Mauricio, 11-nov-2026): los honorarios de los
    turnos de enfermería, el circulante y el instrumentista no son de nadie
    registrado. Van en su propio grupo de tarjetas, una por turno o área
    —por ítem—, con su ficha y su reporte impreso igual que un médico. Lo
    que se cobró como honorario de médico sin elegir al médico sale en un
    aviso aparte: no es de nadie, pero está en el total.

    ⚠️ El buscador de médicos NO va al servidor: son a lo sumo un centenar
    de tarjetas y cada una lleva su clave (nombre y especialidad sin
    tildes, en minúsculas) en `data-clave`. «pena» encuentra a PEÑA.

    ⚠️ Sin `transform` en el hover de la tarjeta (§9.M29) y sin
    `wire:loading` general (§9.M31): la tarjeta que se tocó se enciende
    con el `data-loading` que Livewire 4 le pone al botón de origen.

    ⚠️ `wire:model.live.change` en las fechas, no `.live`: con `.live`
    cada tecla manda la fecha a medio escribir, que llega vacía y la
    página la reemplaza por el inicio del mes mientras se teclea.

    Los estilos de la barra y el panel viven en
    `filament.partials.estilos-de-reporte`; los de las tarjetas, abajo.
--}}
<x-filament-panels::page>
    @php
        $ficha = $this->ficha();
        $resumen = $this->resumen();
        $sePuedeImprimir = $this->sePuedeImprimir();
        $porQueNo = 'El reporte impreso cubre como máximo un año: elegí un período más corto.';
    @endphp

    <div class="sh">
        {{-- 1. El período --}}
        <div class="sh-barra">
            <div class="sh-seg" role="group" aria-label="Período">
                @foreach ($this->periodos() as $atajo)
                    <button
                        type="button"
                        wire:key="atajo-{{ $atajo['clave'] }}"
                        wire:click="usarPeriodo('{{ $atajo['clave'] }}')"
                        title="{{ $atajo['rango'] }}"
                        @class(['sh-seg-boton', 'sh-seg-activo' => $atajo['activo']])
                        @if ($atajo['activo']) aria-pressed="true" @endif
                    >{{ $atajo['etiqueta'] }}</button>
                @endforeach
            </div>

            <div class="sh-rango">
                <input type="date" wire:model.live.change="desde" aria-label="Desde">
                <span class="sh-rango-flecha" aria-hidden="true">→</span>
                <input type="date" wire:model.live.change="hasta" aria-label="Hasta">
            </div>
        </div>

        @if ($ficha)
            {{-- 3. Un médico, un turno o los «sin médico» --}}
            <div class="sh-panel" wire:key="ficha-{{ $ficha['tipo'] }}-{{ $ficha['id'] ?? 'x' }}">
                <div class="hm-ficha-cabeza">
                    @if ($ficha['tipo'] === 'medico')
                        <span class="hm-avatar hm-avatar-grande" aria-hidden="true">{{ $ficha['iniciales'] }}</span>
                    @else
                        <span @class([
                            'hm-avatar',
                            'hm-avatar-grande',
                            'hm-avatar-personal' => $ficha['tipo'] === 'personal',
                            'hm-avatar-aviso' => $ficha['tipo'] === 'sin_medico',
                        ]) aria-hidden="true">
                            <x-filament::icon
                                :icon="$ficha['tipo'] === 'personal' ? 'heroicon-m-user-group' : 'heroicon-m-exclamation-triangle'"
                                class="hm-avatar-icono"
                            />
                        </span>
                    @endif

                    <div class="hm-ficha-quien">
                        <h2 class="hm-ficha-nombre">{{ $ficha['nombre'] }}</h2>
                        <p class="hm-esp hm-esp-largo">{{ $ficha['subtitulo'] }} · {{ $this->periodo() }}</p>
                    </div>

                    <div class="hm-botones">
                        <button type="button" wire:click="verTodos" class="sh-todos">
                            <x-filament::icon icon="heroicon-m-arrow-left" class="sh-todos-icono" />
                            Todos
                        </button>

                        @if ($ficha['tipo'] !== 'sin_medico')
                            @if ($ficha['enlace'] !== null)
                                <button
                                    type="button"
                                    wire:click="mountAction('imprimirReporte', { {{ $ficha['tipo'] === 'medico' ? 'medico' : 'turno' }}: {{ (int) $ficha['id'] }} })"
                                    wire:loading.attr="disabled"
                                    wire:target="mountAction"
                                    class="hm-imprimir"
                                >
                                    <x-filament::icon icon="heroicon-m-printer" class="sh-todos-icono" />
                                    {{ $ficha['tipo'] === 'medico' ? 'Imprimir su reporte' : 'Imprimir el reporte del turno' }}
                                </button>
                            @else
                                <span class="hm-imprimir" data-apagado title="{{ $porQueNo }}">
                                    <x-filament::icon icon="heroicon-m-printer" class="sh-todos-icono" />
                                    Imprimir
                                </span>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="hm-ficha-cuerpo">
                    <div class="hm-ficha-total">
                        <span class="sh-etiqueta">
                            {{ match ($ficha['tipo']) {
                                'medico'   => 'Le toca en el período',
                                'personal' => 'Le toca al turno en el período',
                                default    => 'Facturado sin médico',
                            } }}
                        </span>
                        <span class="sh-valor">{{ $ficha['total'] }}</span>
                        <span class="sh-nota">
                            {{ $ficha['renglones'] }} {{ $ficha['renglones'] === 1 ? 'honorario facturado' : 'honorarios facturados' }}
                            · {{ $ficha['parte'] }} del período
                        </span>
                    </div>

                    @if ($ficha['pagadores'] !== [])
                        <div class="hm-pagadores">
                            <span class="sh-etiqueta">Por pagador</span>

                            @foreach ($ficha['pagadores'] as $pagador)
                                <div class="hm-pagador" wire:key="pagador-{{ $loop->index }}">
                                    <span class="hm-pagador-nombre">{{ $pagador['nombre'] }}</span>
                                    <span class="hm-pagador-cuantos">
                                        {{ $pagador['cuantos'] }} {{ $pagador['cuantos'] === 1 ? 'honorario' : 'honorarios' }}
                                    </span>
                                    <span class="hm-pagador-total">{{ $pagador['total'] }}</span>
                                    <span class="hm-pagador-parte">{{ $pagador['parte'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($ficha['tipo'] === 'sin_medico')
                        <p class="hm-explica">
                            Son honorarios de médico que se cobraron sin elegir al médico, antes de que fuera
                            obligatorio. Ya facturados no se pueden cambiar: quedan acá para que el total cuadre y
                            para revisarlos. De ahora en adelante la cuenta no deja cargar uno sin médico.
                        </p>
                    @endif
                </div>
            </div>

            <div class="sh-detalle">
                <div class="sh-detalle-cabeza">
                    <h3 class="sh-detalle-titulo">
                        {{ match ($ficha['tipo']) {
                            'medico'   => 'Sus honorarios',
                            'personal' => 'Los honorarios del turno',
                            default    => 'Los renglones sin médico',
                        } }}
                    </h3>
                </div>

                {{ $this->table }}
            </div>
        @else
            @php
                $medicos = $this->medicos();
                $personal = $this->personal();
                $sinMedico = $this->sinMedicoDelPeriodo();
                $hayTarjetas = count($medicos) + count($personal) > 0;
            @endphp

            {{-- 2. Todos --}}
            <div class="sh-panel">
                <div class="hm-resumen">
                    <div class="hm-cifra hm-cifra-grande">
                        <span class="sh-etiqueta">Total facturado · {{ $this->periodo() }}</span>
                        <span class="sh-valor">{{ $resumen['total'] }}</span>
                        <span class="sh-nota">
                            {{ $resumen['renglones'] }} {{ $resumen['renglones'] === 1 ? 'honorario' : 'honorarios' }}
                        </span>
                    </div>
                    <div class="hm-cifra">
                        <span class="sh-etiqueta">
                            {{ $resumen['medicos'] }} {{ $resumen['medicos'] === 1 ? 'médico' : 'médicos' }}
                        </span>
                        <span class="hm-cifra-valor">{{ $resumen['totalMedicos'] }}</span>
                    </div>
                    <div class="hm-cifra">
                        <span class="sh-etiqueta">Enfermería y personal</span>
                        <span class="hm-cifra-valor">{{ $resumen['totalPersonal'] }}</span>
                    </div>

                    <div class="hm-botones hm-resumen-botones">
                        @if (! $sePuedeImprimir)
                            <span class="hm-imprimir" data-apagado title="{{ $porQueNo }}">
                                <x-filament::icon icon="heroicon-m-printer" class="sh-todos-icono" />
                                Imprimir reporte general
                            </span>
                        @elseif ($hayTarjetas || $sinMedico)
                            <button
                                type="button"
                                wire:click="mountAction('imprimirReporte')"
                                wire:loading.attr="disabled"
                                wire:target="mountAction"
                                class="hm-imprimir"
                            >
                                <x-filament::icon icon="heroicon-m-printer" class="sh-todos-icono" />
                                Imprimir reporte general
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            @if ($sinMedico)
                <div class="sh-aviso">
                    <span class="sh-aviso-signo" aria-hidden="true">!</span>
                    <span class="sh-aviso-texto">
                        <strong>
                            {{ $sinMedico['renglones'] }}
                            {{ $sinMedico['renglones'] === 1 ? 'honorario de médico se cobró' : 'honorarios de médico se cobraron' }}
                            sin elegir al médico · {{ $sinMedico['total'] }}.
                        </strong>
                        No entran en la liquidación de nadie. Están en el total para que cuadre.
                    </span>
                    <button type="button" wire:click="verSinMedico" class="sh-aviso-enlace hm-enlace">Ver cuáles</button>
                </div>
            @endif

            @if (! $hayTarjetas)
                @unless ($sinMedico)
                    <div class="sh-aviso">
                        <span class="sh-aviso-signo" aria-hidden="true">·</span>
                        <span class="sh-aviso-texto">
                            <strong>Sin honorarios facturados en {{ $this->periodo() }}.</strong>
                            Solo cuentan los honorarios de facturas emitidas en estas fechas.
                        </span>
                    </div>
                @endunless
            @else
                <div
                    class="hm-lista"
                    x-data="{
                        q: '',
                        norma(texto) {
                            return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim()
                        },
                        ve(clave) {
                            const q = this.norma(this.q)

                            return q === '' || clave.includes(q)
                        },
                        get vacio() {
                            return this.norma(this.q) !== ''
                                && [...this.$root.querySelectorAll('[data-clave]')].every((tarjeta) => ! this.ve(tarjeta.dataset.clave))
                        },
                    }"
                >
                    <div class="hm-herramientas">
                        <label class="hm-buscar">
                            <x-filament::icon icon="heroicon-m-magnifying-glass" class="hm-buscar-icono" />
                            <input
                                type="search"
                                x-model="q"
                                x-on:keydown.escape="q = ''"
                                placeholder="Buscar médico, especialidad o turno"
                                aria-label="Buscar médico, especialidad o turno"
                                autocomplete="off"
                            >
                        </label>

                        <select wire:model.live="orden" class="hm-orden" aria-label="Orden">
                            <option value="nombre">De la A a la Z</option>
                            <option value="total">Lo que más lleva primero</option>
                        </select>

                        <button type="button" wire:click="alternarRenglones" class="sh-todos">
                            <x-filament::icon
                                :icon="$verRenglones ? 'heroicon-m-eye-slash' : 'heroicon-m-list-bullet'"
                                class="sh-todos-icono"
                            />
                            {{ $verRenglones ? 'Ocultar los renglones' : 'Ver todos los renglones' }}
                        </button>
                    </div>

                    @if ($medicos !== [])
                        <h3 class="hm-seccion">
                            Médicos
                            <span class="hm-seccion-cuenta">{{ count($medicos) }}</span>
                        </h3>

                        <div class="hm-tarjetas">
                            @foreach ($medicos as $tarjeta)
                                <button
                                    type="button"
                                    wire:key="medico-{{ $tarjeta['id'] }}"
                                    wire:click="verSoloEste({{ $tarjeta['id'] }})"
                                    data-clave="{{ $tarjeta['clave'] }}"
                                    x-show="ve($el.dataset.clave)"
                                    class="hm-tarjeta"
                                    title="Ver los honorarios de {{ $tarjeta['nombre'] }}"
                                >
                                    <span class="hm-arriba">
                                        <span class="hm-avatar" aria-hidden="true">{{ $tarjeta['iniciales'] }}</span>
                                        <span class="hm-quien">
                                            <span class="hm-nombre">{{ $tarjeta['nombre'] }}</span>
                                            <span class="hm-esp">{{ $tarjeta['especialidad'] !== '' ? $tarjeta['especialidad'] : 'Sin especialidad' }}</span>
                                        </span>
                                        <span class="hm-girando" aria-hidden="true"></span>
                                    </span>

                                    <span class="hm-monto">{{ $tarjeta['total'] }}</span>

                                    <span class="hm-barra" aria-hidden="true">
                                        <span style="width: {{ $tarjeta['barra'] }}%"></span>
                                    </span>

                                    <span class="hm-pie">
                                        <span>{{ $tarjeta['renglones'] }} {{ $tarjeta['renglones'] === 1 ? 'honorario' : 'honorarios' }}</span>
                                        <span>{{ $tarjeta['parte'] }} del período</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if ($personal !== [])
                        <h3 class="hm-seccion">
                            Enfermería y personal
                            <span class="hm-seccion-cuenta">{{ count($personal) }}</span>
                            <span class="hm-seccion-nota">por turno o área, sin personas registradas</span>
                        </h3>

                        <div class="hm-tarjetas">
                            @foreach ($personal as $tarjeta)
                                <button
                                    type="button"
                                    wire:key="turno-{{ $tarjeta['id'] }}"
                                    wire:click="verTurno({{ $tarjeta['id'] }})"
                                    data-clave="{{ $tarjeta['clave'] }}"
                                    x-show="ve($el.dataset.clave)"
                                    class="hm-tarjeta"
                                    title="Ver los honorarios de {{ $tarjeta['nombre'] }}"
                                >
                                    <span class="hm-arriba">
                                        <span class="hm-avatar hm-avatar-personal" aria-hidden="true">
                                            <x-filament::icon icon="heroicon-m-user-group" class="hm-avatar-icono" />
                                        </span>
                                        <span class="hm-quien">
                                            <span class="hm-nombre">{{ $tarjeta['nombre'] }}</span>
                                            <span class="hm-esp">Turno o área</span>
                                        </span>
                                        <span class="hm-girando" aria-hidden="true"></span>
                                    </span>

                                    <span class="hm-monto">{{ $tarjeta['total'] }}</span>

                                    <span class="hm-barra" aria-hidden="true">
                                        <span style="width: {{ $tarjeta['barra'] }}%"></span>
                                    </span>

                                    <span class="hm-pie">
                                        <span>{{ $tarjeta['renglones'] }} {{ $tarjeta['renglones'] === 1 ? 'honorario' : 'honorarios' }}</span>
                                        <span>{{ $tarjeta['parte'] }} del período</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <p x-show="vacio" x-cloak class="hm-sin">
                        Nada coincide con «<span x-text="q"></span>».
                    </p>
                </div>
            @endif

            @if ($verRenglones && ($hayTarjetas || $sinMedico))
                <div class="sh-detalle">
                    <div class="sh-detalle-cabeza">
                        <h3 class="sh-detalle-titulo">Todos los honorarios del período</h3>
                    </div>

                    {{ $this->table }}
                </div>
            @endif
        @endif
    </div>

    @include('filament.partials.estilos-de-reporte')

    <style>
        /* ── El total del período, con el botón del reporte general ── */
        .hm-resumen {
            display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem 2.25rem;
            padding: 1.25rem 1.5rem;
        }
        .hm-cifra { display: flex; flex-direction: column; gap: .2rem; }
        .hm-cifra-valor {
            font-size: 1.375rem; font-weight: 600; line-height: 1.2;
            font-variant-numeric: tabular-nums; color: var(--sh-texto);
        }
        .hm-resumen-botones { margin-left: auto; }

        /* ── Los botones ── */
        .hm-botones { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .hm-imprimir {
            display: inline-flex; align-items: center; gap: .35rem;
            border: 0; font-family: inherit; line-height: 1.25;
            padding: .45rem .9rem; border-radius: .5rem; cursor: pointer;
            font-size: .8125rem; font-weight: 600; text-decoration: none;
            color: rgb(255 255 255); background: var(--sh-acento);
            transition: filter .12s ease;
        }
        .dark .hm-imprimir { color: rgb(24 24 27); }
        .hm-imprimir:hover { filter: brightness(1.06); }
        .hm-imprimir:focus-visible { outline: 2px solid var(--sh-acento); outline-offset: 2px; }
        .hm-imprimir[data-apagado] { opacity: .45; cursor: not-allowed; filter: none; }
        .hm-imprimir:disabled { opacity: .65; cursor: progress; filter: none; }

        /* ── Las herramientas: buscar, ordenar, ver renglones ── */
        .hm-lista { display: flex; flex-direction: column; gap: .875rem; }
        .hm-herramientas { display: flex; flex-wrap: wrap; align-items: center; gap: .625rem; }
        .hm-buscar {
            position: relative; display: flex; align-items: center; flex: 1 1 16rem; max-width: 30rem;
        }
        .hm-buscar-icono {
            position: absolute; left: .75rem; width: 1.05rem; height: 1.05rem;
            color: var(--sh-muy-tenue); pointer-events: none;
        }
        .hm-buscar input, .hm-orden {
            height: 2.375rem; border: 0; border-radius: .625rem;
            font-size: .875rem; color: var(--sh-texto); background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .hm-buscar input { width: 100%; padding: 0 .9rem 0 2.4rem; }
        .hm-orden { padding: 0 2rem 0 .75rem; cursor: pointer; }
        .dark .hm-orden { color-scheme: dark; }
        .hm-buscar input:focus, .hm-orden:focus { outline: 2px solid var(--sh-acento); outline-offset: 0; }

        /* ── Las tarjetas ── */
        .hm-tarjetas {
            display: grid; gap: .875rem; grid-auto-rows: 1fr;
            grid-template-columns: repeat(auto-fill, minmax(15.5rem, 1fr));
        }
        .hm-tarjeta {
            position: relative; display: flex; flex-direction: column; gap: .5rem;
            width: 100%; padding: .9rem 1rem .8rem; border: 0; border-radius: .75rem;
            text-align: left; cursor: pointer; color: inherit;
            background: var(--sh-fondo); box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
            transition: box-shadow .15s ease;
        }
        .hm-tarjeta:hover { box-shadow: 0 6px 18px -8px rgb(0 0 0 / .25), 0 0 0 1px var(--sh-muy-tenue); }
        .hm-tarjeta:focus-visible { outline: 2px solid var(--sh-acento); outline-offset: 2px; }
        .hm-tarjeta[data-loading] { box-shadow: 0 0 0 2px var(--sh-acento); cursor: progress; }

        .hm-arriba { display: flex; align-items: center; gap: .7rem; min-width: 0; }
        .hm-avatar {
            display: inline-flex; align-items: center; justify-content: center; flex: none;
            width: 2.25rem; height: 2.25rem; border-radius: 999px;
            font-size: .75rem; font-weight: 700; letter-spacing: .02em;
            background: var(--sh-acento-fondo); color: var(--sh-acento);
        }
        .hm-avatar-grande { width: 3rem; height: 3rem; font-size: 1rem; }
        .hm-quien { display: flex; flex-direction: column; min-width: 0; }
        .hm-nombre {
            font-size: .875rem; font-weight: 600; line-height: 1.25; color: var(--sh-texto);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .hm-esp {
            margin: 0; font-size: .75rem; color: var(--sh-tenue);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .hm-monto {
            margin-top: auto; padding-top: .25rem;
            font-size: 1.25rem; font-weight: 700; line-height: 1.2; letter-spacing: -.01em;
            font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--sh-texto);
        }
        .hm-barra { display: block; height: .3rem; border-radius: 999px; background: var(--sh-hover); overflow: hidden; }
        .dark .hm-barra { background: rgb(255 255 255 / .06); }
        .hm-barra > span { display: block; height: 100%; border-radius: 999px; background: var(--sh-acento); }
        .hm-pie {
            display: flex; justify-content: space-between; gap: .5rem;
            font-size: .75rem; color: var(--sh-tenue); font-variant-numeric: tabular-nums;
        }

        .hm-girando {
            display: none; flex: none; margin-left: auto; width: .95rem; height: .95rem; border-radius: 999px;
            border: 2px solid var(--sh-acento); border-top-color: transparent;
            animation: hm-girar .7s linear infinite;
        }
        .hm-tarjeta[data-loading] .hm-girando { display: inline-block; }
        @keyframes hm-girar { to { transform: rotate(360deg); } }

        .hm-sin { margin: 0; padding: .75rem 0; font-size: .875rem; color: var(--sh-tenue); }

        /* ── Los dos grupos: médicos y personal ── */
        .hm-seccion {
            display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .5rem;
            margin: .5rem 0 0; font-size: .9375rem; font-weight: 600; color: var(--sh-texto);
        }
        .hm-seccion-cuenta {
            padding: 0 .45rem; border-radius: 999px; font-size: .75rem; font-weight: 600;
            font-variant-numeric: tabular-nums; background: var(--sh-hover); color: var(--sh-tenue);
        }
        .dark .hm-seccion-cuenta { background: rgb(255 255 255 / .06); }
        .hm-seccion-nota { font-size: .75rem; font-weight: 400; color: var(--sh-tenue); }

        .hm-avatar-icono { width: 1.1rem; height: 1.1rem; }
        .hm-avatar-grande .hm-avatar-icono { width: 1.4rem; height: 1.4rem; }
        .hm-avatar-personal { background: rgb(14 165 233 / .12); color: rgb(2 132 199); }
        .dark .hm-avatar-personal { background: rgb(56 189 248 / .12); color: rgb(125 211 252); }
        .hm-avatar-aviso { background: rgb(254 243 199); color: rgb(180 83 9); }
        .dark .hm-avatar-aviso { background: rgb(251 191 36 / .12); color: rgb(253 230 138); }

        .hm-esp-largo { white-space: normal; }
        .hm-explica { margin: 0; font-size: .8125rem; line-height: 1.5; color: var(--sh-tenue); }
        .hm-enlace { padding: 0; border: 0; background: transparent; cursor: pointer; }

        /* ── La ficha del médico elegido ── */
        .hm-ficha-cabeza {
            display: flex; flex-wrap: wrap; align-items: center; gap: .875rem 1rem;
            padding: 1.125rem 1.5rem; border-bottom: 1px solid var(--sh-linea);
        }
        .hm-ficha-quien { flex: 1 1 14rem; min-width: 0; }
        .hm-ficha-nombre { margin: 0; font-size: 1.125rem; font-weight: 600; line-height: 1.3; color: var(--sh-texto); }
        .hm-ficha-cuerpo {
            display: grid; gap: 1.25rem 2rem; padding: 1.25rem 1.5rem;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr);
        }
        .hm-ficha-total { display: flex; flex-direction: column; gap: .2rem; }
        .hm-pagadores { display: flex; flex-direction: column; gap: .1rem; }
        .hm-pagadores .sh-etiqueta { margin-bottom: .3rem; }
        .hm-pagador {
            display: grid; grid-template-columns: minmax(0, 1fr) auto auto 3rem; align-items: baseline; gap: .875rem;
            padding: .45rem 0; border-top: 1px solid var(--sh-linea); font-size: .875rem;
        }
        .hm-pagador-nombre { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--sh-texto); }
        .hm-pagador-cuantos { font-size: .75rem; color: var(--sh-tenue); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .hm-pagador-total { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--sh-texto); }
        .hm-pagador-parte { text-align: right; font-size: .75rem; color: var(--sh-tenue); font-variant-numeric: tabular-nums; }

        @media (max-width: 48rem) {
            .hm-ficha-cuerpo { grid-template-columns: 1fr; }
            .hm-resumen-botones { margin-left: 0; }
        }
        @media (max-width: 30rem) {
            .hm-resumen, .hm-ficha-cabeza, .hm-ficha-cuerpo { padding-left: 1rem; padding-right: 1rem; }
            .hm-tarjetas { grid-template-columns: 1fr; }
            .hm-herramientas > * { flex: 1 1 100%; max-width: none; }
        }
    </style>

    {{-- El modal del reporte impreso. Si la tabla está a la vista ya lo dibujó ella y este no se repite. --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
