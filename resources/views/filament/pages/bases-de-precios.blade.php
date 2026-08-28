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
    EL MISMO NÚMERO SIGNIFICA DOS COSAS DISTINTAS
    ─────────────────────────────────────────────────────────────────────

    EN EL PRECIO DE LISTA, «8 sin precio» es lo accionable y va en rojo:
    cada uno de esos ocho es una discusión en el mostrador esperando a
    que alguien lo pida. «131 con precio» tranquiliza y no sirve de nada.

    EN LA BASE DE UN SEGURO, ese mismo número NO es una alarma: es lo que
    no se pactó, y lo que no se pactó se cobra al precio de lista, que es
    lo correcto. Ahí se rotula «sin pactar» y va en gris. Pintarlo de
    rojo empujaría a alguien a completarlo, y completar un tarifario que
    nadie firmó es inventar un descuento.
--}}
<x-filament-panels::page>
    @php($bases = $this->bases())
    @php($resumen = $this->resumenDeLaBase())

    <div class="sihla-bases">
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
                <div class="sihla-cifras">
                    <div class="sihla-cifra">
                        <span class="sihla-cifra-valor">{{ number_format($resumen['conPrecio']) }}</span>
                        <span class="sihla-cifra-rotulo">con precio</span>
                    </div>

                    <div @class([
                        'sihla-cifra',
                        'sihla-cifra-alerta' => $this->convenioId === null && $resumen['sinPrecio'] > 0,
                        'sihla-cifra-tenue' => $this->convenioId !== null,
                    ])>
                        <span class="sihla-cifra-valor">{{ number_format($resumen['sinPrecio']) }}</span>
                        <span class="sihla-cifra-rotulo">
                            {{ $this->convenioId === null ? 'sin precio' : 'sin pactar' }}
                        </span>
                    </div>

                    <div class="sihla-cifra sihla-cifra-tenue">
                        <span class="sihla-cifra-valor">{{ number_format($resumen['total']) }}</span>
                        <span class="sihla-cifra-rotulo">en el catálogo</span>
                    </div>
                </div>

                <div class="sihla-acciones">
                    {{ $this->agregarItemAction }}
                    {{ $this->copiarBaseAction }}
                </div>
            </div>

            <p class="sihla-leyenda">
                @if ($this->convenioId === null)
                    Este es el precio del hospital: el que se le cobra a quien paga de su bolsillo
                    y a cualquier pagador que no tenga precio propio para el ítem.
                @else
                    Acá está lo pactado con <strong>{{ $this->nombreDeLaBase() }}</strong>: estos
                    precios le ganan al de lista cuando el paciente viene por ese pagador. Lo que
                    no aparece en esta base no es un hueco — se cobra al precio de lista. Para
                    sumar un ítem, «Agregar ítem».
                @endif
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

        .sihla-toggle {
            display: flex; flex-wrap: wrap; gap: .25rem;
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

        /* ── Las cifras y el botón ────────────────────────────────────── */

        .sihla-barra {
            display: flex; flex-direction: column; gap: 1rem;
            align-items: flex-start;
        }
        @media (min-width: 768px) {
            .sihla-barra { flex-direction: row; align-items: center; justify-content: space-between; }
        }

        .sihla-cifras { display: flex; align-items: stretch; gap: 1.5rem; }

        .sihla-cifra { display: flex; flex-direction: column; gap: .1rem; }

        .sihla-cifra-valor {
            font-size: 1.375rem; font-weight: 600; line-height: 1.1;
            font-variant-numeric: tabular-nums;
            color: rgb(24 24 27);
        }
        .dark .sihla-cifra-valor { color: rgb(244 244 245); }

        .sihla-cifra-rotulo {
            font-size: .6875rem; font-weight: 500;
            letter-spacing: .04em; text-transform: uppercase;
            color: rgb(113 113 122);
        }
        .dark .sihla-cifra-rotulo { color: rgb(113 113 122); }

        .sihla-cifra-alerta .sihla-cifra-valor { color: rgb(220 38 38); }
        .dark .sihla-cifra-alerta .sihla-cifra-valor { color: rgb(248 113 113); }

        .sihla-cifra-tenue .sihla-cifra-valor { color: rgb(161 161 170); font-weight: 500; }
        .dark .sihla-cifra-tenue .sihla-cifra-valor { color: rgb(113 113 122); }

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
