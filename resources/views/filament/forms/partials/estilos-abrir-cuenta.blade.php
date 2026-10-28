{{--
    Estilos del modal «Abrir cuenta» (rediseño del 26-oct-2026): la ficha
    del paciente y las tarjetas del tipo de atención. Mismo lenguaje que
    las ventanas del turno de caja (`estilos-turno`): tarjetas con anillo,
    el elegido en ámbar.

    ⚠️ Estilos propios con `<style>` y variables con `.dark`: el CSS del
    panel está precompilado y las clases nuevas de Tailwind no existen ahí
    (§9.A7). Colores literales: en Filament 5 las variables del panel
    traen `oklch(…)` y no van dentro de `rgb()` (§9.M28).
--}}
<style>
    .ac {
        --ac-fondo: rgb(255 255 255);
        --ac-fondo-suave: rgb(250 250 250);
        --ac-anillo: rgb(9 9 11 / .1);
        --ac-linea: rgb(9 9 11 / .07);
        --ac-texto: rgb(9 9 11);
        --ac-tenue: rgb(113 113 122);
        --ac-acento: rgb(217 119 6);
        --ac-acento-fondo: rgb(255 251 235);
        --ac-acento-anillo: rgb(217 119 6 / .35);
        --ac-ojo: rgb(202 138 4);
        --ac-ojo-fondo: rgb(254 252 232);
        --ac-mal: rgb(220 38 38);
        --ac-mal-fondo: rgb(254 242 242);

        color: var(--ac-texto);
    }
    .dark .ac {
        --ac-fondo: rgb(24 24 27);
        --ac-fondo-suave: rgb(255 255 255 / .03);
        --ac-anillo: rgb(255 255 255 / .1);
        --ac-linea: rgb(255 255 255 / .07);
        --ac-texto: rgb(255 255 255);
        --ac-tenue: rgb(161 161 170);
        --ac-acento: rgb(251 191 36);
        --ac-acento-fondo: rgb(251 191 36 / .08);
        --ac-acento-anillo: rgb(251 191 36 / .35);
        --ac-ojo: rgb(250 204 21);
        --ac-ojo-fondo: rgb(250 204 21 / .08);
        --ac-mal: rgb(248 113 113);
        --ac-mal-fondo: rgb(248 113 113 / .08);
    }

    .ac-chico { margin-top: .5rem; font-size: .75rem; line-height: 1.1rem; color: var(--ac-tenue); }

    .ac-etiqueta {
        display: inline-flex; align-items: center;
        padding: .0625rem .4375rem; border-radius: 9999px;
        font-size: .6875rem; font-weight: 600; letter-spacing: .02em; line-height: 1.1rem;
    }
    .ac-etiqueta-ojo { color: var(--ac-ojo); background: var(--ac-ojo-fondo); }

    /* ── La ficha del paciente ── */
    .ac-ficha {
        display: flex; align-items: center; gap: .875rem;
        padding: .75rem .875rem; border-radius: .75rem;
        background: var(--ac-fondo-suave); box-shadow: 0 0 0 1px var(--ac-anillo);
    }
    .ac-avatar {
        flex: none; display: flex; align-items: center; justify-content: center;
        width: 2.5rem; height: 2.5rem; border-radius: 9999px;
        background: var(--ac-acento-fondo); color: var(--ac-acento);
        box-shadow: 0 0 0 1px var(--ac-acento-anillo);
        font-size: .875rem; font-weight: 700; letter-spacing: .03em;
    }
    .ac-ficha-cuerpo { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .125rem; }
    .ac-nombre {
        display: flex; flex-wrap: wrap; align-items: center; gap: .375rem;
        font-size: .9375rem; font-weight: 600; line-height: 1.3rem;
    }

    /*
     * Los datos van separados por «·», y al partirse en dos renglones el
     * segundo no puede empezar con un punto suelto. Cada dato lleva su
     * punto ADELANTE y la fila se corre hacia la izquierda lo que mide
     * ese punto: el de cada comienzo de renglón queda fuera de la caja, y
     * la caja lo recorta.
     */
    .ac-datos { overflow: hidden; }
    .ac-datos-fila {
        display: flex; flex-wrap: wrap; margin-left: -.875rem;
        font-size: .8125rem; line-height: 1.25rem; color: var(--ac-tenue);
    }
    .ac-datos-fila span::before { content: '·'; display: inline-block; width: .875rem; text-align: center; }

    .ac-expediente { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: .3125rem; }
    .ac-rotulo {
        font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
        color: var(--ac-tenue);
    }
    .ac-pastillas { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .375rem; }
    .ac-pastilla {
        display: inline-flex; align-items: center; gap: .3125rem;
        padding: .25rem .625rem; border-radius: 9999px; cursor: pointer;
        font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap;
        color: var(--ac-tenue); background: var(--ac-fondo); box-shadow: 0 0 0 1px var(--ac-anillo);
        transition: box-shadow .12s ease, background-color .12s ease, color .12s ease;
    }
    .ac-pastilla:hover { color: var(--ac-texto); }
    .ac-pastilla:focus-visible { outline: 2px solid var(--ac-acento); outline-offset: 2px; }
    .ac-pastilla svg { width: .875rem; height: .875rem; }
    .ac-pastilla-elegida,
    .ac-pastilla-elegida:hover {
        color: var(--ac-acento); background: var(--ac-acento-fondo); box-shadow: 0 0 0 1.5px var(--ac-acento);
    }
    .ac-sin-expediente { font-size: .8125rem; font-weight: 600; color: var(--ac-mal); }
    .ac-aviso {
        margin-top: .5rem; padding: .5rem .75rem; border-radius: .625rem;
        font-size: .8125rem; line-height: 1.2rem; color: var(--ac-mal); background: var(--ac-mal-fondo);
    }

    @media (max-width: 639px) {
        .ac-ficha { flex-wrap: wrap; }
        .ac-expediente {
            width: 100%; flex-direction: row; align-items: center; justify-content: space-between;
            padding-top: .5rem; border-top: 1px solid var(--ac-linea);
        }
    }

    /* ── El tipo de atención ── */
    .ac-tipos { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(7.25rem, 1fr)); }
    .ac-tipo {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .375rem;
        padding: .75rem .5rem; border-radius: .75rem; cursor: pointer; text-align: center;
        font-size: .8125rem; font-weight: 500; line-height: 1.1rem;
        color: var(--ac-tenue); background: var(--ac-fondo); box-shadow: 0 0 0 1px var(--ac-anillo);
        transition: box-shadow .12s ease, background-color .12s ease, color .12s ease;
    }
    .ac-tipo:hover { color: var(--ac-texto); background: var(--ac-fondo-suave); }
    .ac-tipo:focus-visible { outline: 2px solid var(--ac-acento); outline-offset: 2px; }

    /*
     * Dos clases y no una: el icono de Filament trae `.fi-icon.fi-size-md`
     * con su propio tamaño, y empatar la especificidad —este `<style>`
     * viene después— es lo que lo deja a la medida de la tarjeta.
     */
    .ac .ac-tipo-icono { width: 1.375rem; height: 1.375rem; color: inherit; }

    .ac-tipo-elegido,
    .ac-tipo-elegido:hover {
        color: var(--ac-acento); background: var(--ac-acento-fondo);
        box-shadow: 0 0 0 2px var(--ac-acento); font-weight: 600;
    }
</style>
