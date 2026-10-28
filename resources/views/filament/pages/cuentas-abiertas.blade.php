{{--
    Las cuentas abiertas del hospital.

    Tarjetas y no tabla: lo único que hay que poder leer a un metro de
    distancia es de quién es la cuenta, desde cuándo está abierta y
    cuánto lleva. Todo lo demás está adentro.

    COMPACTAS (Mauricio, 22-oct-2026: «no me termina de gustar»). Eran
    tan altas que entraban dos filas, con un hueco en el medio y cuatro
    botones apilados. Ahora: el nombre, una línea con las insignias y
    hace cuánto entró, el expediente, el pagador y el total; abajo UN
    renglón con lo que esa persona usa todo el día y «⋯» para el resto
    —el número de cuenta, la fecha exacta de ingreso, Anular y lo
    clínico que no es de todos los días—.

    El CSS de Filament viene precompilado, así que las clases de Tailwind
    que no usa el panel NO existen acá (§9.A7). Por eso lo que se ve
    abajo se apoya en clases que el panel ya trae y en un `<style>`
    propio para lo que no.
--}}
<x-filament-panels::page>
    <div class="sihla-cuentas">

        <div class="sihla-barra">
            <label class="sihla-buscador">
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    class="sihla-buscador-icono"
                />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="busqueda"
                    placeholder="Buscar por nombre, apellido, expediente o número de cuenta…"
                    class="sihla-buscador-campo"
                    autocomplete="off"
                />
            </label>

            {{--
                El turno vive en el ENCABEZADO de la página, arriba a la
                derecha: es el estado de quien cobra, no una acción sobre
                las cuentas. Acá abajo competía con el botón que sí se
                usa todo el día.
            --}}
            {{ $this->abrirCuentaAction }}
        </div>

        @php($cuentas = $this->cuentas())
        @php($puedeCargar = $this->puedeCargar())
        @php($botones = $this->botonesDeLaTarjeta())
        @php($diagnosticoAfuera = $botones['clinicos'] && ! $botones['abonar'] && ! $botones['facturar'])
        @php($diagnosticos = $this->diagnosticosPorEncuentro($cuentas))
        @php($coinciden = $this->cuantasCoinciden($cuentas->count()))
        @php($porQueNoAbona = $this->porQueNoAbona())
        @php($porQueNoFactura = $this->porQueNoFactura())

        @if ($cuentas->isEmpty())
            <div class="sihla-vacio">
                <x-filament::icon icon="heroicon-o-inbox" class="sihla-vacio-icono" />

                <p class="sihla-vacio-titulo">
                    @if (filled($this->busqueda))
                        Ninguna cuenta abierta coincide con «{{ $this->busqueda }}».
                    @else
                        No hay ninguna cuenta abierta.
                    @endif
                </p>

                <p class="sihla-vacio-texto">
                    @if (filled($this->busqueda))
                        Probá con el apellido o con el número de expediente.
                    @else
                        Cuando ingrese un paciente, abrile la cuenta acá arriba. Todo lo que
                        se le vaya haciendo —medicamentos, estudios, estancia— se acumula
                        en ella hasta el egreso.
                    @endif
                </p>
            </div>
        @else
            {{--
                La grilla corta en `tarjetas_por_pantalla`. Lo que no entra
                no puede desaparecer callado: es un paciente internado cuya
                cuenta nadie encuentra (Mauricio, 22-oct-2026).
            --}}
            @if ($coinciden > $cuentas->count())
                <div class="sihla-aviso-tope" role="status">
                    <x-filament::icon icon="heroicon-m-information-circle" class="sihla-aviso-tope-icono" />
                    <span>
                        Se muestran las {{ $cuentas->count() }} más recientes de {{ $coinciden }}.
                        Buscá por nombre, apellido o expediente para encontrar las demás.
                    </span>
                </div>
            @endif

            <div class="sihla-tarjetas">
                @foreach ($cuentas as $cuenta)
                    @php($resumen = $this->resumenDe($cuenta))
                    @php($anula = $this->puedeAnular($cuenta))
                    @php($cuantosDiagnosticos = $diagnosticos[$cuenta->encuentro_id] ?? 0)

                    {{--
                        Quien no puede cargar —auditoría— ve la misma tarjeta
                        pero inerte. El permiso de verdad se verifica dentro
                        de la acción con `abort_unless`: esto solo evita que
                        alguien apriete algo que iba a terminar en un 403.
                    --}}
                    <div
                        wire:key="cuenta-{{ $cuenta->id }}"
                        @class(['sihla-tarjeta', 'sihla-tarjeta-lectura' => ! $puedeCargar])
                    >
                        <button
                            type="button"
                            @if ($puedeCargar)
                                wire:click="mountAction('cargarEnCuenta', { cuenta: {{ $cuenta->id }} })"
                                wire:loading.attr="disabled"
                            @else
                                disabled
                                data-apagado
                            @endif
                            class="sihla-tarjeta-cuerpo"
                        >
                            <span class="sihla-nombre">{{ $resumen['nombre'] }}</span>

                            {{--
                                N · 3R · 4R: el rango de edad, que es lo que
                                decide el descuento de ley en cada cargo. Va
                                junto al tipo y a hace cuánto entró, para que
                                se lea de un vistazo sin abrir nada. La fecha
                                exacta está en el título y en «⋯».
                            --}}
                            <div class="sihla-insignias">
                                @if ($resumen['rango'] !== '')
                                    <x-filament::badge
                                        :color="$resumen['rango_color']"
                                        size="sm"
                                        :title="$resumen['rango_titulo']"
                                        class="sihla-rango"
                                    >
                                        {{ $resumen['rango'] }}
                                    </x-filament::badge>
                                @endif

                                <x-filament::badge :color="$cuenta->encuentro->tipo->color()" size="sm">
                                    {{ $resumen['tipo'] }}
                                </x-filament::badge>

                                {{--
                                    «Día 32» en hospitalización; minutos,
                                    horas o días en lo demás
                                    (`TiempoDelEncuentro`). La fecha exacta,
                                    en el título.
                                --}}
                                <span class="sihla-hace" title="Ingreso {{ $resumen['ingreso'] }}">
                                    <x-filament::icon :icon="$resumen['desde_icono']" class="sihla-icono-chico" />
                                    {{ $resumen['desde'] }}
                                </span>
                            </div>

                            {{--
                                El expediente, y el servicio si tiene. Cada
                                dato entero o en el renglón de abajo, nunca
                                partido; el punto que los separa lo pone el
                                CSS y desaparece cuando el segundo baja.
                            --}}
                            <div class="sihla-numeros">
                                <div class="sihla-numeros-fila">
                                    <span>{{ $resumen['expediente'] }}</span>

                                    @if (filled($resumen['servicio']))
                                        <span>{{ $resumen['servicio'] }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="sihla-pie">
                                {{--
                                    El pagador va envuelto para poder
                                    ENCOGERSE. Sin eso, un nombre largo
                                    —«PAN-AMERICAN LIFE INSURANCE GROUP»— le
                                    gana el ancho al monto y lo parte en dos
                                    renglones.
                                --}}
                                <div class="sihla-pie-pagador">
                                    <x-filament::badge :color="$cuenta->convenio->tipo->color()" size="sm">
                                        {{ $resumen['pagador'] }}
                                    </x-filament::badge>
                                </div>

                                <div class="sihla-total">
                                    <span class="sihla-total-monto">{{ $resumen['total'] }}</span>
                                    <span class="sihla-tenue">
                                        {{ $resumen['lineas'] }} {{ (int) $resumen['lineas'] === 1 ? 'ítem' : 'ítems' }}
                                    </span>
                                </div>
                            </div>

                            {{--
                                Sin el «Paciente L … · Seguro L …» debajo
                                (Mauricio, 22-oct-2026): en la tarjeta va el
                                total; el reparto se ve al abrir la cuenta y
                                al facturar.
                            --}}
                        </button>

                        {{--
                            ─────────────────────────────────────────────
                            UN RENGLÓN DE BOTONES Y «⋯»
                            ─────────────────────────────────────────────

                            La tarjeta es un `div` y el cuerpo es el botón:
                            un botón dentro de otro no es HTML válido, así
                            que las acciones van al lado del cuerpo, no
                            adentro.

                            Afuera, lo que esa persona usa todo el día
                            (`botonesDeLaTarjeta()`): caja, Abonar y
                            Facturar; el médico, Diagnóstico con su
                            contador. Sin el permiso el botón no está; con
                            el permiso pero sin poder ahora, está apagado y
                            dice por qué.

                            En «⋯», lo de vez en cuando: el número de
                            cuenta, la fecha exacta de ingreso, Anular —la
                            corrección de un error, no una acción del día—
                            y lo clínico que no sale afuera.

                            🔴 «Tratamiento» está apagado a propósito, no
                            sin terminar. Una receta es una orden médica y
                            se firma; hasta que SESAL conteste si la firma
                            electrónica vale en el expediente clínico, no se
                            puede saber si esto se guarda o se imprime — y
                            esa respuesta cambia el módulo entero.
                        --}}
                        <div class="sihla-tarjeta-acciones">
                            @if ($botones['abonar'])
                                <button
                                    type="button"
                                    @if ($porQueNoAbona === null)
                                        wire:click="prepararAbono({{ $cuenta->id }})"
                                        wire:loading.attr="disabled"
                                    @else
                                        disabled
                                        data-apagado
                                        title="{{ $porQueNoAbona }}"
                                    @endif
                                    class="sihla-accion"
                                >
                                    <x-filament::icon icon="heroicon-o-banknotes" class="sihla-icono-chico" />
                                    <span class="sihla-girando" aria-hidden="true"></span>
                                    <span>Abonar</span>
                                </button>
                            @endif

                            {{--
                                🔴 Facturar CIERRA la cuenta y consume un
                                número del SAR. Apagado también en la
                                cuenta vacía: no hay qué facturar.
                            --}}
                            @if ($botones['facturar'])
                                @php($porQueNoFacturaEsta = $this->porQueNoFacturaLa($cuenta, $porQueNoFactura))

                                <button
                                    type="button"
                                    @if ($porQueNoFacturaEsta === null)
                                        wire:click="prepararFactura({{ $cuenta->id }})"
                                        wire:loading.attr="disabled"
                                    @else
                                        disabled
                                        data-apagado
                                        title="{{ $porQueNoFacturaEsta }}"
                                    @endif
                                    class="sihla-accion"
                                >
                                    <x-filament::icon icon="heroicon-o-document-text" class="sihla-icono-chico" />
                                    <span class="sihla-girando" aria-hidden="true"></span>
                                    <span>Facturar</span>
                                </button>
                            @endif

                            @if ($diagnosticoAfuera)
                                <button
                                    type="button"
                                    wire:click="mountAction('diagnosticar', { cuenta: {{ $cuenta->id }} })"
                                    wire:loading.attr="disabled"
                                    class="sihla-accion"
                                >
                                    <x-filament::icon icon="heroicon-o-clipboard-document-list" class="sihla-icono-chico" />
                                    <span class="sihla-girando" aria-hidden="true"></span>
                                    <span>Diagnóstico</span>

                                    @if ($cuantosDiagnosticos > 0)
                                        <span class="sihla-contador">{{ $cuantosDiagnosticos }}</span>
                                    @endif
                                </button>
                            @endif

                            {{--
                                «⋯» abre el menú COMPARTIDO de la página
                                (abajo de la grilla) con los datos de esta
                                cuenta. Un menú por tarjeta era el 58 % del
                                HTML de cada una, y la grilla entera se
                                vuelve a mandar cada vez que se cierra un
                                modal (28-oct-2026).
                            --}}
                            <button
                                type="button"
                                class="sihla-mas-boton"
                                title="Más de esta cuenta"
                                aria-label="Más de esta cuenta"
                                aria-haspopup="menu"
                                x-on:click="$dispatch('sihla-menu-de-cuenta', {
                                    ancla: $el,
                                    cuenta: {{ $cuenta->id }},
                                    numero: @js($cuenta->numero),
                                    ingreso: @js($resumen['ingreso']),
                                    anula: @js($anula),
                                    diagnosticos: {{ $cuantosDiagnosticos }},
                                })"
                            >
                                <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="sihla-icono-mas" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{--
                ─────────────────────────────────────────────────────────
                EL MENÚ «⋯», UNO SOLO PARA TODA LA GRILLA (28-oct-2026)
                ─────────────────────────────────────────────────────────

                Cada tarjeta traía su propio menú armado —encabezados,
                renglones, íconos, el aviso de Tratamiento—: 8.7 de los
                14.9 KB de la tarjeta. Cerrar cualquier modal vuelve a
                mandar la grilla entera, y con 24 cuentas eso eran 200 KB
                de menús que nadie había abierto.

                Ahora «⋯» manda los datos de SU cuenta y este menú se
                pega a ese botón (`x-anchor`, que sigue a la ancla cuando
                cambia). Como vive afuera de las tarjetas, ninguna tarjeta
                lo tapa (§9.M29).

                Los permisos siguen donde estaban: «Anular» sale solo si
                `puedeAnular()` lo dijo para esa cuenta, y la acción lo
                vuelve a verificar al montarse.
            --}}
            <div
                class="sihla-menu"
                x-data="{
                    abierto: false,
                    ancla: null,
                    c: { cuenta: null, numero: '', ingreso: '', anula: false, diagnosticos: 0 },
                    abrir(datos) {
                        if (this.abierto && this.ancla === datos.ancla) {
                            this.abierto = false

                            return
                        }

                        this.c = datos
                        this.ancla = datos.ancla
                        this.abierto = true
                    },
                    accion(nombre) {
                        this.abierto = false
                        this.$wire.mountAction(nombre, { cuenta: this.c.cuenta })
                    },
                }"
                x-on:sihla-menu-de-cuenta.window="abrir($event.detail)"
                x-on:keydown.escape.window="abierto = false"
            >
                <div
                    x-cloak
                    x-show="abierto"
                    x-anchor.bottom-end.offset.6.fixed="ancla"
                    x-on:click.outside="if (! $event.target.closest('.sihla-mas-boton')) abierto = false"
                    x-transition:enter-start="fi-opacity-0"
                    x-transition:leave-end="fi-opacity-0"
                    class="fi-dropdown-panel sihla-menu-panel"
                    role="menu"
                >
                    <div class="sihla-menu-cabecera">
                        <x-filament::dropdown.header icon="heroicon-o-hashtag">
                            <span x-text="c.numero"></span>
                        </x-filament::dropdown.header>

                        <x-filament::dropdown.header icon="heroicon-o-clock">
                            <span x-text="c.ingreso" x-bind:title="'Ingreso ' + c.ingreso"></span>
                        </x-filament::dropdown.header>
                    </div>

                    <x-filament::dropdown.list x-show="{{ $botones['clinicos'] ? 'true' : 'c.anula' }}">
                        @if ($botones['clinicos'] && ! $diagnosticoAfuera)
                            <x-filament::dropdown.list.item
                                icon="heroicon-o-clipboard-document-list"
                                x-on:click="accion('diagnosticar')"
                            >
                                Diagnóstico
                                <span class="sihla-contador" x-cloak x-show="c.diagnosticos > 0" x-text="c.diagnosticos"></span>
                            </x-filament::dropdown.list.item>
                        @endif

                        @if ($botones['clinicos'])
                            <x-filament::dropdown.list.item
                                icon="heroicon-o-beaker"
                                :disabled="true"
                                tooltip="La receta es una orden médica y se firma. Se habilita cuando SESAL confirme si la firma electrónica vale en el expediente clínico."
                            >
                                Tratamiento
                            </x-filament::dropdown.list.item>
                        @endif

                        <x-filament::dropdown.list.item
                            icon="heroicon-o-x-circle"
                            color="danger"
                            x-show="c.anula"
                            x-on:click="accion('anularCuenta')"
                        >
                            Anular cuenta
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </div>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />

    <style>
        .sihla-cuentas { display: flex; flex-direction: column; gap: 1.25rem; }

        .sihla-barra {
            display: flex; flex-direction: column; gap: .75rem;
        }
        @media (min-width: 640px) {
            .sihla-barra { flex-direction: row; align-items: center; justify-content: space-between; }
        }

        .sihla-buscador {
            position: relative; display: flex; align-items: center; flex: 1 1 auto; max-width: 34rem;
        }
        .sihla-buscador-icono {
            position: absolute; inset-inline-start: .75rem; width: 1.15rem; height: 1.15rem;
            color: rgb(113 113 122); pointer-events: none;
        }
        .sihla-buscador-campo {
            width: 100%; padding: .65rem .9rem .65rem 2.6rem; font-size: .95rem;
            border-radius: .6rem; border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255); color: inherit;
        }
        /* `--primary-600` de Filament 5 es un color entero (oklch): va con var() solo (§9.M28). */
        .sihla-buscador-campo:focus {
            outline: 2px solid var(--primary-600, rgb(79 70 229)); outline-offset: 1px; border-color: transparent;
        }

        .sihla-aviso-tope {
            display: flex; align-items: flex-start; gap: .5rem;
            padding: .65rem .85rem; border-radius: .6rem; font-size: .85rem;
            border: 1px solid rgb(253 230 138); background: rgb(255 251 235); color: rgb(146 64 14);
        }
        .sihla-aviso-tope-icono { width: 1.1rem; height: 1.1rem; flex: none; margin-top: .05rem; }

        /*
         * `grid-auto-rows: 1fr` hace que TODAS las tarjetas midan igual, y
         * no solo las de la misma fila: el pie queda a la misma altura en
         * toda la grilla. Sin `min-height`: la tarjeta mide lo que tiene
         * adentro, y por eso ya no queda un hueco en el medio.
         */
        .sihla-tarjetas {
            display: grid; gap: .875rem; grid-template-columns: repeat(1, minmax(0, 1fr));
            grid-auto-rows: 1fr;
        }
        @media (min-width: 640px)  { .sihla-tarjetas { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .sihla-tarjetas { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1536px) { .sihla-tarjetas { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

        /*
         * ⚠️ Sin `transform` al pasar el mouse. Un transform arma un
         * contexto de apilamiento propio, y el menú «⋯» que se abre hacia
         * abajo quedaba DEBAJO de la tarjeta de la fila siguiente.
         */
        .sihla-tarjeta {
            position: relative; display: flex; flex-direction: column; height: 100%;
            padding: .875rem .95rem .75rem; border-radius: .75rem; border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            transition: box-shadow .15s ease, border-color .15s ease;
        }
        .sihla-tarjeta:hover {
            border-color: rgb(161 161 170);
            box-shadow: 0 6px 18px -8px rgb(0 0 0 / .25);
        }
        .sihla-tarjeta-lectura:hover { border-color: rgb(228 228 231); box-shadow: none; }

        /*
         * El cuerpo se estira para ocupar lo que sobre: con eso el pie
         * —pagador y total— queda a la MISMA altura en todas las tarjetas.
         */
        .sihla-tarjeta-cuerpo {
            display: flex; flex-direction: column; gap: .4rem; flex: 1 1 auto;
            width: 100%; text-align: start; padding: 0; margin: 0;
            border: 0; background: transparent; color: inherit; cursor: pointer;
        }
        .sihla-tarjeta-cuerpo:focus-visible {
            outline: 2px solid var(--primary-600, rgb(79 70 229)); outline-offset: 4px; border-radius: .5rem;
        }
        .sihla-tarjeta-cuerpo:disabled { cursor: progress; }
        .sihla-tarjeta-cuerpo[data-apagado] { cursor: default; }
        .sihla-tarjeta-lectura .sihla-tarjeta-cuerpo { cursor: default; }

        .sihla-nombre {
            font-size: .95rem; font-weight: 600; line-height: 1.25; color: rgb(24 24 27);
        }
        .sihla-insignias {
            display: flex; flex-wrap: wrap; align-items: center; gap: .3rem;
        }
        .sihla-rango { font-weight: 700; letter-spacing: .02em; cursor: help; }
        .sihla-hace {
            display: inline-flex; align-items: center; gap: .25rem; margin-inline-start: .2rem;
            font-size: .75rem; color: rgb(113 113 122); white-space: nowrap;
        }

        .sihla-icono-chico { width: .9rem; height: .9rem; flex: none; }
        .sihla-tenue { color: rgb(161 161 170); }

        /*
         * El separador vive en el margen izquierdo de cada dato. La fila de
         * adentro corre ese margen hacia afuera (`margin-left` negativo) y
         * la caja de afuera lo recorta (`overflow: hidden`): si los dos
         * caben, el punto queda entre ellos; si el segundo baja, el punto
         * cae en la parte recortada y no cuelga al final del primero.
         * Hacen falta las DOS cajas: con una sola, el recorte empieza donde
         * empieza el margen y el punto se ve.
         */
        .sihla-numeros { overflow: hidden; font-size: .78rem; color: rgb(113 113 122); }
        .sihla-numeros-fila { display: flex; flex-wrap: wrap; row-gap: .1rem; margin-left: -1rem; }
        .sihla-numeros-fila > span { position: relative; margin-left: 1rem; white-space: nowrap; }
        .sihla-numeros-fila > span + span::before { content: '·'; position: absolute; left: -.6rem; }

        /*
         * `margin-top: auto` empuja el pie al fondo del cuerpo y alinea el
         * total de todas las tarjetas: el ojo lee una columna de montos,
         * no una escalera.
         */
        .sihla-pie {
            display: flex; align-items: center; justify-content: space-between; gap: .5rem;
            margin-top: auto; padding-top: .55rem; border-top: 1px solid rgb(244 244 245);
        }
        /*
         * EL MONTO NUNCA SE PARTE: `flex: none` + `nowrap` le dan al total
         * el ancho que necesite y el pagador se encoge con puntos
         * suspensivos. `tabular-nums` alinea los dígitos entre tarjetas.
         */
        .sihla-pie-pagador { min-width: 0; flex: 1 1 auto; overflow: hidden; }
        .sihla-pie-pagador > * { max-width: 100%; }
        .sihla-total {
            display: flex; flex-direction: column; align-items: flex-end; line-height: 1.15;
            flex: 0 0 auto; white-space: nowrap; font-size: .78rem;
        }
        .sihla-total-monto {
            font-size: 1.05rem; font-weight: 700; color: rgb(24 24 27);
            font-variant-numeric: tabular-nums;
        }

        /*
         * UN renglón: los botones del día se reparten el ancho y «⋯» queda
         * a la derecha con su ancho justo. Sin botones —laboratorio—, «⋯»
         * solo, a la derecha.
         */
        .sihla-tarjeta-acciones {
            display: flex; align-items: center; gap: .4rem;
            margin-top: .65rem; padding-top: .6rem; border-top: 1px solid rgb(244 244 245);
        }
        .sihla-accion {
            display: flex; align-items: center; justify-content: center; gap: .3rem;
            flex: 1 1 0; min-width: 0; height: 2rem; padding: 0 .5rem; border-radius: .5rem;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            font-size: .78rem; font-weight: 500; color: rgb(63 63 70); cursor: pointer;
            transition: border-color .15s ease, background .15s ease;
        }
        .sihla-accion > span:not(.sihla-contador) {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .sihla-accion:hover:not(:disabled) { border-color: rgb(161 161 170); background: rgb(244 244 245); }

        /*
         * ─────────────────────────────────────────────────────────────
         * APAGADO NO ES LO MISMO QUE OCUPADO (28-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * `wire:loading.attr="disabled"` apaga TODOS los botones mientras
         * hay una petición en camino —es lo que impide el doble clic—, y
         * antes eso los ponía al 45 %: cada búsqueda o cada modal hacía
         * parpadear la grilla entera. Ahora el gris es solo para los que
         * no se pueden usar (`data-apagado`, con el porqué en el título);
         * los ocupados quedan iguales, con el cursor de espera, y el que
         * se apretó gira (`data-loading`, que Livewire 4 le pone al botón
         * que hizo la petición).
         */
        .sihla-accion:disabled { cursor: progress; }
        .sihla-accion[data-apagado] { opacity: .45; cursor: not-allowed; }

        .sihla-girando {
            display: none; flex: none; width: .9rem; height: .9rem; border-radius: 9999px;
            border: 2px solid currentColor; border-top-color: transparent;
            animation: sihla-girar .7s linear infinite;
        }
        .sihla-accion[data-loading] .sihla-girando { display: inline-block; }
        .sihla-accion[data-loading] .sihla-icono-chico { display: none; }

        /*
         * Al tocar la tarjeta (agregar a la cuenta): el borde se enciende
         * y gira una ruedita en la esquina hasta que abre el modal.
         */
        .sihla-tarjeta:has(> .sihla-tarjeta-cuerpo[data-loading]) {
            border-color: var(--primary-500, rgb(245 158 11));
        }
        .sihla-tarjeta:has(> .sihla-tarjeta-cuerpo[data-loading])::after {
            content: ''; position: absolute; top: .8rem; right: .8rem;
            width: 1rem; height: 1rem; border-radius: 9999px;
            border: 2px solid var(--primary-500, rgb(245 158 11)); border-top-color: transparent;
            animation: sihla-girar .7s linear infinite;
        }
        @keyframes sihla-girar { to { transform: rotate(360deg); } }

        .sihla-mas-boton {
            flex: none; margin-inline-start: auto;
            display: flex; align-items: center; justify-content: center;
            width: 2rem; height: 2rem; border-radius: .5rem;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            color: rgb(82 82 91); cursor: pointer;
            transition: border-color .15s ease, background .15s ease;
        }
        .sihla-mas-boton:hover { border-color: rgb(161 161 170); background: rgb(244 244 245); }
        .sihla-icono-mas { width: 1.1rem; height: 1.1rem; }

        /*
         * El menú compartido no ocupa lugar en la columna de la página:
         * su panel es `fixed` y se pega al «⋯» que lo abrió.
         */
        .sihla-menu { display: contents; }
        .sihla-menu-panel > .sihla-menu-cabecera { border-bottom-width: 0 !important; }
        .sihla-menu-panel > .fi-dropdown-list { border-top: 1px solid var(--gray-100, rgb(244 244 245)); }
        .dark .sihla-menu-panel > .fi-dropdown-list { border-top-color: rgb(255 255 255 / .06); }
        .sihla-menu-panel .sihla-contador { margin-inline-start: .35rem; }

        .sihla-contador {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 1.15rem; height: 1.15rem; padding: 0 .3rem; border-radius: 999px;
            background: rgb(220 38 38); color: rgb(255 255 255);
            font-size: .7rem; font-weight: 600; line-height: 1;
        }

        .sihla-vacio {
            display: flex; flex-direction: column; align-items: center; gap: .6rem;
            padding: 3.5rem 1.5rem; text-align: center;
            border: 1px dashed rgb(228 228 231); border-radius: .75rem;
        }
        .sihla-vacio-icono { width: 2.5rem; height: 2.5rem; color: rgb(161 161 170); }
        .sihla-vacio-titulo { font-size: 1rem; font-weight: 600; color: rgb(63 63 70); }
        .sihla-vacio-texto { max-width: 34rem; font-size: .875rem; color: rgb(113 113 122); }

        .dark .sihla-buscador-campo,
        .dark .sihla-tarjeta { background: rgb(39 39 42); border-color: rgb(63 63 70); }
        .dark .sihla-tarjeta:hover { border-color: rgb(113 113 122); }
        .dark .sihla-tarjeta-lectura:hover { border-color: rgb(63 63 70); }
        .dark .sihla-nombre,
        .dark .sihla-total-monto { color: rgb(244 244 245); }
        .dark .sihla-hace,
        .dark .sihla-numeros { color: rgb(161 161 170); }
        .dark .sihla-pie,
        .dark .sihla-tarjeta-acciones { border-top-color: rgb(63 63 70); }
        .dark .sihla-accion,
        .dark .sihla-mas-boton {
            border-color: rgb(63 63 70); background: rgb(52 52 56); color: rgb(212 212 216);
        }
        .dark .sihla-accion:hover:not(:disabled),
        .dark .sihla-mas-boton:hover {
            border-color: rgb(113 113 122); background: rgb(63 63 70);
        }
        .dark .sihla-vacio { border-color: rgb(63 63 70); }
        .dark .sihla-vacio-titulo { color: rgb(212 212 216); }
        .dark .sihla-aviso-tope {
            border-color: rgb(120 53 15); background: rgb(69 26 3 / .45); color: rgb(253 230 138);
        }
    </style>
</x-filament-panels::page>
