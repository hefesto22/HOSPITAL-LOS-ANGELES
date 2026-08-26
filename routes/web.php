<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Filament v4 toma control de "/" porque el panel está configurado con
| ->path('/') en AdminPanelProvider. NO definir aquí Route::get('/') —
| Filament lo perderá si la ruta web tiene mayor prioridad.
|
| Este archivo queda disponible para rutas custom adicionales (webhooks,
| callbacks OAuth, endpoints públicos puntuales) que NO conflictúen con
| las rutas de Filament.
|
| Las rutas internas del panel (/login, /dashboard, /users, /shield/roles,
| /horizon, etc.) las gestiona Filament automáticamente.
*/

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrincipioActivo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Etiquetas para imprimir
|--------------------------------------------------------------------------
|
| Tres cosas distintas se etiquetan en este hospital, y cada una resuelve
| un problema propio:
|
|   · LA PRESENTACIÓN — la caja de 100, el blíster de 12. Es lo único que
|     existe físicamente y lo único que se puede agarrar con la mano, así
|     que es lo que se escanea al recibir y al dispensar.
|   · EL PRINCIPIO ACTIVO — la etiqueta de la GAVETA. Se escanea y salen
|     todos los productos que lo llevan, en cualquier forma.
|   · EL ÍTEM — el código interno del catálogo. Sigue sirviendo para todo
|     lo que no viene envasado: un estudio, un procedimiento, un honorario.
|
| Van como rutas web y no como acciones de Filament a propósito: imprimir
| es abrir una página y apretar Ctrl+P. Un modal con JavaScript que arma
| otra ventana es más frágil, se lo come el bloqueador de pop-ups, y falla
| justo el día que hay que mostrarlo.
|
| ⚠️ Autenticadas y autorizadas igual que la ficha. Una ruta de impresión
| sin candado es un listado del catálogo entero servido por URL a
| cualquiera que adivine un id.
*/
Route::middleware(['web', 'auth'])->group(function (): void {
    /**
     * La hoja, armada igual para las tres.
     *
     * ⚠️ El enlace al OTRO formato se arma acá y se le pasa hecho a la
     * vista. Antes lo armaba la vista con `route('etiquetas.item', ...)`
     * fijo, y desde que hay más de un tipo de etiqueta eso mentía: al
     * principio activo le generaba la URL del ÍTEM con ese id —otro
     * producto— sin dar ningún error.
     *
     * @param array<string, mixed> $parametros
     */
    $hoja = function (
        string $ruta,
        array $parametros,
        string $codigo,
        string $nombre,
        ?string $subtitulo,
    ): View {
        /*
         * Dos formatos, y cada uno resuelve un problema distinto:
         *
         *   · `media` — media hoja A4 con UN código grande, para la
         *     gaveta o el estante. Es la que se lee de lejos.
         *   · `hoja`  — treinta etiquetas chicas en una A4, para el
         *     frasco, el blíster reenvasado y la caja.
         *
         * El default es la grande: es la que se pide de a una.
         */
        $formato = request()->string('formato', 'media')->toString();
        $formato = in_array($formato, ['media', 'hoja'], true) ? $formato : 'media';

        $copias = max(1, min(60, (int) request()->integer('copias', 30)));

        return view('etiquetas.item', [
            'codigo'         => $codigo,
            'nombre'         => $nombre,
            'subtitulo'      => $subtitulo,
            'formato'        => $formato,
            'copias'         => $formato === 'media' ? 1 : $copias,
            'urlOtroFormato' => $formato === 'media'
                ? route($ruta, [...$parametros, 'formato' => 'hoja', 'copias' => 30])
                : route($ruta, [...$parametros, 'formato' => 'media']),
        ]);
    };

    /*
     * ─────────────────────────────────────────────────────────────────
     * LA ETIQUETA DE LA CAJA
     * ─────────────────────────────────────────────────────────────────
     *
     * Para lo que llega sin código legible o para lo que el hospital
     * reenvasa. El código lo propone el formulario de presentaciones
     * —`MED-0708-01`— y se imprime acá.
     *
     * El subtítulo es la equivalencia: «1 CAJA = 100 TABLETA». En el
     * estante eso vale más que cualquier otra cosa que se pueda escribir
     * en una etiqueta chica.
     */
    Route::get('/etiquetas/presentacion/{presentacion}', function (
        ItemPresentacion $presentacion,
    ) use ($hoja) {
        $duenio = $presentacion->item;

        abort_unless(
            $duenio instanceof Item && (auth()->user()?->can('view', $duenio) ?? false),
            403,
        );

        $codigo = $presentacion->codigo_barras;

        /*
         * Sin código no hay etiqueta que imprimir. Devolver una hoja en
         * blanco sería peor: alguien la manda a la impresora, salen
         * treinta rectángulos vacíos y recién ahí se entiende.
         */
        if ($codigo === null) {
            abort(404);
        }

        return $hoja(
            'etiquetas.presentacion',
            ['presentacion' => $presentacion->getKey()],
            $codigo,
            $presentacion->nombre,
            $presentacion->comoSeLee(),
        );
    })->name('etiquetas.presentacion');

    /*
     * La etiqueta del ÍTEM, con su código interno. Es la del catálogo:
     * lo que no viene en ninguna caja y aun así hay que poder identificar.
     */
    Route::get('/etiquetas/item/{item}', function (Item $item) use ($hoja) {
        abort_unless(auth()->user()?->can('view', $item) ?? false, 403);

        $principio = $item->principio_activo;

        return $hoja(
            'etiquetas.item',
            ['item' => $item->getKey()],
            $item->codigo,
            $item->nombre,
            is_string($principio) && $principio !== '' && $principio !== $item->nombre
                ? $principio
                : null,
        );
    })->name('etiquetas.item');

    /*
     * La etiqueta del ESTANTE, que es de lo que se trata todo esto: se
     * pega en la gaveta del acetaminofén y al escanearla salen los cuatro
     * productos que lo llevan.
     */
    Route::get('/etiquetas/principio/{principio}', function (PrincipioActivo $principio) use ($hoja) {
        abort_unless(auth()->user()?->can('view', $principio) ?? false, 403);

        return $hoja(
            'etiquetas.principio',
            ['principio' => $principio->getKey()],
            $principio->codigo,
            $principio->nombre,
            $principio->tambien_llamado,
        );
    })->name('etiquetas.principio');
});
