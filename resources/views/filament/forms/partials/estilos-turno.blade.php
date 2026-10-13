{{--
    Estilos de las ventanas de abrir y cerrar el turno de caja
    (rediseño del 13-oct-2026).

    ⚠️ Estilos propios con `<style>` y variables con `.dark`: el CSS del
    panel está precompilado y las clases nuevas de Tailwind no existen ahí
    (§9.A7). Colores literales y no `rgb(var(--gray-200))`: en Filament 5
    esas variables traen `oklch(…)` y el `rgb()` queda inválido.
--}}
<style>
    .tc {
        --tc-fondo: rgb(255 255 255);
        --tc-fondo-suave: rgb(250 250 250);
        --tc-anillo: rgb(9 9 11 / .1);
        --tc-linea: rgb(9 9 11 / .07);
        --tc-texto: rgb(9 9 11);
        --tc-tenue: rgb(113 113 122);
        --tc-acento: rgb(217 119 6);
        --tc-acento-fondo: rgb(255 251 235);
        --tc-acento-anillo: rgb(217 119 6 / .55);
        --tc-bien: rgb(22 163 74);
        --tc-bien-fondo: rgb(240 253 244);
        --tc-mal: rgb(220 38 38);
        --tc-mal-fondo: rgb(254 242 242);
        --tc-ojo: rgb(202 138 4);
        --tc-ojo-fondo: rgb(254 252 232);

        display: flex; flex-direction: column; gap: 1rem;
        color: var(--tc-texto);
    }
    .dark .tc {
        --tc-fondo: rgb(24 24 27);
        --tc-fondo-suave: rgb(255 255 255 / .03);
        --tc-anillo: rgb(255 255 255 / .1);
        --tc-linea: rgb(255 255 255 / .07);
        --tc-texto: rgb(255 255 255);
        --tc-tenue: rgb(161 161 170);
        --tc-acento: rgb(251 191 36);
        --tc-acento-fondo: rgb(251 191 36 / .08);
        --tc-acento-anillo: rgb(251 191 36 / .6);
        --tc-bien: rgb(74 222 128);
        --tc-bien-fondo: rgb(74 222 128 / .08);
        --tc-mal: rgb(248 113 113);
        --tc-mal-fondo: rgb(248 113 113 / .08);
        --tc-ojo: rgb(250 204 21);
        --tc-ojo-fondo: rgb(250 204 21 / .08);
    }

    .tc-titulo { font-size: .875rem; font-weight: 600; }
    .tc-tenue { color: var(--tc-tenue); }
    .tc-chico { font-size: .75rem; line-height: 1.1rem; }
    .tc-numero { font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* ── La franja de contexto: el último turno, o de quién es este ── */
    .tc-franja {
        display: flex; align-items: flex-start; gap: .75rem;
        padding: .75rem .875rem; border-radius: .75rem;
        background: var(--tc-fondo-suave); box-shadow: 0 0 0 1px var(--tc-anillo);
        font-size: .8125rem; line-height: 1.25rem;
    }
    .tc-franja-icono { flex: none; width: 1.25rem; height: 1.25rem; margin-top: .05rem; color: var(--tc-tenue); }
    .tc-franja strong { font-weight: 600; }
    .tc-franja-aviso { background: var(--tc-acento-fondo); box-shadow: 0 0 0 1px var(--tc-acento-anillo); }
    .tc-franja-aviso .tc-franja-icono { color: var(--tc-acento); }

    .tc-etiqueta {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .0625rem .4375rem; border-radius: 9999px;
        font-size: .6875rem; font-weight: 600; letter-spacing: .02em; line-height: 1.1rem;
    }
    .tc-etiqueta-bien { color: var(--tc-bien); background: var(--tc-bien-fondo); }
    .tc-etiqueta-mal { color: var(--tc-mal); background: var(--tc-mal-fondo); }
    .tc-etiqueta-ojo { color: var(--tc-ojo); background: var(--tc-ojo-fondo); }
    .tc-etiqueta-gris { color: var(--tc-tenue); background: var(--tc-fondo-suave); box-shadow: 0 0 0 1px var(--tc-anillo); }
    .tc-etiqueta-acento { color: var(--tc-acento); background: var(--tc-acento-fondo); box-shadow: 0 0 0 1px var(--tc-acento-anillo); }

    /* ── Las tarjetas de los turnos ── */
    .tc-turnos { display: grid; gap: .625rem; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); }
    .tc-turno {
        position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: .375rem;
        padding: .875rem; border-radius: .75rem; text-align: left; cursor: pointer;
        background: var(--tc-fondo); box-shadow: 0 0 0 1px var(--tc-anillo);
        transition: box-shadow .12s ease, background-color .12s ease;
    }
    .tc-turno:hover { background: var(--tc-fondo-suave); }
    .tc-turno:focus-visible { outline: 2px solid var(--tc-acento); outline-offset: 2px; }
    .tc-turno-elegido { background: var(--tc-acento-fondo); box-shadow: 0 0 0 2px var(--tc-acento); }
    .tc-turno-elegido:hover { background: var(--tc-acento-fondo); }
    .tc-turno-cabeza { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: .5rem; }
    .tc-turno-nombre { font-size: 1rem; font-weight: 700; letter-spacing: .01em; }
    .tc-turno-marca {
        display: flex; align-items: center; justify-content: center;
        width: 1.125rem; height: 1.125rem; border-radius: 9999px;
        box-shadow: inset 0 0 0 1.5px var(--tc-anillo);
    }
    .tc-turno-elegido .tc-turno-marca { background: var(--tc-acento); box-shadow: none; }
    .tc-turno-marca svg { width: .75rem; height: .75rem; color: rgb(255 255 255); }
    .dark .tc-turno-marca svg { color: rgb(24 24 27); }
    .tc-turno-etiquetas { display: flex; flex-wrap: wrap; gap: .25rem; min-height: 1.1rem; }
    .tc-turno-gente { font-size: .75rem; line-height: 1.1rem; color: var(--tc-tenue); }

    /* ── El conteo ── */
    .tc-cuadre { display: grid; gap: .875rem; grid-template-columns: 1fr; }
    @media (min-width: 640px) { .tc-cuadre { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }

    .tc-caja {
        display: flex; flex-direction: column; gap: .5rem;
        padding: .875rem; border-radius: .75rem;
        background: var(--tc-fondo); box-shadow: 0 0 0 1px var(--tc-anillo);
    }
    .tc-contado {
        display: flex; align-items: center; overflow: hidden; border-radius: .625rem;
        background: var(--tc-fondo); box-shadow: 0 0 0 1px var(--tc-anillo);
        transition: box-shadow .12s ease;
    }
    .tc-contado:focus-within { box-shadow: 0 0 0 2px var(--tc-acento); }
    .tc-contado-prefijo {
        align-self: stretch; display: flex; align-items: center; padding: 0 .875rem;
        font-size: 1.125rem; font-weight: 600; color: var(--tc-tenue);
        border-right: 1px solid var(--tc-linea);
    }
    .tc-contado input {
        width: 100%; min-width: 0; border: 0; background: transparent; outline: none; box-shadow: none;
        padding: .75rem .875rem; color: var(--tc-texto);
        font-size: 1.75rem; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.01em;
    }
    .tc-contado input:focus { outline: none; box-shadow: none; }
    .tc-contado input::placeholder { color: var(--tc-tenue); opacity: .5; font-weight: 600; }
    .tc-contado-malo { box-shadow: 0 0 0 2px var(--tc-mal) !important; }

    .tc-cinta { display: flex; flex-direction: column; margin: 0; }
    .tc-cinta-linea {
        display: flex; align-items: baseline; justify-content: space-between; gap: .75rem;
        padding: .3125rem 0; font-size: .8125rem;
    }
    .tc-cinta-linea dt { display: flex; align-items: baseline; gap: .375rem; color: var(--tc-tenue); }
    .tc-cinta-linea dd { margin: 0; font-weight: 500; font-variant-numeric: tabular-nums; }
    .tc-cinta-signo { display: inline-block; width: .75rem; text-align: center; font-weight: 600; }
    .tc-cinta-total { border-top: 1px solid var(--tc-linea); margin-top: .25rem; padding-top: .5rem; }
    .tc-cinta-total dt, .tc-cinta-total dd { color: var(--tc-texto); font-weight: 600; }

    .tc-veredicto {
        display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .625rem .75rem; border-radius: .625rem; font-weight: 700; font-size: .9375rem;
    }
    .tc-veredicto-bien { color: var(--tc-bien); background: var(--tc-bien-fondo); }
    .tc-veredicto-mal { color: var(--tc-mal); background: var(--tc-mal-fondo); }
    .tc-veredicto-ojo { color: var(--tc-ojo); background: var(--tc-ojo-fondo); }
    .tc-espera {
        display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center; gap: .375rem;
        min-height: 8rem; padding: 1rem; text-align: center;
        font-size: .8125rem; color: var(--tc-tenue);
    }
    .tc-espera svg { width: 1.75rem; height: 1.75rem; opacity: .6; }

    .tc-fuera { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; font-size: .75rem; color: var(--tc-tenue); }
    .tc-fuera strong { color: var(--tc-texto); font-weight: 600; font-variant-numeric: tabular-nums; }

    .tc-monto-grande { font-size: 1.125rem !important; font-weight: 600 !important; font-variant-numeric: tabular-nums; }

    /* ── Quién cierra la gaveta ocupada ── */
    .tc-lista { display: flex; flex-direction: column; gap: .375rem; margin: 0; padding: 0; list-style: none; font-size: .8125rem; }
    .tc-lista li { display: flex; align-items: flex-start; gap: .5rem; }
    .tc-lista svg { flex: none; width: 1rem; height: 1rem; margin-top: .125rem; color: var(--tc-bien); }
</style>
