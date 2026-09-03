{{--
    La factura adentro de un modal.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ UN IFRAME Y NO EL HTML PEGADO ACÁ
    ─────────────────────────────────────────────────────────────────────

    Porque `facturas.imprimir` es un documento completo —con su `@page`,
    su tamaño carta y sus reglas de `@media print`— y esas reglas no
    sobreviven pegadas adentro del panel: el CSS de Filament se les monta
    encima y lo que sale de la impresora deja de ser el papel que el SAR
    y la cajera conocen.

    Adentro del iframe la hoja es la MISMA página que ya se imprimía
    antes en pestaña aparte, con `?incrustada=1` para que no dibuje su
    propia barra de botones.

    ⚠️ Se imprime `contentWindow.print()` y no `window.print()`: el
    segundo mandaría a la impresora el panel entero —menú, tabla, modal—
    con la factura escondida adentro.

    ─────────────────────────────────────────────────────────────────────
    🔴 EL CSS VA ACÁ ADENTRO, NO EN CLASES DE TAILWIND (§9.A7)
    ─────────────────────────────────────────────────────────────────────

    La primera versión traía `class="w-full"` en el iframe y
    `flex justify-end` en la barra. **Ninguna de las dos existía.** Este
    blade se renderiza adentro del panel, que carga el CSS PRECOMPILADO
    de Filament, y ese bundle solo trae las clases que Filament usa: no
    escanea las vistas del proyecto. El proyecto tampoco tiene un theme
    propio de Filament que las pudiera compilar.

    Se notaba en dos cosas a la vez: el botón «Imprimir» quedaba a la
    IZQUIERDA aunque el blade pidiera `justify-end`, y el iframe se
    quedaba con su ancho intrínseco de 300 px —de ahí la hoja recortada,
    sin las columnas de cantidad, precio ni total—.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ SE ESCALA Y NO SE ESTIRA
    ─────────────────────────────────────────────────────────────────────

    Una carta mide 216 mm: 816 px a 96 dpi. Eso NO es negociable, porque
    el documento reparte sus columnas en milímetros y estirarlo a un
    iframe de 1,400 px daría una previsualización que no se parece al
    papel que sale de la impresora.

    Así que el iframe se dibuja SIEMPRE a 816 px y se encoge con
    `transform: scale()` hasta lo que quepa. Lo que se ve en pantalla es
    la hoja completa, a escala, y no un recorte.

    El alto del iframe se compensa —`alto del marco ÷ escala`— para que
    el documento llene el marco y su propio scroll siga sirviendo cuando
    la factura pasa de una página, que es justo lo que empezó a pasar con
    las cirugías desglosadas.
--}}
<div
    x-data="{
        /** Una carta: 216 mm a 96 dpi. */
        ANCHO: 816,

        escala: 1,
        alto: 1056,
        desplazamiento: 0,
        observador: null,

        init() {
            /*
             * ⚠️ En `$nextTick` y con un ResizeObserver, no una sola vez
             * al montar: el modal entra con animación y ahí el marco
             * todavía mide cero. Midiendo una sola vez, la escala
             * quedaba en 1 y la hoja volvía a salir recortada.
             */
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

            /* Nunca agranda: una carta más grande que su tamaño real se lee peor. */
            this.escala = Math.min(1, marco.clientWidth / this.ANCHO);
            this.alto = marco.clientHeight / this.escala;

            /*
             * ⚠️ Centrar a mano y no con `margin: auto`.
             *
             * Un elemento transformado sigue OCUPANDO su tamaño sin
             * escalar —816 px— así que `auto` centraría la caja original
             * y no lo que se ve. Con la hoja al 60 % quedaría corrida
             * casi 200 px. Acá se centra lo que de verdad mide en
             * pantalla: ancho del marco menos la hoja YA escalada.
             */
            this.desplazamiento = Math.max(0, (marco.clientWidth - this.ANCHO * this.escala) / 2);
        },

        imprimir() {
            const marco = this.$refs.papel;

            if (! marco?.contentWindow) {
                return;
            }

            marco.contentWindow.focus();
            marco.contentWindow.print();
        },
    }"
    class="sihla-vista-factura"
>
    <style>
        .sihla-vista-factura { display: flex; flex-direction: column; gap: .75rem; }

        .sihla-vista-factura .sihla-acciones { display: flex; justify-content: flex-end; }

        /*
         * El marco NO es blanco a propósito. Con la hoja centrada sobre
         * un fondo del mismo color, el sobrante a los lados se leía como
         * parte del papel y la factura parecía cortada. Sobre un gris
         * tenue se ve lo que es: una hoja sobre la mesa.
         *
         * `overflow: hidden` y no `auto`: el iframe escalado ya ocupa el
         * alto del marco, así que una barra acá sería una segunda barra
         * encima de la del documento.
         */
        .sihla-vista-factura .sihla-marco {
            position: relative;
            overflow: hidden;
            width: 100%;
            height: min(72vh, 1100px);
            border-radius: .75rem;
            border: 1px solid rgb(228 228 231);
            background: rgb(244 244 245);
        }
        .dark .sihla-vista-factura .sihla-marco {
            border-color: rgb(63 63 70);
            background: rgb(24 24 27);
        }

        .sihla-vista-factura .sihla-papel {
            position: absolute;
            top: 0;
            left: 0;
            border: 0;
            background: rgb(255 255 255);
            transform-origin: top left;
            box-shadow: 0 1px 3px rgb(0 0 0 / .12), 0 8px 24px rgb(0 0 0 / .08);
        }
    </style>

    <div class="sihla-acciones">
        <x-filament::button icon="heroicon-o-printer" x-on:click="imprimir()">
            Imprimir
        </x-filament::button>
    </div>

    <div class="sihla-marco" x-ref="marco">
        <iframe
            x-ref="papel"
            src="{{ route('facturas.imprimir', ['factura' => $factura, 'incrustada' => 1]) }}"
            title="Factura {{ $factura->numero }}"
            class="sihla-papel"
            :style="`width: ${ANCHO}px; height: ${alto}px; transform: translateX(${desplazamiento}px) scale(${escala});`"
        ></iframe>
    </div>
</div>
