<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tarifario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 BORRA TODO EL MOVIMIENTO Y DEJA EL CATÁLOGO INTACTO.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PARA QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Probar inventario deja rastro: lotes mal tecleados, existencias
 * amontonadas, cuentas a medio cargar. Corregir el código no arregla los
 * datos que se guardaron mal —una migración cambia la regla de ahí en
 * adelante, no parte en tres un lote que ya nació mezclado—. Este comando
 * borra el movimiento para poder volver a probar desde cero.
 *
 * ⚠️⚠️ ESTO NO ES REVERSIBLE Y NO ES PARA PRODUCCIÓN.
 *
 * Borra cargos, recepciones, kardex, existencias, lotes, costos, cuentas
 * y encuentros. En un hospital de verdad eso es la historia clínica y
 * fiscal del año: por eso exige `--forzar` y se niega a correr si el
 * entorno no es local. Un `TRUNCATE` que se ejecuta por accidente en el
 * servidor equivocado no tiene deshacer.
 *
 * De los precios borra SOLO los que el sistema calculó solo al recibir
 * —los que dicen «Calculado solo en el primer ingreso a bodega»—. Son
 * datos derivados: se vuelven a generar con la próxima recepción, y con
 * el costo de cada envase. Los que alguien fijó a mano NO se tocan: esos
 * son decisiones de dirección con su fecha y su motivo.
 *
 * NO toca: productos, presentaciones, márgenes, descuentos de ley,
 * almacenes, proveedores, sedes, convenios, pacientes ni usuarios.
 */
class ReiniciarPruebasDeInventario extends Command
{
    protected $signature = 'sihla:reiniciar-pruebas {--forzar : Correr sin preguntar}';

    protected $description = 'Borra cargos, recepciones, kardex, existencias, lotes y cuentas. Deja el catálogo intacto.';

    /**
     * En este orden no importa: `CASCADE` resuelve las dependencias. Se
     * listan todas juntas para que el TRUNCATE sea uno solo y la base no
     * quede a medio vaciar si algo falla.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'cargos',
        'cargo_claves',
        'movimientos_kardex',
        'existencias',
        'lotes',
        'costos_promedio',
        'recepcion_lineas',
        'recepciones',
        'ajuste_lineas',
        'ajustes',
        'conteo_lineas',
        'conteos',
        'compras',
        'cuentas',
        'encuentros',
    ];

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo corre en local. En cualquier otro entorno sería borrar la historia del hospital.');

            return self::FAILURE;
        }

        if (! $this->option('forzar') && ! $this->confirm('Se van a borrar TODOS los cargos, recepciones, existencias, lotes y cuentas. El catálogo se queda. ¿Seguimos?')) {
            $this->line('No se tocó nada.');

            return self::SUCCESS;
        }

        $tablas = implode(', ', self::TABLAS);

        DB::statement('TRUNCATE TABLE '.$tablas.' RESTART IDENTITY CASCADE');

        /*
         * `forceDelete` y no `delete`: el tarifario usa borrado suave, y
         * una fila «borrada» seguiría ocupando su lugar en la línea de
         * tiempo del precio. Como son derivados, se van de verdad.
         */
        $derivados = Tarifario::query()
            ->where('motivo', 'like', 'Calculado%en el primer ingreso%')
            ->forceDelete();

        $this->newLine();
        $this->line($derivados.' precio(s) calculados automáticamente borrados. Los fijados a mano se quedaron.');
        $this->info('Listo. El movimiento quedó en cero y el catálogo intacto.');
        $this->line('Corré «php artisan sihla:demo-descuentos» para volver a abrir las tres cuentas de prueba.');
        $this->line('La próxima recepción vuelve a calcular el precio de cada envase con su propio costo.');

        return self::SUCCESS;
    }
}
