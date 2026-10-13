{{--
    Estilos de los reportes sobrios del panel (13-oct-2026): la barra del
    período con su control segmentado, el panel con la cifra de arriba y
    la tabla chica de resumen.

    Los usan Honorarios por médico, Consumo de productos y Existencias.
    Viven en un solo lugar para que las tres pantallas se vean iguales y
    un ajuste no haya que hacerlo tres veces.

    ⚠️ Colores literales con variables propias y `.dark`: en Filament 5 las
    variables del panel son oklch y `rgb(var(--gray-200))` no vale. El
    `<style>` va en Blade porque el CSS del panel está precompilado.
--}}
    <style>
        .sh {
            --sh-fondo: rgb(255 255 255);
            --sh-anillo: rgb(9 9 11 / .05);
            --sh-sombra: 0 1px 2px 0 rgb(0 0 0 / .05);
            --sh-linea: rgb(9 9 11 / .06);
            --sh-texto: rgb(9 9 11);
            --sh-tenue: rgb(113 113 122);
            --sh-muy-tenue: rgb(161 161 170);
            --sh-hover: rgb(250 250 250);
            --sh-acento: rgb(217 119 6);
            --sh-acento-fondo: rgb(255 251 235);

            display: flex; flex-direction: column; gap: 1.5rem;
        }
        .dark .sh {
            --sh-fondo: rgb(24 24 27);
            --sh-anillo: rgb(255 255 255 / .1);
            --sh-sombra: none;
            --sh-linea: rgb(255 255 255 / .08);
            --sh-texto: rgb(255 255 255);
            --sh-tenue: rgb(161 161 170);
            --sh-muy-tenue: rgb(113 113 122);
            --sh-hover: rgb(255 255 255 / .03);
            --sh-acento: rgb(251 191 36);
            --sh-acento-fondo: rgb(251 191 36 / .08);
        }

        /* 1. Barra del período */
        .sh-barra {
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: .75rem;
        }
        .sh-seg {
            display: inline-flex; flex-wrap: wrap; gap: .25rem; padding: .25rem;
            border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-seg-boton {
            padding: .45rem .8rem; border-radius: .5rem; cursor: pointer;
            font-size: .875rem; font-weight: 500; color: var(--sh-tenue);
            background: transparent; border: 0;
            transition: background-color .12s ease, color .12s ease;
        }
        .sh-seg-boton:hover { color: var(--sh-texto); background: var(--sh-hover); }
        .sh-seg-activo, .sh-seg-activo:hover { color: var(--sh-acento); background: var(--sh-acento-fondo); }

        .sh-rango {
            display: inline-flex; align-items: center; gap: .25rem; padding: .25rem .375rem;
            border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-rango input {
            height: 2rem; padding: 0 .375rem; border: 0; border-radius: .375rem;
            background: transparent; color: var(--sh-texto);
            font-size: .875rem; font-variant-numeric: tabular-nums;
            color-scheme: light;
        }
        .dark .sh-rango input { color-scheme: dark; }
        .sh-rango input:focus { outline: 2px solid var(--sh-acento); outline-offset: 0; }
        .sh-rango-flecha { font-size: .875rem; color: var(--sh-muy-tenue); }

        .sh-seg-boton:focus-visible, .sh-fila:focus-visible, .sh-todos:focus-visible {
            outline: 2px solid var(--sh-acento); outline-offset: -2px;
        }

        /* 2. Panel */
        .sh-panel {
            overflow: hidden; border-radius: .75rem; background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-cabeza {
            display: flex; flex-direction: column; gap: .2rem; padding: 1.25rem 1.5rem;
        }
        .sh-etiqueta { font-size: .8125rem; font-weight: 500; color: var(--sh-tenue); }
        .sh-valor {
            font-size: 1.875rem; font-weight: 600; line-height: 1.2; letter-spacing: -.01em;
            font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--sh-texto);
        }
        .sh-nota { font-size: .75rem; color: var(--sh-muy-tenue); }

        .sh-tabla { width: 100%; border-collapse: collapse; border-top: 1px solid var(--sh-linea); }
        .sh-tabla th {
            padding: .625rem 1.5rem; text-align: left;
            font-size: .75rem; font-weight: 500; color: var(--sh-tenue);
            border-bottom: 1px solid var(--sh-linea);
        }
        .sh-tabla td {
            padding: .75rem 1.5rem; vertical-align: middle;
            font-size: .875rem; color: var(--sh-texto);
            border-bottom: 1px solid var(--sh-linea);
        }
        .sh-tabla tbody tr:last-child td { border-bottom: 0; }
        .sh-num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .sh-total { font-weight: 600; }

        .sh-fila { cursor: pointer; transition: background-color .12s ease; }
        .sh-fila:hover td { background: var(--sh-hover); }
        .sh-fila-activa td, .sh-fila-activa:hover td { background: var(--sh-acento-fondo); }
        .sh-fila-activa td:first-child { box-shadow: inset 3px 0 0 var(--sh-acento); }

        .sh-tabla-nombre { display: block; font-weight: 500; line-height: 1.3; }
        .sh-tabla-detalle {
            display: block; margin-top: .1rem;
            font-size: .75rem; color: var(--sh-tenue);
        }
        .sh-solo-cel { display: none; }

        /* 3. Detalle */
        .sh-detalle { display: flex; flex-direction: column; gap: .75rem; }
        .sh-detalle-cabeza { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }
        .sh-detalle-titulo { font-size: 1rem; font-weight: 600; color: var(--sh-texto); }
        .sh-todos {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .375rem .75rem; border-radius: .5rem; cursor: pointer;
            font-size: .8125rem; font-weight: 500; color: var(--sh-tenue);
            background: var(--sh-fondo); border: 0;
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-todos:hover { color: var(--sh-texto); }
        .sh-todos-icono { width: 1rem; height: 1rem; }

        /* Cifra chica dentro de un botón del control segmentado */
        .sh-seg-cuenta {
            margin-left: .35rem; padding: 0 .4rem; border-radius: 999px;
            font-size: .75rem; font-weight: 600; font-variant-numeric: tabular-nums;
            background: var(--sh-hover); color: var(--sh-tenue);
        }
        .sh-seg-activo .sh-seg-cuenta { background: transparent; color: inherit; }
        .sh-seg-cuenta-alerta { color: var(--sh-acento); }

        /* Los dos controles de Existencias, uno debajo del otro */
        .sh-filtros { display: flex; flex-direction: column; align-items: flex-start; gap: .625rem; }
        .sh-seg-ancho { align-self: flex-start; max-width: 100%; }

        /* Una marca chica al lado de un nombre: «Stock infinito» */
        .sh-chip {
            display: inline-block; margin-left: .375rem; padding: .05rem .45rem;
            border-radius: 999px; vertical-align: .1em;
            font-size: .6875rem; font-weight: 600; line-height: 1.4;
            background: var(--sh-acento-fondo); color: var(--sh-acento);
        }

        /* «Ver los 40 productos», al pie del panel */
        .sh-mas {
            display: block; width: 100%; padding: .625rem 1.5rem; cursor: pointer;
            font-size: .8125rem; font-weight: 500; color: var(--sh-tenue);
            background: transparent; border: 0; border-top: 1px solid var(--sh-linea);
        }
        .sh-mas:hover { color: var(--sh-texto); background: var(--sh-hover); }
        .sh-mas:focus-visible { outline: 2px solid var(--sh-acento); outline-offset: -2px; }

        /* Una línea de aviso, sin el peso de una tarjeta */
        .sh-aviso {
            display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem;
            padding: .75rem 1rem; border-radius: .75rem;
            font-size: .8125rem; color: var(--sh-tenue);
            background: var(--sh-fondo);
            box-shadow: var(--sh-sombra), 0 0 0 1px var(--sh-anillo);
        }
        .sh-aviso strong { font-weight: 600; color: var(--sh-texto); }
        .sh-aviso-signo {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.75rem; height: 1.75rem; flex-shrink: 0; border-radius: 999px;
            font-size: 1.125rem; line-height: 1;
            background: var(--sh-acento-fondo); color: var(--sh-acento);
        }
        .sh-aviso-texto { flex: 1 1 18rem; }
        .sh-aviso-enlace { font-weight: 600; color: var(--sh-acento); white-space: nowrap; }
        .sh-aviso-enlace:hover { text-decoration: underline; }

        /* El vencimiento de un lote, en el modal «Lotes» de Existencias */
        .sh-tabla-sin-borde { border-top: 0; }
        .sh-vence {
            display: inline-block; padding: .05rem .45rem; border-radius: .375rem;
            font-size: .75rem; font-weight: 600; font-variant-numeric: tabular-nums;
            background: var(--sh-hover); color: var(--sh-tenue);
        }
        .sh-vence-danger { background: rgb(254 226 226); color: rgb(185 28 28); }
        .sh-vence-warning { background: rgb(254 243 199); color: rgb(146 64 14); }
        .sh-vence-success { background: rgb(220 252 231); color: rgb(21 128 61); }
        .dark .sh-vence-danger { background: rgb(248 113 113 / .12); color: rgb(252 165 165); }
        .dark .sh-vence-warning { background: rgb(251 191 36 / .12); color: rgb(253 230 138); }
        .dark .sh-vence-success { background: rgb(74 222 128 / .12); color: rgb(134 239 172); }

        /*
         * «¿Dónde hay?» de un infinito, en el modal «Mover» de Existencias
         * (`filament.forms.lugares-del-producto`): cada almacén es una
         * tarjeta que se toca para marcar o desmarcar.
         */
        .sh.sh-compacto { gap: .875rem; }
        .sh-tiles {
            display: grid; gap: .625rem;
            grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr));
        }
        .sh-tile {
            display: flex; align-items: center; gap: .75rem; width: 100%;
            padding: .75rem .875rem; border: 0; border-radius: .75rem;
            text-align: left; cursor: pointer;
            background: var(--sh-fondo); box-shadow: 0 0 0 1px var(--sh-anillo);
            transition: background-color .12s ease, box-shadow .12s ease;
        }
        .sh-tile:hover { background: var(--sh-hover); }
        .sh-tile:focus-visible { outline: 2px solid var(--sh-acento); outline-offset: 2px; }
        .sh-tile-on, .sh-tile-on:hover {
            background: var(--sh-acento-fondo); box-shadow: 0 0 0 2px var(--sh-acento);
        }
        .sh-tile-icono {
            display: inline-flex; align-items: center; justify-content: center;
            width: 2.25rem; height: 2.25rem; flex-shrink: 0; border-radius: .625rem;
            background: var(--sh-hover); color: var(--sh-tenue);
            transition: background-color .12s ease, color .12s ease;
        }
        .sh-tile-on .sh-tile-icono { background: var(--sh-acento); color: rgb(255 255 255); }
        .dark .sh-tile-on .sh-tile-icono { color: rgb(24 24 27); }
        .sh-tile-svg { width: 1.25rem; height: 1.25rem; }
        .sh-tile-texto { display: flex; flex-direction: column; flex: 1; min-width: 0; }
        .sh-tile-nombre { font-size: .875rem; font-weight: 600; line-height: 1.25; color: var(--sh-texto); }
        .sh-tile-tipo { margin-top: .1rem; font-size: .75rem; color: var(--sh-tenue); }
        .sh-tile-marca {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.25rem; height: 1.25rem; flex-shrink: 0; border-radius: 999px;
            color: transparent; box-shadow: inset 0 0 0 1.5px var(--sh-anillo);
        }
        .sh-tile-on .sh-tile-marca { background: var(--sh-acento); color: rgb(255 255 255); box-shadow: none; }
        .dark .sh-tile-on .sh-tile-marca { color: rgb(24 24 27); }
        .sh-tile-check { width: .875rem; height: .875rem; }

        .sh-cambios {
            display: flex; flex-wrap: wrap; align-items: center; gap: .375rem;
            padding: .625rem .75rem; border-radius: .625rem; background: var(--sh-hover);
        }
        .sh-cambios-titulo { margin-right: .25rem; font-size: .8125rem; font-weight: 500; color: var(--sh-tenue); }
        .sh-cambio { padding: .15rem .55rem; border-radius: .375rem; font-size: .75rem; font-weight: 600; }
        .sh-cambio-mas { background: var(--sh-acento-fondo); color: var(--sh-acento); }
        .sh-cambio-menos {
            color: var(--sh-muy-tenue); text-decoration: line-through;
            box-shadow: inset 0 0 0 1px var(--sh-anillo);
        }
        .sh-cambios-nada { font-size: .8125rem; color: var(--sh-muy-tenue); }
        .sh-cambio-queda { color: var(--sh-texto); box-shadow: inset 0 0 0 1px var(--sh-anillo); background: var(--sh-fondo); }

        /* Los tres pasos del «Mover» de un infinito (`filament.forms.mover-infinito`) */
        .sh-paso { display: flex; flex-direction: column; gap: .5rem; }
        .sh-paso-titulo {
            display: inline-flex; align-items: center; gap: .5rem;
            font-size: .875rem; font-weight: 600; color: var(--sh-texto);
        }
        .sh-paso-numero {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.375rem; height: 1.375rem; border-radius: 999px;
            font-size: .75rem; font-weight: 700;
            background: var(--sh-acento-fondo); color: var(--sh-acento);
        }
        .sh-tiles-chicas { grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); }
        .sh-tiles-dos { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .sh-tile-ya { font-weight: 600; color: var(--sh-acento); }
        .sh-aviso-chico {
            padding: .5rem .75rem; border-radius: .5rem;
            font-size: .8125rem; color: var(--sh-acento); background: var(--sh-acento-fondo);
        }
        @media (max-width: 30rem) {
            .sh-tiles-dos { grid-template-columns: 1fr; }
        }

        /*
         * Qué hay en cada lugar de un infinito («Lugares»): la tarjeta del
         * almacén con sus envases debajo. Marcado = lleno; incluido —viene
         * adentro de lo marcado— = contorno; las unidades sueltas, fijas.
         */
        .sh-tiles-lugares { grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); align-items: start; }
        .sh-lugar {
            display: flex; flex-direction: column; border-radius: .75rem;
            background: var(--sh-fondo); box-shadow: 0 0 0 1px var(--sh-anillo);
            transition: background-color .12s ease, box-shadow .12s ease;
        }
        .sh-lugar-on { background: var(--sh-acento-fondo); box-shadow: 0 0 0 2px var(--sh-acento); }
        .sh-lugar .sh-tile, .sh-lugar .sh-tile-on, .sh-lugar .sh-tile-on:hover { background: transparent; box-shadow: none; }
        .sh-lugar .sh-tile:hover { background: var(--sh-hover); }
        .sh-envases { display: flex; flex-wrap: wrap; gap: .375rem; padding: 0 .875rem .75rem 3.875rem; }
        .sh-envase {
            padding: .2rem .6rem; border: 0; border-radius: 999px; cursor: pointer;
            font-size: .75rem; font-weight: 600; line-height: 1.25rem; white-space: nowrap;
            color: var(--sh-tenue); background: var(--sh-fondo); box-shadow: inset 0 0 0 1px var(--sh-anillo);
        }
        .sh-envase:hover { color: var(--sh-texto); }
        .sh-envase:focus-visible { outline: 2px solid var(--sh-acento); outline-offset: 1px; }
        .sh-envase-on, .sh-envase-on:hover { color: rgb(255 255 255); background: var(--sh-acento); box-shadow: none; }
        .dark .sh-envase-on, .dark .sh-envase-on:hover { color: rgb(24 24 27); }
        .sh-envase-incluido, .sh-envase-incluido:hover {
            cursor: default; color: var(--sh-acento); background: var(--sh-fondo);
            box-shadow: inset 0 0 0 1.5px var(--sh-acento);
        }
        .sh-envase-fijo, .sh-envase-fijo:hover { cursor: default; color: var(--sh-tenue); background: var(--sh-hover); box-shadow: none; }

        /*
         * La columna «Dónde está» de Existencias
         * (`filament.tables.columns.donde-esta`): un renglón por almacén,
         * el nombre alineado a la izquierda y lo que hay a la derecha.
         */
        .sh-donde {
            display: grid; grid-template-columns: max-content minmax(0, 1fr);
            align-items: center; column-gap: 1rem; row-gap: .45rem;
            padding: 1rem .75rem; min-width: 15rem;
        }
        .sh-donde-almacen {
            display: inline-flex; align-items: center; gap: .45rem; white-space: nowrap;
            font-size: .75rem; font-weight: 600; letter-spacing: .02em; color: var(--sh-tenue);
        }
        .sh-donde-almacen::before {
            content: ''; width: .375rem; height: .375rem; flex-shrink: 0;
            border-radius: 999px; background: var(--sh-muy-tenue);
        }
        .sh-donde-elegido { color: var(--sh-acento); }
        .sh-donde-elegido::before { background: var(--sh-acento); }
        .sh-donde-que { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem; }
        .sh-donde-cantidad {
            font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums;
            white-space: nowrap; color: var(--sh-texto);
        }
        .sh-donde-tag {
            padding: 0 .4rem; border-radius: .3rem; white-space: nowrap;
            font-size: .6875rem; font-weight: 600; line-height: 1.25rem; letter-spacing: .02em;
            color: var(--sh-texto); background: var(--sh-hover); box-shadow: inset 0 0 0 1px var(--sh-anillo);
        }
        .dark .sh-donde-tag { background: rgb(255 255 255 / .06); }
        .sh-donde-tag-suelta { color: var(--sh-tenue); background: transparent; }
        .dark .sh-donde-tag-suelta { background: transparent; }

        /*
         * Lo que hay en un almacén, en las tarjetas de los modales: las
         * mismas etiquetas chicas que la tabla.
         */
        .sh-tile-hay { display: flex; flex-wrap: wrap; gap: .25rem; margin-top: .35rem; }

        /* El «Mover» de lo que tiene existencia (`filament.forms.mover-existencia`) */
        .sh-tiles-lotes { grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
        .sh-tile-vence { margin-top: .35rem; }
        .sh-cantidad { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .sh-numero {
            width: 9rem; padding: .5rem .75rem; border: 0; border-radius: .5rem;
            font-size: 1rem; font-weight: 600; font-variant-numeric: tabular-nums;
            color: var(--sh-texto); background: var(--sh-fondo); box-shadow: 0 0 0 1px var(--sh-anillo);
        }
        .dark .sh-numero { color-scheme: dark; }
        .sh-numero:focus { outline: 2px solid var(--sh-acento); outline-offset: 0; }
        .sh-numero[aria-invalid="true"] { box-shadow: 0 0 0 2px var(--sh-acento); }
        .sh-cuenta { font-size: .8125rem; font-weight: 500; color: var(--sh-texto); }

        [x-cloak] { display: none !important; }

        @media (max-width: 48rem) {
            .sh-ocultable { display: none; }
            .sh-solo-cel { display: inline; }
        }
        @media (max-width: 30rem) {
            .sh-envases { padding-left: .875rem; }
            .sh-numero { flex: 1; min-width: 0; }
            .sh-barra { flex-direction: column; align-items: stretch; }
            .sh-seg { display: grid; grid-template-columns: 1fr 1fr; }
            .sh-rango { justify-content: space-between; }
            .sh-rango input { flex: 1; min-width: 0; }
            .sh-cabeza { padding: 1rem; }
            .sh-valor { font-size: 1.5rem; }
            .sh-tabla th, .sh-tabla td { padding-left: 1rem; padding-right: 1rem; }
        }
    </style>
