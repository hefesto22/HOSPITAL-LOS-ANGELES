{{--
    El catálogo entero con el precio de un pagador, y el selector arriba.

    ─────────────────────────────────────────────────────────────────────
    EL SELECTOR NO ES UN FILTRO Y POR ESO NO VA EN LA TABLA
    ─────────────────────────────────────────────────────────────────────

    Un filtro cambia QUÉ FILAS se ven. Esto cambia QUÉ NÚMERO SE EDITA:
    la misma fila, el mismo ítem, pero el precio de otro pagador. Editar
    el de PALIG creyendo que se edita el de lista no se descubre hasta la
    conciliación a sesenta días, cuando ya se facturó con él.

    Por eso está arriba de todo, grande, con la base actual claramente
    marcada y con el nombre repetido en la barra de abajo. Es redundante a
    propósito: la redundancia acá cuesta dos líneas y evita facturar mal.

    ─────────────────────────────────────────────────────────────────────
    LAS VISTAS DICEN CUÁNTO HAY EN CADA UNA (6-nov-2026)
    ─────────────────────────────────────────────────────────────────────

    EN EL PRECIO DE LISTA, «Pendientes» es lo accionable y su número va
    en rojo: cada uno es un ítem que en caja no se le puede cobrar a un
    particular. El L 10 de relleno cuenta como pendiente.

    EN LA BASE DE UN SEGURO no hay fichas: se ve solo lo pactado
    (Mauricio, 6-nov-2026: «Sin pactar» y «Todos» confundían). Lo nuevo
    entra por «Agregar ítem».

    ENTER GUARDA Y SALTA A LA FILA SIGUIENTE. El campo de precio de
    Filament guarda al cambiar; acá solo se mueve el foco al siguiente
    campo habilitado, así que cargar una base es escribir y Enter.
--}}
<x-filament-panels::page>
    @php($bases = $this->bases())
    @php($vistas = $this->vistas())
    @php($categorias = $this->categorias())
    @php($vista = $this->vistaActual())
    @php($nombre = $this->nombreDeLaBase())

    <div
        class="sihla-bases"
        x-data
        x-on:keydown.enter="
            if (! $event.target.matches('.fi-ta-text-input input')) return;
            $event.preventDefault();
            const campos = [...$el.querySelectorAll('.fi-ta-text-input input:not([disabled])')];
            const siguiente = campos[campos.indexOf($event.target) + 1];
            $event.target.blur();
            if (siguiente) { siguiente.focus(); siguiente.select(); }
        "
    >
        <div class="sihla-panel">
            <div class="sihla-toggle" role="tablist" aria-label="Base de precios">
                @foreach ($bases as $base)
                    @php($activa = $this->convenioId === $base['id'])

                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $activa ? 'true' : 'false' }}"
                        wire:key="base-{{ $base['id'] ?? 'lista' }}"
                        wire:click="cambiarDeBase({{ $base['id'] === null ? 'null' : $base['id'] }})"
                        wire:loading.attr="disabled"
                        @class(['sihla-base', 'sihla-base-activa' => $activa])
                    >
                        <span class="sihla-base-nombre">{{ $base['nombre'] }}</span>
                        <span class="sihla-base-cuantos">{{ number_format($base['cuantos']) }}</span>
                    </button>
                @endforeach
            </div>

            <div class="sihla-barra">
                <div class="sihla-vistas" role="tablist" aria-label="Qué se ve de esta base">
                    @foreach (count($vistas) > 1 ? $vistas : [] as $opcion)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $opcion['activa'] ? 'true' : 'false' }}"
                            wire:key="vista-{{ $opcion['clave'] }}"
                            wire:click="cambiarDeVista('{{ $opcion['clave'] }}')"
                            wire:loading.attr="disabled"
                            @class([
                                'sihla-vista',
                                'sihla-vista-activa' => $opcion['activa'],
                                'sihla-vista-alerta' => $opcion['alerta'],
                            ])
                        >
                            <span>{{ $opcion['rotulo'] }}</span>
                            <span class="sihla-vista-cuantos">{{ number_format($opcion['cuantos']) }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="sihla-acciones">
                    {{ $this->agregarItemAction }}
                    {{ $this->copiarBaseAction }}
                </div>
            </div>

            @if (count($categorias) > 1 || $this->categoriaId !== null)
                <div class="sihla-categorias" aria-label="Categoría">
                    <button
                        type="button"
                        wire:key="categoria-todas"
                        wire:click="cambiarDeCategoria(null)"
                        wire:loading.attr="disabled"
                        @class(['sihla-categoria', 'sihla-categoria-activa' => $this->categoriaId === null])
                    >
                        Todas
                    </button>

                    @foreach ($categorias as $categoria)
                        <button
                            type="button"
                            wire:key="categoria-{{ $categoria['id'] }}"
                            wire:click="cambiarDeCategoria({{ $categoria['id'] }})"
                            wire:loading.attr="disabled"
                            @class(['sihla-categoria', 'sihla-categoria-activa' => $this->categoriaId === $categoria['id']])
                        >
                            {{ $categoria['nombre'] }}
                            <span class="sihla-categoria-cuantos">{{ number_format($categoria['cuantos']) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <p class="sihla-leyenda">
                @switch ($vista)
                    @case ('pendientes')
                        Estos ítems todavía no tienen precio de lista —o tienen el L 10 de relleno— y en caja
                        no se le pueden cobrar a un particular. Escribí el precio y <strong>Enter</strong>:
                        se guarda y pasa al siguiente.
                        @break
                    @case ('reales')
                        El precio del hospital: el que paga quien viene de su bolsillo y cualquier pagador
                        que no tenga precio propio para el ítem.
                        @break
                    @case ('pactados')
                        Lo pactado con <strong>{{ $nombre }}</strong>: estos precios le ganan al de lista
                        cuando el paciente viene por ese pagador. Lo que no está acá se cobra al precio de
                        lista; para pactar algo nuevo, «Agregar ítem».
                        @break
                    @default
                        Todo el catálogo con el precio del hospital. Los vacíos dicen «pendiente».
                @endswitch
            </p>
        </div>

        {{ $this->table }}
    </div>

    <x-filament-actions::modals />

    <style>
        .sihla-bases { display: flex; flex-direction: column; gap: 1.25rem; }

        /* ── El panel que agrupa selector, cifras y leyenda ───────────── */

        .sihla-panel {
            display: flex; flex-direction: column; gap: 1rem;
            padding: 1rem;
            border-radius: .875rem;
            border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            box-shadow: 0 1px 2px rgb(0 0 0 / .04);
        }
        .dark .sihla-panel {
            border-color: rgb(63 63 70);
            background: rgb(24 24 27);
            box-shadow: none;
        }

        /* ── El selector de base ──────────────────────────────────────── */

        /*
         * ⚠️ La pista NO se estira a todo el ancho del panel.
         *
         * Con `display: flex` a secas ocupaba la tarjeta entera y las tres
         * bases quedaban apiladas contra el borde izquierdo, con medio
         * metro de gris vacío a la derecha. Centrar el CONTENIDO no
         * alcanza: dejaría dos huecos grises en vez de uno.
         *
         * `width: fit-content` hace que la pista abrace a sus opciones
         * —que es como se ve un control segmentado— y `margin-inline: auto`
         * la centra. `max-width` + `justify-content` son para cuando hay
         * tantos pagadores que tiene que envolver: las filas de abajo
         * quedan centradas y la pista nunca desborda a 360 px.
         */
        .sihla-toggle {
            display: flex; flex-wrap: wrap; gap: .25rem;
            justify-content: center;
            width: fit-content; max-width: 100%;
            margin-inline: auto;
            padding: .3rem;
            border-radius: .75rem;
            background: rgb(244 244 245);
        }
        .dark .sihla-toggle { background: rgb(39 39 42); }

        .sihla-base {
            display: inline-flex; align-items: center; gap: .5rem;
            padding: .5rem .85rem;
            border-radius: .55rem;
            cursor: pointer;
            font-size: .8125rem; font-weight: 500; line-height: 1.2;
            color: rgb(82 82 91);
            border: 0; background: transparent;
            transition: background .12s ease, color .12s ease, box-shadow .12s ease;
        }
        .sihla-base:hover { background: rgb(228 228 231); color: rgb(24 24 27); }
        .sihla-base:disabled { opacity: .55; cursor: progress; }
        .sihla-base:focus-visible {
            outline: 2px solid rgb(245 158 11);
            outline: 2px solid var(--primary-500);
            outline-offset: 1px;
        }
        .dark .sihla-base { color: rgb(161 161 170); }
        .dark .sihla-base:hover { background: rgb(52 52 56); color: rgb(244 244 245); }

        /*
         * La activa se marca con el color primario y no con «blanco sobre
         * gris»: en modo oscuro esa diferencia era de dos tonos de gris
         * casi iguales, y la base que se está editando tiene que ser
         * imposible de confundir.
         */
        /*
         * ⚠️ En Filament v5 `--primary-500` guarda un COLOR entero
         * (`oklch(…)`), no los tres canales sueltos. `rgb(var(--primary-500))`
         * no es válido acá: para ponerle transparencia va `color-mix`,
         * que es lo que usa el propio Filament. Cada regla lleva primero
         * su equivalente en ámbar plano por si el navegador es viejo y no
         * entiende `color-mix`.
         */
        .sihla-base-activa,
        .sihla-base-activa:hover {
            background: rgb(245 158 11 / .12);
            background: color-mix(in oklab, var(--primary-500) 12%, transparent);
            color: rgb(217 119 6);
            color: var(--primary-600);
            font-weight: 600;
            box-shadow: inset 0 0 0 1px rgb(245 158 11 / .35);
            box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 35%, transparent);
        }
        .dark .sihla-base-activa,
        .dark .sihla-base-activa:hover {
            background: rgb(245 158 11 / .16);
            background: color-mix(in oklab, var(--primary-500) 16%, transparent);
            color: rgb(251 191 36);
            color: var(--primary-400);
        }

        .sihla-base-cuantos {
            min-width: 1.75rem; padding: .1rem .4rem;
            border-radius: 999px;
            font-size: .6875rem; font-weight: 600;
            font-variant-numeric: tabular-nums; text-align: center;
            background: rgb(228 228 231); color: rgb(113 113 122);
        }
        .dark .sihla-base-cuantos { background: rgb(63 63 70); color: rgb(161 161 170); }

        .sihla-base-activa .sihla-base-cuantos,
        .dark .sihla-base-activa .sihla-base-cuantos {
            background: rgb(245 158 11);
            background: var(--primary-500);
            color: rgb(255 255 255);
        }

        /* ── Las vistas, las acciones y las categorías ───────────────── */

        .sihla-barra {
            display: flex; flex-direction: column; gap: 1rem;
            align-items: flex-start;
        }
        @media (min-width: 768px) {
            .sihla-barra { flex-direction: row; align-items: center; justify-content: space-between; }
        }

        .sihla-vistas { display: flex; flex-wrap: wrap; gap: .5rem; }

        .sihla-vista {
            display: inline-flex; align-items: center; gap: .5rem;
            padding: .45rem .8rem;
            border-radius: .6rem;
            border: 1px solid rgb(228 228 231);
            background: transparent;
            cursor: pointer;
            font-size: .8125rem; font-weight: 500; line-height: 1.2;
            color: rgb(82 82 91);
            transition: background .12s ease, color .12s ease, border-color .12s ease;
        }
        .sihla-vista:hover { background: rgb(244 244 245); color: rgb(24 24 27); }
        .sihla-vista:disabled { opacity: .55; cursor: progress; }
        .sihla-vista:focus-visible {
            outline: 2px solid rgb(245 158 11);
            outline: 2px solid var(--primary-500);
            outline-offset: 1px;
        }
        .dark .sihla-vista { border-color: rgb(63 63 70); color: rgb(161 161 170); }
        .dark .sihla-vista:hover { background: rgb(39 39 42); color: rgb(244 244 245); }

        .sihla-vista-activa,
        .sihla-vista-activa:hover {
            border-color: rgb(245 158 11 / .45);
            border-color: color-mix(in oklab, var(--primary-500) 45%, transparent);
            background: rgb(245 158 11 / .12);
            background: color-mix(in oklab, var(--primary-500) 12%, transparent);
            color: rgb(217 119 6);
            color: var(--primary-600);
            font-weight: 600;
        }
        .dark .sihla-vista-activa,
        .dark .sihla-vista-activa:hover {
            background: rgb(245 158 11 / .16);
            background: color-mix(in oklab, var(--primary-500) 16%, transparent);
            color: rgb(251 191 36);
            color: var(--primary-400);
        }

        .sihla-vista-cuantos {
            min-width: 1.75rem; padding: .1rem .45rem;
            border-radius: 999px;
            font-size: .6875rem; font-weight: 600;
            font-variant-numeric: tabular-nums; text-align: center;
            background: rgb(228 228 231); color: rgb(82 82 91);
        }
        .dark .sihla-vista-cuantos { background: rgb(63 63 70); color: rgb(212 212 216); }

        .sihla-vista-activa .sihla-vista-cuantos {
            background: rgb(245 158 11);
            background: var(--primary-500);
            color: rgb(255 255 255);
        }
        .sihla-vista-alerta .sihla-vista-cuantos,
        .dark .sihla-vista-alerta .sihla-vista-cuantos {
            background: rgb(220 38 38); color: rgb(255 255 255);
        }

        .sihla-categorias { display: flex; flex-wrap: wrap; gap: .375rem; }

        .sihla-categoria {
            display: inline-flex; align-items: center; gap: .375rem;
            padding: .25rem .65rem;
            border-radius: 999px;
            border: 1px solid rgb(228 228 231);
            background: transparent;
            cursor: pointer;
            font-size: .75rem; font-weight: 500; line-height: 1.3;
            color: rgb(82 82 91);
        }
        .sihla-categoria:hover { background: rgb(244 244 245); color: rgb(24 24 27); }
        .sihla-categoria:disabled { opacity: .55; cursor: progress; }
        .dark .sihla-categoria { border-color: rgb(63 63 70); color: rgb(161 161 170); }
        .dark .sihla-categoria:hover { background: rgb(39 39 42); color: rgb(244 244 245); }

        .sihla-categoria-activa,
        .sihla-categoria-activa:hover,
        .dark .sihla-categoria-activa,
        .dark .sihla-categoria-activa:hover {
            border-color: rgb(245 158 11);
            border-color: var(--primary-500);
            color: rgb(217 119 6);
            color: var(--primary-500);
            font-weight: 600;
        }

        .sihla-categoria-cuantos {
            font-size: .6875rem; font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: rgb(161 161 170);
        }

        .sihla-acciones { display: flex; flex-wrap: wrap; gap: .5rem; flex-shrink: 0; }

        .sihla-leyenda {
            font-size: .8125rem; line-height: 1.5; max-width: 60ch;
            color: rgb(113 113 122);
        }
        .sihla-leyenda strong { color: rgb(63 63 70); font-weight: 600; }
        .dark .sihla-leyenda strong { color: rgb(228 228 231); }

        /* ── La columna de dinero ─────────────────────────────────────── */

        /*
         * Alineado a la derecha y con cifras de ancho fijo: una columna de
         * precios que no alinea las unidades obliga a leer dígito por
         * dígito para comparar dos filas, que es como un 1.080 y un 10.800
         * pasan por iguales.
         */
        .sihla-bases input[type="number"] {
            text-align: right;
            font-variant-numeric: tabular-nums;
            max-width: 9rem;
            margin-left: auto;
        }
        .sihla-bases input[type="number"]::-webkit-outer-spin-button,
        .sihla-bases input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none; margin: 0;
        }
        .sihla-bases input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
    </style>
</x-filament-panels::page>
