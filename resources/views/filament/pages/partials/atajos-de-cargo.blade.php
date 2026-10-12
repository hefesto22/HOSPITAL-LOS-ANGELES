{{--
    La banda de arriba del modal: a la izquierda lo que más se carga, a la
    derecha el descuento del hospital.

    Los atajos van ARRIBA del formulario porque son el camino rápido y el
    formulario es la excepción. Una ronda de medicamentos son veinte líneas
    y casi siempre las mismas seis cosas: buscarlas por nombre veinte veces
    es el trabajo, apretarlas es el atajo.

    El descuento vive acá y no adentro del formulario porque es de otra
    naturaleza: no se decide por línea sino por tanda, y no lo decide quien
    teclea sino quien autoriza. Se pone una vez y queda puesto hasta que se
    borre o se cambie de cuenta.

    Son DOS desde el 11-oct-2026 (Mauricio): el de farmacia, solo para
    medicamentos y materiales, y el de servicios, para todo lo demás, en
    porcentaje o en lempiras. Cada uno con sus topes.
--}}
@php($cuenta = $cuenta ?? null)
@php($servicios = $servicios ?? ['grupos' => [], 'maximo' => null, 'puede' => false, 'valor' => null, 'modo' => 'porcentaje', 'motivo' => null])

@if ($cuenta && $puede)
    <div class="sihla-cabecera">
        <div class="sihla-atajos">
            @if ($items->isNotEmpty())
                <p class="sihla-atajos-titulo">Lo que más se carga</p>

                <div class="sihla-atajos-fila">
                    @foreach ($items as $item)
                        <button
                            type="button"
                            wire:key="atajo-{{ $cuenta->id }}-{{ $item->id }}"
                            wire:click="agregarRapido({{ $cuenta->id }}, {{ $item->id }})"
                            wire:loading.attr="disabled"
                            class="sihla-atajo"
                            title="{{ $item->nombre }}"
                        >
                            <span class="sihla-atajo-mas">+</span>
                            <span class="sihla-atajo-nombre">{{ $item->nombre }}</span>
                            @if ($item->mueveInventario())
                                <span class="sihla-atajo-nota">pide almacén</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{--
            🔴 El «¿por qué?» aparece con Alpine y NO esperando al servidor.

            Con `wire:model.blur` solo, el campo del motivo aparecía recién
            en el siguiente render — y si quien atiende teclea 30 y aprieta
            Agregar de una, el cargo se rechaza por falta de motivo con un
            aviso rojo que parece un error del sistema. El número se escribe
            y la pregunta ya está ahí.

            🔴 `wire:model.live.blur`, NO `wire:model.blur` (11-oct-2026).
            En Livewire 4 el `.blur` solo, sin `.live`, ya no le avisa al
            servidor: guarda el valor en el navegador y nada más. El
            descuento quedaba esperando hasta que alguien apretara Agregar,
            y quien solo quería rebajar lo ya cargado lo veía no hacer
            nada. Con `.live.blur` se aplica al salir del campo, y Enter
            sale del campo en vez de mandar el formulario de abajo.
        --}}
        <div class="sihla-descuentos">
        <div class="sihla-descuento" x-data="{ desc: @js($descuento), motivo: @js($motivo ?? '') }">
            <label class="sihla-descuento-titulo" for="sihla-descuento-tanda">Descuento en farmacia</label>

            <div class="sihla-descuento-campo">
                <input
                    id="sihla-descuento-tanda"
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
                <span class="sihla-descuento-signo">%</span>
            </div>

            <p class="sihla-descuento-ayuda">{{ $ayuda }}</p>

            {{--
                El motivo aparece solo cuando hay algo que justificar. Un
                campo siempre visible se llena con «ok» por costumbre; uno
                que aparece cuando se tecleó el número se lee como lo que
                es: la pregunta de por qué.
            --}}
            <input
                type="text"
                class="sihla-descuento-motivo"
                style="text-transform: uppercase"
                maxlength="200"
                placeholder="¿Por qué? Lo puede dar cualquiera"
                wire:model.live.blur="motivoDeLaTanda"
                x-on:input="motivo = $event.target.value"
                x-on:keydown.enter.prevent="$el.blur()"
                x-show="Number(desc) > 0"
                x-cloak
            >

            {{--
                🔴 El faltante se cuenta ANTES de apretar Agregar.

                El mínimo de diez caracteres existe para que «ok» no cuente
                como motivo, pero enterarse de él recién cuando el cargo se
                rechaza parece una falla del sistema. Contando lo que falta
                mientras se escribe, la regla se entiende sin leerla.
            --}}
            <p class="sihla-descuento-falta" x-show="Number(desc) > 0 && motivo.trim().length < 10" x-cloak>
                Faltan <span x-text="10 - motivo.trim().length"></span> caracteres para que quede explicado.
            </p>

        </div>

        {{--
            EL DESCUENTO EN SERVICIOS (11-oct-2026).

            Todo lo que no sale de farmacia: hospitalización, consultas,
            laboratorio, rayos X. En % o en L. Los topes se dicen antes de
            teclear, porque cada cosa aguanta distinto según la ley: teclear
            30 y ver que quedó en 20 parece un error si nadie lo avisó.

            `wire:key` con el modo: al pasar de % a L, Alpine vuelve a
            nacer con el campo vacío y el motivo escondido, en vez de
            quedarse con el número de antes.
        --}}
        <div
            class="sihla-descuento"
            wire:key="servicios-{{ $cuenta->id }}-{{ $servicios['modo'] }}"
            x-data="{ desc: @js($servicios['valor']), motivo: @js($servicios['motivo'] ?? '') }"
        >
            <label class="sihla-descuento-titulo" for="sihla-descuento-servicios">Descuento en servicios</label>

            <div class="sihla-descuento-fila">
                <div class="sihla-descuento-modo" role="group" aria-label="Cómo se rebaja">
                    <button
                        type="button"
                        wire:click="cambiarModoDeServicios('porcentaje')"
                        @class(['activo' => $servicios['modo'] === 'porcentaje'])
                    >%</button>
                    <button
                        type="button"
                        wire:click="cambiarModoDeServicios('monto')"
                        @class(['activo' => $servicios['modo'] === 'monto'])
                    >L</button>
                </div>

                <div class="sihla-descuento-campo">
                    @if ($servicios['modo'] === 'monto')
                        <span class="sihla-descuento-signo">L</span>
                    @endif
                    <input
                        id="sihla-descuento-servicios"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        placeholder="0"
                        @class(['sihla-descuento-monto' => $servicios['modo'] === 'monto'])
                        wire:model.live.blur="descuentoServicios"
                        x-on:input="desc = $event.target.value"
                        x-on:keydown.enter.prevent="$el.blur()"
                        @disabled(! $servicios['puede'])
                    >
                    @if ($servicios['modo'] === 'porcentaje')
                        <span class="sihla-descuento-signo">%</span>
                    @endif
                </div>
            </div>

            <ul class="sihla-descuento-topes">
                @foreach ($servicios['grupos'] as $grupo)
                    <li><strong>{{ $grupo['tope'] }}</strong> · {{ $grupo['que'] }}</li>
                @endforeach
            </ul>

            <p class="sihla-descuento-ayuda">
                @if ($servicios['modo'] === 'monto' && $servicios['maximo'] !== null)
                    Se reparte entre lo cargado: hasta L {{ number_format((float) $servicios['maximo'], 2) }}.
                @endif
                No toca farmacia, honorarios ni paquetes.
            </p>

            <input
                type="text"
                class="sihla-descuento-motivo"
                style="text-transform: uppercase"
                maxlength="200"
                placeholder="¿Por qué? Lo puede dar cualquiera"
                wire:model.live.blur="motivoServicios"
                x-on:input="motivo = $event.target.value"
                x-on:keydown.enter.prevent="$el.blur()"
                x-show="Number(desc) > 0"
                x-cloak
            >

            <p class="sihla-descuento-falta" x-show="Number(desc) > 0 && motivo.trim().length < 10" x-cloak>
                Faltan <span x-text="10 - motivo.trim().length"></span> caracteres para que quede explicado.
            </p>
        </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
        .sihla-cabecera {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 1.25rem; margin-bottom: 1rem;
        }
        .sihla-atajos { flex: 1 1 auto; min-width: 0; }
        .sihla-atajos-titulo {
            font-size: .75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .04em; color: rgb(113 113 122); margin-bottom: .5rem;
        }
        .sihla-atajos-fila { display: flex; flex-wrap: wrap; gap: .4rem; }
        .sihla-atajo {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px; cursor: pointer;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            font-size: .78rem; line-height: 1.2; color: rgb(39 39 42);
            transition: border-color .12s ease, background .12s ease;
        }
        .sihla-atajo:hover { border-color: rgb(161 161 170); background: rgb(244 244 245); }
        .sihla-atajo:disabled { opacity: .5; cursor: progress; }
        .sihla-atajo-mas { font-weight: 700; color: rgb(180 120 20); }
        .sihla-atajo-nombre {
            max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .sihla-atajo-nota { font-size: .65rem; color: rgb(161 161 170); }

        .sihla-descuentos { flex: 0 0 auto; display: flex; flex-direction: column; gap: .9rem; }
        .sihla-descuento { flex: 0 0 auto; text-align: right; }
        .sihla-descuento-fila { display: inline-flex; align-items: stretch; gap: .4rem; }
        .sihla-descuento-modo {
            display: inline-flex; overflow: hidden;
            border: 1px solid rgb(228 228 231); border-radius: .5rem;
        }
        .sihla-descuento-modo button {
            padding: 0 .6rem; font-size: .78rem; font-weight: 600; cursor: pointer;
            background: rgb(255 255 255); color: rgb(113 113 122); border: 0;
        }
        .sihla-descuento-modo button + button { border-left: 1px solid rgb(228 228 231); }
        .sihla-descuento-modo button.activo { background: rgb(180 120 20); color: rgb(255 255 255); }
        .sihla-descuento-campo input.sihla-descuento-monto { width: 6.5rem; }
        .sihla-descuento-topes {
            list-style: none; margin: .35rem 0 0; padding: 0;
            font-size: .7rem; line-height: 1.35; color: rgb(161 161 170);
        }
        .sihla-descuento-topes strong { color: rgb(113 113 122); font-weight: 600; }
        .sihla-descuento-titulo {
            display: block; font-size: .75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .04em; color: rgb(113 113 122); margin-bottom: .5rem;
        }
        .sihla-descuento-campo {
            display: inline-flex; align-items: stretch; overflow: hidden;
            border: 1px solid rgb(228 228 231); border-radius: .5rem;
            background: rgb(255 255 255);
        }
        .sihla-descuento-campo:focus-within { border-color: rgb(180 120 20); }
        .sihla-descuento-campo input {
            width: 4.25rem; border: 0; outline: none; background: transparent;
            padding: .35rem .5rem; font-size: .85rem; text-align: right; color: rgb(39 39 42);
        }
        .sihla-descuento-campo input:disabled { cursor: not-allowed; opacity: .5; }
        .sihla-descuento-signo {
            display: flex; align-items: center; padding: 0 .55rem;
            background: rgb(244 244 245); font-size: .78rem; color: rgb(113 113 122);
        }
        .sihla-descuento-ayuda {
            font-size: .7rem; color: rgb(161 161 170); margin-top: .3rem; max-width: 15rem;
        }
        .sihla-descuento-motivo {
            display: block; margin-top: .45rem; width: 17rem; text-align: left;
            border: 1px solid rgb(228 228 231); border-radius: .5rem;
            padding: .35rem .55rem; font-size: .8rem; outline: none;
            background: rgb(255 255 255); color: rgb(39 39 42);
        }
        .sihla-descuento-motivo:focus { border-color: rgb(180 120 20); }
        .sihla-descuento-falta {
            font-size: .7rem; color: rgb(180 120 20); margin-top: .3rem;
            max-width: 17rem; margin-left: auto; text-align: left;
        }

        .dark .sihla-atajo { background: rgb(39 39 42); border-color: rgb(63 63 70); color: rgb(228 228 231); }
        .dark .sihla-atajo:hover { background: rgb(52 52 56); border-color: rgb(113 113 122); }
        .dark .sihla-atajos-titulo,
        .dark .sihla-descuento-titulo { color: rgb(161 161 170); }
        .dark .sihla-descuento-campo,
        .dark .sihla-descuento-motivo { background: rgb(39 39 42); border-color: rgb(63 63 70); color: rgb(228 228 231); }
        .dark .sihla-descuento-campo input,
        .dark .sihla-descuento-motivo { color: rgb(228 228 231); }
        .dark .sihla-descuento-signo { background: rgb(52 52 56); color: rgb(161 161 170); }
        .dark .sihla-descuento-modo { border-color: rgb(63 63 70); }
        .dark .sihla-descuento-modo button { background: rgb(39 39 42); color: rgb(161 161 170); }
        .dark .sihla-descuento-modo button + button { border-left-color: rgb(63 63 70); }
        .dark .sihla-descuento-modo button.activo { background: rgb(180 120 20); color: rgb(255 255 255); }
        .dark .sihla-descuento-topes strong { color: rgb(212 212 216); }
    </style>
@endif
