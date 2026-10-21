<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LAS UNIDADES QUE PIDE LA LISTA DE PRODUCTOS (Mauricio, 14-oct-2026).
 *
 * El Excel con que farmacia va a levantar el catálogo ofrece, en «Se
 * dispensa en» y en «Viene en», siete formas que el sistema no tenía:
 *
 *   - se dispensan en: SUPOSITORIO, ÓVULO, PARCHE y TUBO (la crema que se
 *     vende entera);
 *   - vienen en: TUBO, PAQUETE (gasas, apósitos), ROLLO (lo que se corta
 *     por metro) y GALÓN (alcohol, yodo, jabón quirúrgico).
 *
 * Sin ellas, la carga de la lista no tendría a qué unidad apuntar.
 *
 * Va en una migración y no solo en `UnidadesSeeder` para que entre sola
 * con el `migrate --force` del despliegue: el seeder no se vuelve a
 * correr en una base con datos, y si se corriera pisaría el nombre y el
 * símbolo de las unidades que el hospital ya editó.
 *
 * Solo INSERTA lo que falta. Si el hospital ya creó una de estas desde
 * la pantalla de Unidades —con este código, o con el mismo nombre con o
 * sin tilde («OVULO»)—, la deja como está y no la duplica.
 *
 * Todas son de conteo y sin fracción: medio supositorio no es media
 * existencia. Ninguna trae otros envases adentro —un paquete de gasas
 * trae gasas sueltas—; si alguna llega a traerlos, se enciende desde la
 * pantalla, igual que CAJA.
 */
return new class extends Migration
{
    /**
     * código => [nombre, símbolo]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const UNIDADES = [
        'SUP'    => ['SUPOSITORIO', 'sup'],
        'OVULO'  => ['ÓVULO', 'óv'],
        'PARCHE' => ['PARCHE', 'parche'],
        'TUBO'   => ['TUBO', 'tubo'],
        'PAQ'    => ['PAQUETE', 'paq'],
        'ROLLO'  => ['ROLLO', 'rollo'],
        'GALON'  => ['GALÓN', 'gal'],
    ];

    /** Para comparar nombres sin que una tilde haga dos unidades. */
    private const CON_TILDE = 'ÁÉÍÓÚÜÑ';

    private const SIN_TILDE = 'AEIOUUN';

    public function up(): void
    {
        $ahora = now();

        foreach (self::UNIDADES as $codigo => [$nombre, $simbolo]) {
            $yaEsta = DB::table('unidades')
                ->whereNull('deleted_at')
                ->where(function (Builder $consulta) use ($codigo, $nombre): void {
                    $consulta->where('codigo', $codigo)
                        ->orWhereRaw(
                            'translate(upper(btrim(nombre)), ?, ?) = translate(?, ?, ?)',
                            [self::CON_TILDE, self::SIN_TILDE, $nombre, self::CON_TILDE, self::SIN_TILDE],
                        );
                })
                ->exists();

            if ($yaEsta) {
                continue;
            }

            DB::table('unidades')->insert([
                'codigo'           => $codigo,
                'nombre'           => $nombre,
                'simbolo'          => $simbolo,
                'magnitud'         => 'conteo',
                'permite_fraccion' => false,
                'contiene_envases' => false,
                'created_at'       => $ahora,
                'updated_at'       => $ahora,
            ]);
        }
    }

    /**
     * ⚠️ Borra solo las que nadie usa. Una que ya está en un producto, una
     * presentación, un cargo o un presupuesto se queda: las llaves
     * foráneas son RESTRICT y el rollback reventaría a la mitad.
     */
    public function down(): void
    {
        $consulta = DB::table('unidades')->whereIn('codigo', array_keys(self::UNIDADES));

        foreach ([
            ['items', 'unidad_dispensacion_id'],
            ['items', 'unidad_fraccion_id'],
            ['item_presentaciones', 'unidad_id'],
            ['cargos', 'unidad_id'],
            ['presupuesto_lineas', 'unidad_id'],
        ] as [$tabla, $columna]) {
            $consulta->whereNotExists(
                fn (Builder $q) => $q->from($tabla)->whereColumn("{$tabla}.{$columna}", 'unidades.id'),
            );
        }

        $consulta->delete();
    }
};
