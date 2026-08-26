<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Los closures de Filament reciben sus argumentos POR NOMBRE
|--------------------------------------------------------------------------
|
| Filament no inyecta por tipo: `EvaluatesClosures` busca el nombre del
| parámetro en el arreglo que le pasa quien evalúa el closure. Para las
| consultas ese arreglo es `['query' => $consulta]`.
|
| Un parámetro con OTRO nombre no lo encuentra. No falla: cae hasta
| `app()->make(Builder::class)` —que el contenedor sabe construir— y el
| closure recibe un `Builder` VACÍO, sin modelo y sin tabla.
|
| Ahí se abren dos finales, los dos silenciosos:
|
|   • El closure devuelve el builder vacío → Filament lo toma como la
|     consulta de la tabla y la pantalla revienta con un error que no
|     menciona nada de esto.
|
|   • El closure no devuelve nada → se pierde lo que hacía. Un `with()`
|     que no eager-loadea es un N+1 que nadie ve; una subconsulta que no
|     se agrega es una columna en blanco que parece falta de datos.
|
| Esta prueba lee el código fuente porque el error NO se puede detectar
| de otra forma: no lo ve PHPStan —el tipo es correcto—, no lo ve Pint, y
| una prueba que llame al método directamente pasa igual, porque el
| problema está en el cableado, no en la lógica.
*/

use Symfony\Component\Finder\Finder;

/**
 * Las listas de parámetros de todos los closures de consulta que hay en
 * `app/Filament`, con el archivo donde viven.
 *
 * @return list<array{archivo: string, parametros: string}>
 */
function closuresDeConsultaDeFilament(): array
{
    $encontrados = [];

    $archivos = Finder::create()
        ->files()
        ->in(app_path('Filament'))
        ->name('*.php')
        ->sortByName();

    foreach ($archivos as $archivo) {
        $codigo = $archivo->getContents();

        /*
         * Las dos formas en que se declara una: `modifyQueryUsing(...)`
         * —tabla, relación, filtro— y el `query(...)` de un `Filter`.
         * `fn` y `function`, con o sin `static`, y también como
         * argumento con nombre (`modifyQueryUsing: fn (...)`).
         */
        $patrones = [
            '/modifyQueryUsing\s*(?::\s*|\(\s*)(?:static\s+)?(?:function|fn)\s*\(([^)]*)\)/',
            '/->query\s*\(\s*(?:static\s+)?(?:function|fn)\s*\(([^)]*)\)/',
        ];

        foreach ($patrones as $patron) {
            preg_match_all($patron, $codigo, $coincidencias);

            foreach ($coincidencias[1] as $parametros) {
                $encontrados[] = [
                    'archivo'    => $archivo->getRelativePathname(),
                    'parametros' => (string) $parametros,
                ];
            }
        }
    }

    return $encontrados;
}

it('🔴 todo closure de consulta de Filament nombra su primer parametro $query', function (): void {
    $sospechosos = [];

    foreach (closuresDeConsultaDeFilament() as $closure) {
        $partes = explode(',', $closure['parametros']);
        $primero = trim($partes[0]);

        /* Sin parámetros no hay nada que inyectar: `fn (): Builder => …`. */
        if ($primero === '') {
            continue;
        }

        if (str_contains($primero, '$query')) {
            continue;
        }

        $sospechosos[] = $closure['archivo'].' → '.$primero;
    }

    expect($sospechosos)->toBe([]);
})->note('🔴 Con cualquier otro nombre —`$consulta`, `$q`— Filament entrega un Builder vacío del contenedor: el `with()` no eager-loadea, la subconsulta no se agrega y la columna sale en blanco. Sin excepción, sin log, sin nada en la pantalla que lo delate.');

it('encuentra los closures de consulta que existen, para no pasar por vacia', function (): void {
    expect(closuresDeConsultaDeFilament())->not->toBeEmpty();
})->note('Una prueba que no encuentra nada que revisar pasa siempre. Si alguien mueve `app/Filament` o cambia el patrón, esto avisa en vez de dar un visto bueno vacío.');
