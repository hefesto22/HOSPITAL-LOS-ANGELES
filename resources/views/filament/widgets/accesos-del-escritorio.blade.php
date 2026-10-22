{{--
    El saludo y los botones grandes del escritorio.

    Botones de verdad, no una lista de enlaces: se aprietan con el dedo
    desde el celular caminando por el piso, y a las tres de la mañana nadie
    lee un menú. El CSS de Filament viene precompilado (§9.A7): lo que se
    ve acá va en el estilo propio de abajo.
--}}
@php
    $accesos = $this->accesos();
    $nombre = $this->nombre();
    $roles = $this->roles();
@endphp

<x-filament-widgets::widget>
    <div class="sihla-escritorio">
        <div class="sihla-escritorio-saludo">
            <p class="sihla-escritorio-fecha">{{ $this->hoy() }}</p>
            <h2 class="sihla-escritorio-hola">
                {{ $this->saludo() }}{{ $nombre !== '' ? ', '.$nombre : '' }}
            </h2>
            @if ($roles !== '')
                <p class="sihla-escritorio-rol">{{ $roles }}</p>
            @endif
        </div>

        @if (count($accesos) > 0)
            <div class="sihla-escritorio-accesos">
                @foreach ($accesos as $acceso)
                    <a
                        href="{{ $acceso['url'] }}"
                        @class(['sihla-acceso', 'sihla-acceso-principal' => $acceso['principal']])
                    >
                        <x-filament::icon :icon="$acceso['icono']" class="sihla-acceso-icono" />
                        <span class="sihla-acceso-texto">
                            <span class="sihla-acceso-etiqueta">{{ $acceso['etiqueta'] }}</span>
                            <span class="sihla-acceso-detalle">{{ $acceso['detalle'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <p class="sihla-escritorio-vacio">
                Tu usuario todavía no tiene pantallas asignadas. Pedíselas a dirección.
            </p>
        @endif
    </div>

    <style>
        .sihla-escritorio {
            display: flex; flex-direction: column; gap: 1rem;
            padding: 1.25rem; border-radius: .75rem;
            border: 1px solid rgb(228 228 231); background: rgb(255 255 255);
        }

        .sihla-escritorio-fecha { font-size: .8rem; color: rgb(113 113 122); }
        .sihla-escritorio-hola {
            margin-top: .15rem; font-size: 1.35rem; font-weight: 700; line-height: 1.2;
            color: rgb(24 24 27);
        }
        .sihla-escritorio-rol { margin-top: .2rem; font-size: .85rem; color: rgb(82 82 91); }

        .sihla-escritorio-accesos {
            display: grid; gap: .6rem;
            grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
        }

        .sihla-acceso {
            display: flex; align-items: center; gap: .7rem;
            min-height: 3.75rem; padding: .7rem .85rem; border-radius: .65rem;
            border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
            color: rgb(39 39 42); text-decoration: none;
            transition: border-color .15s ease, background .15s ease, transform .15s ease;
        }
        .sihla-acceso:hover { border-color: rgb(161 161 170); background: rgb(244 244 245); transform: translateY(-1px); }

        /*
         * ⚠️ En Filament v5 `--primary-500` es un color entero (`oklch(…)`),
         * no tres canales: va con `var()` solo, nunca dentro de `rgb()`.
         */
        .sihla-acceso-principal {
            border-color: var(--primary-500); background: var(--primary-500);
            color: rgb(255 255 255);
        }
        .sihla-acceso-principal:hover {
            border-color: var(--primary-600); background: var(--primary-600);
        }

        .sihla-acceso-icono { width: 1.6rem; height: 1.6rem; flex: none; }
        .sihla-acceso-texto { display: flex; flex-direction: column; min-width: 0; }
        .sihla-acceso-etiqueta { font-size: .92rem; font-weight: 600; line-height: 1.2; }
        .sihla-acceso-detalle {
            font-size: .74rem; line-height: 1.25; opacity: .75;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .sihla-escritorio-vacio { font-size: .9rem; color: rgb(82 82 91); }

        /* En el celular, dos por fila: el dedo alcanza y no hay que buscar. */
        @media (max-width: 480px) {
            .sihla-escritorio { padding: 1rem; }
            .sihla-escritorio-accesos { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .sihla-acceso { flex-direction: column; align-items: flex-start; gap: .4rem; }
            .sihla-acceso-detalle { white-space: normal; }
        }

        .dark .sihla-escritorio { background: rgb(24 24 27); border-color: rgb(63 63 70); }
        .dark .sihla-escritorio-hola { color: rgb(244 244 245); }
        .dark .sihla-escritorio-rol,
        .dark .sihla-escritorio-vacio { color: rgb(161 161 170); }
        .dark .sihla-acceso { background: rgb(39 39 42); border-color: rgb(63 63 70); color: rgb(228 228 231); }
        .dark .sihla-acceso:hover { background: rgb(52 52 56); border-color: rgb(113 113 122); }
        .dark .sihla-acceso-principal {
            border-color: var(--primary-500); background: var(--primary-500); color: rgb(24 24 27);
        }
        .dark .sihla-acceso-principal:hover { background: var(--primary-400); border-color: var(--primary-400); }
    </style>
</x-filament-widgets::widget>
