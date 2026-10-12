{{--
    Los descuentos de la cuenta, arriba del formulario de agregar.

    Tres columnas en UNA sección con la misma forma que la del buscador de
    abajo: farmacia, servicios y honorarios. Cada columna dice qué toca, el
    campo con su unidad adentro, hasta cuánto se puede, y —cuando ya quedó
    guardado— cuánto quedó aplicado. El motivo aparece debajo de su campo
    cuando se escribe un número.

    Historia corta, para no volver atrás:
      · 11-oct-2026 — fuera «Lo que más se carga»: «no aporta en nada y es
        espacio que se puede usar».
      · 12-oct-2026 — dos tarjetas y después dos filas: «se ve feo». Las
        filas dejaban el lado derecho vacío y los topes flotando.
      · 12-oct-2026 — entra honorarios: «también hacían descuentos en
        honorarios». Va aparte de servicios: rebajarlo es rebajarle al
        médico (`RubroDeDescuento`).

    🔴 `wire:model.live.blur`, NO `wire:model.blur`. En Livewire 4 el
    `.blur` solo, sin `.live`, guarda el valor en el navegador y no le
    avisa al servidor. Con `.live.blur` se aplica al salir del campo, y
    Enter sale del campo en vez de mandar el formulario de abajo.

    🔴 El «¿por qué?» aparece con Alpine y NO esperando al servidor: el
    número se escribe y la pregunta ya está ahí, con las letras que faltan
    contadas mientras se escribe.

    ⚠️ En este archivo, el PHP va siempre en bloque de apertura y cierre,
    nunca en la forma de una línea con paréntesis. Blade busca los bloques
    desde la primera directiva de PHP que encuentra hasta el primer cierre,
    y una de una línea suelta antes de un bloque se traga todo lo que hay
    en el medio. Por lo mismo, ni nombrarlas en un comentario: el
    compilador las ve antes de borrar los comentarios.
--}}
@php
    $cuenta = $cuenta ?? null;
    $vacio = ['valor' => null, 'modo' => 'porcentaje', 'motivo' => null, 'ayuda' => '', 'detalle' => '', 'maximo' => null, 'puede' => false, 'aplicado' => null];
    $servicios = $servicios ?? $vacio;
    $honorarios = $honorarios ?? $vacio;

    /*
     * Lo que cambia entre los dos rubros: a qué propiedad de Livewire se
     * ata cada campo y cómo se presenta. Lo demás es la misma columna.
     */
    $rubros = [
        'servicios' => [
            'datos'   => $servicios,
            'nombre'  => 'Servicios',
            'toca'    => 'Hospital, consultas, laboratorio y rayos X',
            'valor'   => 'descuentoServicios',
            'motivo'  => 'motivoServicios',
        ],
        'honorarios' => [
            'datos'   => $honorarios,
            'nombre'  => 'Honorarios',
            'toca'    => 'Lo que cobra el médico',
            'valor'   => 'descuentoHonorarios',
            'motivo'  => 'motivoHonorarios',
        ],
    ];
@endphp

@if ($cuenta && $puede)
    {{-- Lo que quedó GUARDADO en la cuenta, no lo que se está tecleando: la etiqueta dice si ya tomó. --}}
    @php
        $enFarmacia = $cuenta->descuento_hospital === null
            ? null
            : rtrim(rtrim(number_format((float) $cuenta->descuento_hospital * 100, 2), '0'), '.').' %';
    @endphp

    <section class="dsc" aria-labelledby="dsc-titulo">
        <header class="dsc-cabeza">
            <h3 id="dsc-titulo" class="dsc-titulo">Descuentos de la cuenta</h3>
            <p class="dsc-bajada">Alcanzan a lo que ya está cargado y a lo que se cargue después. Cada uno pide su motivo.</p>
        </header>

        <div class="dsc-columnas">
            {{-- ── Farmacia ─────────────────────────────────────────── --}}
            <div class="dsc-col" x-data="{ desc: @js($descuento), motivo: @js($motivo ?? '') }">
                <div class="dsc-col-cabeza">
                    <label class="dsc-nombre" for="dsc-farmacia">Farmacia</label>
                    @if ($enFarmacia)
                        <span class="dsc-estado">{{ $enFarmacia }} aplicado</span>
                    @endif
                </div>
                <p class="dsc-toca">Medicamentos y materiales</p>

                <div class="dsc-campo">
                    <input
                        id="dsc-farmacia"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        max="{{ $tope }}"
                        step="0.01"
                        placeholder="0"
                        wire:model.live.blur="descuentoDeLaTanda"
                        x-on:input="desc = $event.target.value"
                        x-on:keydown.enter.prevent="$el.blur()"
                        @disabled($tope === '0.00')
                    >
                    <span class="dsc-sufijo">%</span>
                </div>

                <p class="dsc-tope">{{ $ayuda }}</p>

                <div class="dsc-porque" x-show="Number(desc) > 0" x-cloak>
                    <input
                        type="text"
                        class="dsc-motivo"
                        style="text-transform: uppercase"
                        maxlength="200"
                        placeholder="¿Por qué se le rebaja?"
                        aria-label="Motivo del descuento en farmacia"
                        wire:model.live.blur="motivoDeLaTanda"
                        x-on:input="motivo = $event.target.value"
                        x-on:keydown.enter.prevent="$el.blur()"
                    >
                    <p class="dsc-falta" x-show="motivo.trim().length < 10">
                        Faltan <span x-text="10 - motivo.trim().length"></span> letras para que quede explicado.
                    </p>
                </div>
            </div>

            {{--
                ── Servicios y honorarios ───────────────────────────────

                El % y la L van ADENTRO del campo, a la derecha, donde
                farmacia tiene su «%»: son la unidad del número, y apretarlos
                la cambia. `wire:key` con el modo: al pasar de % a L, Alpine
                vuelve a nacer con el campo vacío y el motivo escondido.
            --}}
            @foreach ($rubros as $clave => $rubro)
                <div
                    class="dsc-col"
                    wire:key="dsc-{{ $clave }}-{{ $cuenta->id }}-{{ $rubro['datos']['modo'] }}"
                    x-data="{ desc: @js($rubro['datos']['valor']), motivo: @js($rubro['datos']['motivo'] ?? '') }"
                >
                    <div class="dsc-col-cabeza">
                        <label class="dsc-nombre" for="dsc-{{ $clave }}">{{ $rubro['nombre'] }}</label>
                        @if ($rubro['datos']['aplicado'])
                            <span class="dsc-estado">{{ $rubro['datos']['aplicado'] }} aplicado</span>
                        @endif
                    </div>
                    <p class="dsc-toca">{{ $rubro['toca'] }}</p>

                    <div class="dsc-campo">
                        @if ($rubro['datos']['modo'] === 'monto')
                            <span class="dsc-prefijo">L</span>
                        @endif
                        <input
                            id="dsc-{{ $clave }}"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                            placeholder="0"
                            wire:model.live.blur="{{ $rubro['valor'] }}"
                            x-on:input="desc = $event.target.value"
                            x-on:keydown.enter.prevent="$el.blur()"
                            @disabled(! $rubro['datos']['puede'])
                        >
                        <span class="dsc-modo" role="group" aria-label="Rebajar en porcentaje o en lempiras">
                            <button
                                type="button"
                                wire:click="cambiarModoDelDescuento('{{ $clave }}', 'porcentaje')"
                                @class(['activo' => $rubro['datos']['modo'] === 'porcentaje'])
                                aria-pressed="{{ $rubro['datos']['modo'] === 'porcentaje' ? 'true' : 'false' }}"
                                title="En porcentaje: cada renglón hasta su tope"
                            >%</button>
                            <button
                                type="button"
                                wire:click="cambiarModoDelDescuento('{{ $clave }}', 'monto')"
                                @class(['activo' => $rubro['datos']['modo'] === 'monto'])
                                aria-pressed="{{ $rubro['datos']['modo'] === 'monto' ? 'true' : 'false' }}"
                                title="En lempiras: se reparte entre lo cargado"
                            >L</button>
                        </span>
                    </div>

                    <p class="dsc-tope">
                        @if ($rubro['datos']['modo'] === 'monto' && $rubro['datos']['maximo'] !== null)
                            Se reparte entre lo cargado, hasta L {{ number_format((float) $rubro['datos']['maximo'], 2) }}.
                        @else
                            {{ $rubro['datos']['ayuda'] }}
                        @endif

                        @if ($rubro['datos']['detalle'] !== '')
                            <span class="dsc-info" tabindex="0" title="{{ $rubro['datos']['detalle'] }}" aria-label="Topes: {{ str_replace("\n", '; ', $rubro['datos']['detalle']) }}">
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/></svg>
                            </span>
                        @endif
                    </p>

                    <div class="dsc-porque" x-show="Number(desc) > 0" x-cloak>
                        <input
                            type="text"
                            class="dsc-motivo"
                            style="text-transform: uppercase"
                            maxlength="200"
                            placeholder="¿Por qué se le rebaja?"
                            aria-label="Motivo del descuento en {{ $clave }}"
                            wire:model.live.blur="{{ $rubro['motivo'] }}"
                            x-on:input="motivo = $event.target.value"
                            x-on:keydown.enter.prevent="$el.blur()"
                        >
                        <p class="dsc-falta" x-show="motivo.trim().length < 10">
                            Faltan <span x-text="10 - motivo.trim().length"></span> letras para que quede explicado.
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <style>
        [x-cloak] { display: none !important; }

        .dsc {
            margin-bottom: 1.25rem; padding: 1.25rem 1.5rem 1.35rem;
            border: 1px solid rgb(228 228 231); border-radius: .75rem; background: rgb(255 255 255);
        }
        .dsc-titulo { font-size: .95rem; font-weight: 600; color: rgb(24 24 27); }
        .dsc-bajada { font-size: .8rem; color: rgb(113 113 122); margin-top: .15rem; }

        /* Tres columnas del mismo ancho, separadas por una línea: una por descuento. */
        .dsc-columnas {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 1.1rem;
        }
        .dsc-col { display: flex; flex-direction: column; min-width: 0; padding: 0 1.25rem; }
        .dsc-col:first-child { padding-left: 0; }
        .dsc-col:last-child { padding-right: 0; }
        .dsc-col + .dsc-col { border-left: 1px solid rgb(244 244 245); }

        .dsc-col-cabeza { display: flex; align-items: center; justify-content: space-between; gap: .5rem; min-height: 1.5rem; }
        .dsc-nombre { font-size: .9rem; font-weight: 600; color: rgb(24 24 27); }
        .dsc-toca { font-size: .78rem; color: rgb(113 113 122); margin-top: .1rem; }

        .dsc-estado {
            flex: 0 0 auto; padding: .15rem .55rem; border-radius: 999px; white-space: nowrap;
            font-size: .72rem; font-weight: 600; font-variant-numeric: tabular-nums;
            color: rgb(180 83 9); background: rgb(255 247 237); box-shadow: inset 0 0 0 1px rgb(253 186 116 / .7);
        }

        /* El campo con su unidad ADENTRO: el número a la derecha, la unidad pegada. */
        .dsc-campo {
            display: flex; align-items: center; gap: .35rem; height: 2.5rem; margin-top: .65rem;
            padding: 0 .3rem 0 .75rem; border-radius: .5rem;
            background: rgb(255 255 255); box-shadow: inset 0 0 0 1px rgb(212 212 216);
        }
        .dsc-campo:focus-within { box-shadow: inset 0 0 0 2px rgb(217 119 6); }
        .dsc-campo input {
            flex: 1 1 auto; min-width: 0; height: 100%; border: 0; outline: none; padding: 0;
            background: transparent; color: rgb(24 24 27); font-size: 1rem; font-weight: 500;
            text-align: right; font-variant-numeric: tabular-nums;
            -moz-appearance: textfield; appearance: textfield;
        }
        .dsc-campo input::-webkit-outer-spin-button,
        .dsc-campo input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .dsc-campo input::placeholder { color: rgb(161 161 170); }
        .dsc-campo input:disabled { cursor: not-allowed; opacity: .45; }
        .dsc-sufijo { padding: 0 .55rem 0 .1rem; font-size: .9rem; font-weight: 500; color: rgb(113 113 122); }
        .dsc-prefijo { font-size: .9rem; font-weight: 500; color: rgb(113 113 122); }

        /* El interruptor % | L: dos botones chicos en un riel, adentro del campo. */
        .dsc-modo {
            flex: 0 0 auto; display: inline-flex; gap: .15rem; padding: .15rem;
            border-radius: .4rem; background: rgb(244 244 245);
        }
        .dsc-modo button {
            min-width: 1.85rem; height: 1.75rem; padding: 0 .45rem; border: 0; border-radius: .3rem;
            background: transparent; color: rgb(113 113 122); font-size: .8rem; font-weight: 600; cursor: pointer;
        }
        .dsc-modo button:hover { color: rgb(24 24 27); }
        .dsc-modo button:focus-visible { outline: 2px solid rgb(217 119 6); outline-offset: 1px; }
        .dsc-modo button.activo { background: rgb(217 119 6); color: rgb(255 255 255); }

        .dsc-tope {
            display: flex; align-items: center; gap: .35rem; margin-top: .45rem;
            font-size: .78rem; line-height: 1.4; color: rgb(82 82 91);
        }
        .dsc-info { display: inline-flex; flex: 0 0 auto; color: rgb(161 161 170); cursor: help; border-radius: 999px; }
        .dsc-info svg { width: 1rem; height: 1rem; }
        .dsc-info:hover,
        .dsc-info:focus-visible { color: rgb(217 119 6); outline: none; }

        .dsc-porque { margin-top: .6rem; }
        .dsc-motivo {
            width: 100%; height: 2.25rem; padding: 0 .7rem; border: 0; border-radius: .5rem; outline: none;
            font-size: .82rem; background: rgb(255 255 255); color: rgb(24 24 27);
            box-shadow: inset 0 0 0 1px rgb(212 212 216);
        }
        .dsc-motivo:focus { box-shadow: inset 0 0 0 2px rgb(217 119 6); }
        .dsc-motivo::placeholder { text-transform: none; color: rgb(161 161 170); }
        .dsc-falta { margin-top: .3rem; font-size: .72rem; color: rgb(217 119 6); }

        @media (max-width: 1023px) {
            .dsc-columnas { grid-template-columns: minmax(0, 1fr); }
            .dsc-col,
            .dsc-col:first-child,
            .dsc-col:last-child { padding: 1rem 0; }
            .dsc-col:first-child { padding-top: 0; }
            .dsc-col:last-child { padding-bottom: 0; }
            .dsc-col + .dsc-col { border-left: 0; border-top: 1px solid rgb(244 244 245); }
        }

        .dark .dsc { background: transparent; border-color: rgb(255 255 255 / .1); }
        .dark .dsc-titulo,
        .dark .dsc-nombre { color: rgb(250 250 250); }
        .dark .dsc-bajada,
        .dark .dsc-toca { color: rgb(161 161 170); }
        .dark .dsc-col + .dsc-col { border-color: rgb(255 255 255 / .08); }
        .dark .dsc-estado { color: rgb(252 211 77); background: rgb(245 158 11 / .12); box-shadow: inset 0 0 0 1px rgb(245 158 11 / .35); }
        .dark .dsc-campo,
        .dark .dsc-motivo { background: rgb(255 255 255 / .05); box-shadow: inset 0 0 0 1px rgb(255 255 255 / .12); }
        .dark .dsc-campo:focus-within,
        .dark .dsc-motivo:focus { box-shadow: inset 0 0 0 2px rgb(217 119 6); }
        .dark .dsc-campo input,
        .dark .dsc-motivo { color: rgb(250 250 250); }
        .dark .dsc-campo input::placeholder,
        .dark .dsc-motivo::placeholder { color: rgb(113 113 122); }
        .dark .dsc-sufijo,
        .dark .dsc-prefijo { color: rgb(161 161 170); }
        .dark .dsc-modo { background: rgb(255 255 255 / .07); }
        .dark .dsc-modo button { color: rgb(161 161 170); }
        .dark .dsc-modo button:hover { color: rgb(250 250 250); }
        .dark .dsc-modo button.activo { background: rgb(217 119 6); color: rgb(255 255 255); }
        .dark .dsc-tope { color: rgb(212 212 216); }
        .dark .dsc-info { color: rgb(113 113 122); }
    </style>
@endif
