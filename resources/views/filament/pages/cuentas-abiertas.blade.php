{{--
    Las cuentas abiertas del hospital.

    Tarjetas y no tabla: lo único que hay que poder leer a un metro de
    distancia es de quién es la cuenta, desde cuándo está abierta y
    cuánto lleva. Todo lo demás está adentro.

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

            {{ $this->abrirCuentaAction }}
        </div>

        @php($cuentas = $this->cuentas())
        @php($puedeCargar = $this->puedeCargar())
        @php($puedeDiagnosticar = $this->puedeDiagnosticar())

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
            <div class="sihla-tarjetas">
                @foreach ($cuentas as $cuenta)
                    @php($resumen = $this->resumenDe($cuenta))

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
                        @endif
                        class="sihla-tarjeta-cuerpo"
                    >
                        <div class="sihla-tarjeta-cabecera">
                            <span class="sihla-nombre">{{ $resumen['nombre'] }}</span>

                            <x-filament::badge :color="$cuenta->encuentro->tipo->color()" size="sm">
                                {{ $resumen['tipo'] }}
                            </x-filament::badge>
                        </div>

                        <div class="sihla-linea-tenue">
                            <span>{{ $resumen['expediente'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $cuenta->numero }}</span>
                        </div>

                        <div class="sihla-ingreso">
                            <x-filament::icon icon="heroicon-o-clock" class="sihla-icono-chico" />
                            <span>Ingreso {{ $resumen['ingreso'] }}</span>
                            <span class="sihla-tenue">({{ $resumen['desde'] }})</span>
                        </div>

                        @if (filled($resumen['servicio']))
                            <div class="sihla-linea-tenue">
                                <x-filament::icon icon="heroicon-o-building-office-2" class="sihla-icono-chico" />
                                <span>{{ $resumen['servicio'] }}</span>
                            </div>
                        @endif

                        <div class="sihla-pie">
                            {{--
                                El pagador va envuelto para poder
                                ENCOGERSE. Sin eso, un nombre largo
                                —«PAN-AMERICAN LIFE INSURANCE GROUP»— le
                                gana el ancho al monto y lo parte en dos
                                renglones, que es lo que se veía.
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

                        @if ($cuenta->saldoDeLaAseguradora()->esCero() === false)
                            <div class="sihla-division">
                                <span>Paciente {{ $resumen['paciente'] }}</span>
                                <span aria-hidden="true">·</span>
                                <span>Seguro {{ $resumen['aseguradora'] }}</span>
                            </div>
                        @endif
                    </button>

                    {{--
                        ─────────────────────────────────────────────────
                        TODO DENTRO DE LA MISMA CAJA
                        ─────────────────────────────────────────────────

                        La tarjeta es un `div` y el cuerpo es el botón:
                        un botón dentro de otro no es HTML válido, así que
                        la única forma de que estas dos acciones vivan
                        DENTRO de la tarjeta —y no colgando abajo— es que
                        la tarjeta deje de ser el botón.

                        🔴 «Tratamiento» está apagado a propósito, no sin
                        terminar. Una receta es una orden médica y se
                        firma; hasta que SESAL conteste si la firma
                        electrónica vale en el expediente clínico, no se
                        puede saber si esto se guarda o se imprime — y esa
                        respuesta cambia el módulo entero, no un campo.
                    --}}
                    <div class="sihla-tarjeta-acciones">
                        {{--
                            Abonar vive acá, junto al saldo, y no en una
                            pantalla de caja aparte: quien recibe la plata
                            está mirando este número.
                        --}}
                        <button
                            type="button"
                            wire:click="prepararAbono({{ $cuenta->id }})"
                            wire:loading.attr="disabled"
                            class="sihla-accion-clinica"
                        >
                            <x-filament::icon icon="heroicon-o-banknotes" class="sihla-icono-chico" />
                            <span>Abonar</span>
                        </button>

                        {{--
                            🔴 Facturar CIERRA la cuenta y consume un
                            número del SAR. Va acá, al lado del saldo,
                            porque es la última cosa que se hace con una
                            cuenta abierta.
                        --}}
                        <button
                            type="button"
                            wire:click="prepararFactura({{ $cuenta->id }})"
                            wire:loading.attr="disabled"
                            class="sihla-accion-clinica"
                        >
                            <x-filament::icon icon="heroicon-o-document-text" class="sihla-icono-chico" />
                            <span>Facturar</span>
                        </button>

                        <button
                            type="button"
                            @if ($puedeDiagnosticar)
                                wire:click="mountAction('diagnosticar', { cuenta: {{ $cuenta->id }} })"
                                wire:loading.attr="disabled"
                            @else
                                disabled
                                title="Solo el médico tratante escribe el diagnóstico."
                            @endif
                            class="sihla-accion-clinica"
                        >
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="sihla-icono-chico" />
                            <span>Diagnóstico</span>

                            @if ($this->cuantosDiagnosticos($cuenta) > 0)
                                <span class="sihla-contador">{{ $this->cuantosDiagnosticos($cuenta) }}</span>
                            @endif
                        </button>

                        <button
                            type="button"
                            disabled
                            class="sihla-accion-clinica"
                            title="La receta es una orden médica y se firma. Se habilita cuando SESAL confirme si la firma electrónica vale en el expediente clínico."
                        >
                            <x-filament::icon icon="heroicon-o-beaker" class="sihla-icono-chico" />
                            <span>Tratamiento</span>
                        </button>
                    </div>
                    </div>
                @endforeach
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
        .sihla-buscador-campo:focus {
            outline: 2px solid rgb(var(--primary-600, 79 70 229)); outline-offset: 1px; border-color: transparent;
        }

        /*
         * `grid-auto-rows: 1fr` es lo que hace que TODAS las tarjetas
         * midan igual, y no solo las de la misma fila. Sin eso, un nombre
         * que ocupa dos renglones estira su fila y la de abajo queda más
         * corta — que es lo que se veía mal.
         */
        .sihla-tarjetas {
            display: grid; gap: 1rem; grid-template-columns: repeat(1, minmax(0, 1fr));
            grid-auto-rows: 1fr;
        }
        @media (min-width: 640px)  { .sihla-tarjetas { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .sihla-tarjetas { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1536px) { .sihla-tarjetas { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

        /*
         * La CAJA. Ya no es el botón: es el marco que contiene todo —el
         * cuerpo que se toca para cargar, y abajo las dos acciones
         * clínicas—. `min-height` fija el piso para que una cuenta con
         * un solo dato no quede enana al lado de una hospitalización con
         * seguro y reparto.
         */
        .sihla-tarjeta {
            display: flex; flex-direction: column; height: 100%; min-height: 15.5rem;
            padding: 1rem; border-radius: .75rem; border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            transition: box-shadow .15s ease, transform .15s ease, border-color .15s ease;
        }
        .sihla-tarjeta:hover {
            border-color: rgb(161 161 170);
            box-shadow: 0 6px 18px -6px rgb(0 0 0 / .18);
            transform: translateY(-1px);
        }
        .sihla-tarjeta-lectura:hover { border-color: rgb(228 228 231); box-shadow: none; transform: none; }

        /*
         * El cuerpo se estira para ocupar lo que sobre. Con eso, el pie
         * —pagador y total— queda a la MISMA altura en todas las
         * tarjetas, que es la mitad de por qué una grilla se ve prolija.
         */
        .sihla-tarjeta-cuerpo {
            display: flex; flex-direction: column; gap: .55rem; flex: 1 1 auto;
            width: 100%; text-align: start; padding: 0; margin: 0;
            border: 0; background: transparent; color: inherit; cursor: pointer;
        }
        .sihla-tarjeta-cuerpo:focus-visible {
            outline: 2px solid rgb(var(--primary-600, 79 70 229)); outline-offset: 4px; border-radius: .5rem;
        }
        .sihla-tarjeta-cuerpo:disabled { cursor: default; }
        .sihla-tarjeta-lectura .sihla-tarjeta-cuerpo { cursor: default; }

        /*
         * Separadas del cuerpo por una línea fina y dentro de la misma
         * caja: son del expediente, no de la cuenta, y el borde lo dice
         * sin necesidad de un rótulo.
         */
        /*
         * 🔴 GRID Y NO FLEX, DESDE QUE SON TRES BOTONES.
         *
         * Con `display:flex` los hijos no bajan de su ancho de contenido
         * —`min-width` vale `auto` por defecto— así que «Tratamiento» se
         * salía de la tarjeta y pisaba la de al lado. Tres columnas de
         * `minmax(0, 1fr)` reparten el ancho en partes iguales y dejan
         * que el texto se recorte adentro en vez de empujar hacia afuera.
         */
        /*
         * Dos por fila desde que son cuatro. Con `repeat(4, …)` los
         * rótulos quedaban en «Diagn…» y «Trata…», que es justo lo que
         * hay que leer para no equivocarse de botón.
         */
        .sihla-tarjeta-acciones {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .4rem; margin-top: .75rem; padding-top: .7rem;
            border-top: 1px solid rgb(244 244 245);
        }

        .sihla-accion-clinica {
            display: flex; align-items: center; justify-content: center; gap: .3rem;
            min-width: 0; padding: .45rem .35rem; border-radius: .5rem;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            font-size: .75rem; font-weight: 500; color: rgb(63 63 70); cursor: pointer;
            transition: border-color .15s ease, background .15s ease;
        }

        /* El rótulo se recorta adentro del botón; el icono nunca se encoge. */
        .sihla-accion-clinica > span:not(.sihla-contador) {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .sihla-accion-clinica .sihla-icono-chico { flex: none; }
        .sihla-accion-clinica:hover:not(:disabled) {
            border-color: rgb(161 161 170); background: rgb(244 244 245);
        }
        .sihla-accion-clinica:disabled { opacity: .45; cursor: not-allowed; }

        .sihla-contador {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 1.15rem; height: 1.15rem; padding: 0 .3rem; border-radius: 999px;
            background: rgb(220 38 38); color: rgb(255 255 255);
            font-size: .7rem; font-weight: 600; line-height: 1;
        }

        .sihla-tarjeta-cabecera {
            display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem;
        }
        .sihla-nombre {
            font-size: 1rem; font-weight: 600; line-height: 1.25; color: rgb(24 24 27);
        }

        .sihla-linea-tenue,
        .sihla-ingreso {
            display: flex; align-items: center; gap: .35rem;
            font-size: .8rem; color: rgb(113 113 122);
        }
        .sihla-icono-chico { width: .9rem; height: .9rem; flex: none; }
        .sihla-tenue { color: rgb(161 161 170); }

        /*
         * `margin-top: auto` empuja el pie al fondo del cuerpo. Es lo que
         * alinea el total de todas las tarjetas en la misma línea sin
         * importar cuántos renglones ocupe el nombre arriba — el ojo lee
         * una columna de montos, no una escalera.
         */
        .sihla-pie {
            display: flex; align-items: center; justify-content: space-between; gap: .5rem;
            margin-top: auto; padding-top: .65rem; border-top: 1px solid rgb(244 244 245);
        }
        /*
         * ─────────────────────────────────────────────────────────────
         * EL MONTO NUNCA SE PARTE
         * ─────────────────────────────────────────────────────────────
         *
         * `flex: none` + `nowrap` le dan al total el ancho que necesite y
         * el pagador se encoge con puntos suspensivos. Una cuenta de
         * hospitalización larga puede llegar a L 250,000.00 —trece
         * caracteres— y tiene que caber en un renglón: un monto partido
         * en dos se lee mal justo cuando más importa leerlo bien.
         *
         * `tabular-nums` fija el ancho de cada dígito, así los montos de
         * todas las tarjetas quedan alineados en columna en vez de
         * bailar según cuántos unos tenga el número.
         */
        .sihla-pie-pagador { min-width: 0; flex: 1 1 auto; overflow: hidden; }
        .sihla-pie-pagador > * { max-width: 100%; }

        .sihla-total {
            display: flex; flex-direction: column; align-items: flex-end; line-height: 1.15;
            flex: 0 0 auto; white-space: nowrap;
        }
        .sihla-total-monto {
            font-size: 1.05rem; font-weight: 700; color: rgb(24 24 27);
            font-variant-numeric: tabular-nums;
        }

        .sihla-division {
            display: flex; gap: .4rem; font-size: .75rem; color: rgb(113 113 122);
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
        .dark .sihla-nombre,
        .dark .sihla-total-monto { color: rgb(244 244 245); }
        .dark .sihla-pie,
        .dark .sihla-tarjeta-acciones { border-top-color: rgb(63 63 70); }
        .dark .sihla-accion-clinica {
            border-color: rgb(63 63 70); background: rgb(52 52 56); color: rgb(212 212 216);
        }
        .dark .sihla-accion-clinica:hover:not(:disabled) {
            border-color: rgb(113 113 122); background: rgb(63 63 70);
        }
        .dark .sihla-vacio { border-color: rgb(63 63 70); }
        .dark .sihla-vacio-titulo { color: rgb(212 212 216); }
    </style>
</x-filament-panels::page>
