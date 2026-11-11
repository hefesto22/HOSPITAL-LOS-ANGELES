{{--
    El reporte de honorarios adentro de un modal (Mauricio, 11-nov-2026:
    «que no me redirija a una ventana, que se abra un modal y la ventana
    de impresión»).

    Es el mismo mecanismo que `facturas/modal.blade.php` —ahí está
    explicado por qué un iframe, por qué el CSS va acá adentro (§9.A7) y
    por qué la hoja se escala en vez de estirarse—. Lo que cambia:

      · La hoja es `honorarios.imprimir` con `?incrustada=1`: la misma
        página que se abría en pestaña aparte, sin su barra.
      · La ventana de imprimir se abre SOLA la primera vez que la hoja
        termina de cargar. Si se cierra, «Imprimir» la vuelve a abrir.
      · En el general se elige «Solo el resumen» o «Con la hoja de cada
        uno» sin cerrar el modal: cambia el iframe, y esa vez no se
        imprime sola —quien cambió está mirando, no imprimiendo—.
      · Mientras la hoja carga, el botón está apagado: imprimir antes
        mandaría una hoja en blanco.

    ⚠️ `contentWindow.print()` y no `window.print()`: el segundo manda a
    la impresora el panel entero con el reporte escondido adentro.

    También lo usa el Reporte del mes (11-nov-2026), con «Con las
    facturas» en vez de «Con la hoja de cada uno»: `$etiquetaConDetalle`.
--}}
<div
    x-data="{
        /** Una carta: 216 mm a 96 dpi. */
        ANCHO: 816,

        resumen: @js($enlace),
        conDetalle: @js($enlaceConDetalle),
        detalle: false,
        src: @js($enlace),

        imprimirAlCargar: true,
        cargando: true,

        escala: 1,
        alto: 1056,
        desplazamiento: 0,
        observador: null,

        init() {
            /* Ver `facturas/modal.blade.php`: el modal entra animado y al montar mide cero. */
            this.$nextTick(() => this.ajustar());

            if (window.ResizeObserver && this.$refs.marco) {
                this.observador = new ResizeObserver(() => this.ajustar());
                this.observador.observe(this.$refs.marco);
            }
        },

        destroy() {
            this.observador?.disconnect();
        },

        ajustar() {
            const marco = this.$refs.marco;

            if (! marco || marco.clientWidth === 0) {
                return;
            }

            this.escala = Math.min(1, marco.clientWidth / this.ANCHO);
            this.alto = marco.clientHeight / this.escala;
            this.desplazamiento = Math.max(0, (marco.clientWidth - this.ANCHO * this.escala) / 2);
        },

        verDetalle(conHojas) {
            if (! this.conDetalle || conHojas === this.detalle) {
                return;
            }

            this.detalle = conHojas;
            this.cargando = true;
            this.src = conHojas ? this.conDetalle : this.resumen;
        },

        alCargarLaHoja() {
            this.cargando = false;

            if (! this.imprimirAlCargar) {
                return;
            }

            this.imprimirAlCargar = false;
            this.imprimir();
        },

        imprimir() {
            const papel = this.$refs.papel;

            if (this.cargando || ! papel?.contentWindow) {
                return;
            }

            papel.contentWindow.focus();
            papel.contentWindow.print();
        },
    }"
    class="sihla-vista-honorarios"
>
    <style>
        .sihla-vista-honorarios { display: flex; flex-direction: column; gap: .75rem; }

        .sihla-vista-honorarios .sihla-acciones {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
        }
        .sihla-vista-honorarios .sihla-acciones-fin { margin-left: auto; }

        /* Las dos versiones de la hoja: un control segmentado. */
        .sihla-vista-honorarios .sihla-version {
            display: inline-flex; gap: .2rem; padding: .2rem;
            border-radius: .6rem; background: rgb(244 244 245);
        }
        .dark .sihla-vista-honorarios .sihla-version { background: rgb(39 39 42); }

        .sihla-vista-honorarios .sihla-version button {
            padding: .4rem .8rem; border: 0; border-radius: .45rem; cursor: pointer;
            font-size: .8125rem; font-weight: 500; line-height: 1.2;
            color: rgb(82 82 91); background: transparent;
        }
        .dark .sihla-vista-honorarios .sihla-version button { color: rgb(161 161 170); }
        .sihla-vista-honorarios .sihla-version button[aria-pressed="true"] {
            color: rgb(24 24 27); background: rgb(255 255 255);
            box-shadow: 0 1px 2px rgb(0 0 0 / .08);
            font-weight: 600;
        }
        .dark .sihla-vista-honorarios .sihla-version button[aria-pressed="true"] {
            color: rgb(244 244 245); background: rgb(63 63 70);
        }

        .sihla-vista-honorarios .sihla-marco {
            position: relative;
            overflow: hidden;
            width: 100%;
            height: min(72vh, 1100px);
            border-radius: .75rem;
            border: 1px solid rgb(228 228 231);
            background: rgb(244 244 245);
        }
        .dark .sihla-vista-honorarios .sihla-marco {
            border-color: rgb(63 63 70);
            background: rgb(24 24 27);
        }

        .sihla-vista-honorarios .sihla-papel {
            position: absolute;
            top: 0;
            left: 0;
            border: 0;
            background: rgb(244 244 245);
            transform-origin: top left;
        }

        .sihla-vista-honorarios .sihla-cargando {
            position: absolute; inset: 0; z-index: 1;
            display: flex; align-items: center; justify-content: center;
            font-size: .875rem; color: rgb(113 113 122);
            background: rgb(244 244 245 / .85);
        }
        .dark .sihla-vista-honorarios .sihla-cargando {
            color: rgb(161 161 170);
            background: rgb(24 24 27 / .85);
        }
    </style>

    <div class="sihla-acciones">
        @if ($enlaceConDetalle !== null)
            <div class="sihla-version" role="group" aria-label="Qué se imprime">
                <button type="button" x-on:click="verDetalle(false)" x-bind:aria-pressed="detalle ? 'false' : 'true'">
                    Solo el resumen
                </button>
                <button type="button" x-on:click="verDetalle(true)" x-bind:aria-pressed="detalle ? 'true' : 'false'">
                    {{ $etiquetaConDetalle ?? 'Con la hoja de cada uno' }}
                </button>
            </div>
        @endif

        <div class="sihla-acciones-fin">
            <x-filament::button icon="heroicon-o-printer" x-on:click="imprimir()" x-bind:disabled="cargando">
                Imprimir
            </x-filament::button>
        </div>
    </div>

    <div class="sihla-marco" x-ref="marco">
        <div class="sihla-cargando" x-show="cargando">Preparando el reporte…</div>

        {{--
            `src` escrito Y enlazado: sin el escrito, el iframe nace en
            about:blank y su «load» podría abrir la ventana de imprimir con
            una hoja en blanco. Alpine no lo vuelve a poner si no cambió,
            así que no carga dos veces.
        --}}
        <iframe
            x-ref="papel"
            src="{{ $enlace }}"
            x-bind:src="src"
            title="{{ $titulo }}"
            x-on:load="alCargarLaHoja()"
            class="sihla-papel"
            :style="`width: ${ANCHO}px; height: ${alto}px; transform: translateX(${desplazamiento}px) scale(${escala});`"
        ></iframe>
    </div>
</div>
