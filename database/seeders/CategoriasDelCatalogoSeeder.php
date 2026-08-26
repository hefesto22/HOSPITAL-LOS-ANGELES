<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\AmbitoCatalogo;
use App\Models\CategoriaItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Las categorías del catálogo, calcadas del tarifario impreso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DE DÓNDE SALEN ESTOS NOMBRES
 * ─────────────────────────────────────────────────────────────────────
 *
 * Del papel. El tarifario que el hospital firmó con PALIG viene en
 * hojas —«Área de Hospitalización», «Equipo Médico», «Rayos X»,
 * «Laboratorio», «Consulta Externa»— y esa es la agrupación que el
 * personal ya tiene en la cabeza. Inventar una taxonomía nueva y más
 * prolija obligaría a traducir mentalmente en cada carga.
 *
 * Del lado de farmacia no hay papel que copiar, así que arrancan las
 * cinco que separan lo que de verdad se guarda distinto: el
 * medicamento, lo que cura, lo descartable, lo que se infunde, y el
 * resto. Se renombran y se agregan desde la pantalla, sin deploy.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TAMBIÉN CLASIFICA LO QUE YA ESTABA CARGADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los códigos del catálogo ya traen el prefijo de su hoja (`HOS-003`,
 * `LAB-041`, `MED-0012`), así que la clasificación inicial no hay que
 * adivinarla: está escrita. Lo que no case con ningún prefijo cae en la
 * categoría genérica de SU LADO y queda a la vista para reclasificar.
 *
 * 🔴 Cada UPDATE lleva `se_almacena` en el WHERE. El CHECK
 * `items_categoria_coherente_con_almacenamiento` rechaza un producto de
 * farmacia archivado como servicio, y un seeder que reviente a la mitad
 * deja el catálogo clasificado por la mitad. Filtrar por las dos
 * condiciones a la vez hace que eso no pueda pasar.
 *
 * Es idempotente: se puede volver a correr. Solo toca ítems SIN
 * categoría, así que no pisa ninguna reclasificación hecha a mano.
 *
 *     php artisan db:seed --class=CategoriasDelCatalogoSeeder
 */
class CategoriasDelCatalogoSeeder extends Seeder
{
    /**
     * Desde cuándo rigen. Fija y declarada, no `now()`: así volver a
     * correr el seeder actualiza la MISMA fila en vez de abrir una
     * vigencia nueva.
     */
    private const VIGENCIA_DESDE = '2026-08-01';

    /**
     * código, nombre, ámbito, orden, prefijo de los ítems que le tocan.
     *
     * El prefijo `null` significa «no reclama a nadie por código»: es la
     * genérica, la que recibe lo que sobra.
     *
     * @var list<array{0: string, 1: string, 2: AmbitoCatalogo, 3: int, 4: string|null}>
     */
    private const CATEGORIAS = [
        // ── Lo que el hospital OFRECE ─────────────────────────────────
        ['CON', 'CONSULTA EXTERNA', AmbitoCatalogo::Servicios, 10, 'CON-'],
        ['HOS', 'AREA DE HOSPITALIZACION', AmbitoCatalogo::Servicios, 20, 'HOS-'],

        /*
         * ─────────────────────────────────────────────────────────────
         * EL QUIRÓFANO ES SU PROPIA HOJA
         * ─────────────────────────────────────────────────────────────
         *
         * Faltaba, y se notó al cargar la primera cirugía: una
         * apendicectomía terminó archivada en AREA DE HOSPITALIZACION,
         * que es la hoja del día-cama. Mezclarlas hace ilegible el
         * tarifario justo donde están los números más grandes — veinte
         * cirugías entre las estancias no se encuentran, y lo que no se
         * encuentra se cotiza de memoria.
         *
         * Va en el orden 25, entre hospitalización y equipo médico:
         * es el orden en que se lee el tarifario impreso, que es el que
         * el personal ya conoce.
         */
        ['QUI', 'SALA DE OPERACIONES', AmbitoCatalogo::Servicios, 25, 'QUI-'],
        ['EQP', 'EQUIPO MEDICO', AmbitoCatalogo::Servicios, 30, 'EQP-'],
        ['RX', 'RAYOS X', AmbitoCatalogo::Servicios, 40, 'RX-'],
        ['LAB', 'LABORATORIO', AmbitoCatalogo::Servicios, 50, 'LAB-'],
        ['SRV', 'OTROS SERVICIOS', AmbitoCatalogo::Servicios, 90, null],

        // ── Lo que se GUARDA ──────────────────────────────────────────
        ['MED', 'MEDICAMENTOS', AmbitoCatalogo::Productos, 10, 'MED-'],
        ['MTC', 'MATERIAL DE CURACION', AmbitoCatalogo::Productos, 20, null],
        ['DES', 'DESCARTABLES', AmbitoCatalogo::Productos, 30, null],
        ['SOL', 'SOLUCIONES Y SUEROS', AmbitoCatalogo::Productos, 40, null],
        ['INS', 'OTROS INSUMOS', AmbitoCatalogo::Productos, 90, 'INS-'],
    ];

    /** Las que reciben lo que no case con ningún prefijo. */
    private const GENERICA_DE_SERVICIOS = 'SRV';

    private const GENERICA_DE_PRODUCTOS = 'INS';

    public function run(): void
    {
        /** @var array<string, CategoriaItem> $porCodigo */
        $porCodigo = [];

        foreach (self::CATEGORIAS as [$codigo, $nombre, $ambito, $orden, $prefijo]) {
            $categoria = CategoriaItem::query()->updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'         => $nombre,
                    'ambito'         => $ambito,
                    'orden'          => $orden,
                    'vigencia_desde' => self::VIGENCIA_DESDE,
                ],
            );

            $porCodigo[$codigo] = $categoria;

            if ($prefijo !== null) {
                $this->clasificarPorPrefijo($categoria, $prefijo);
            }
        }

        $this->clasificarElResto($porCodigo[self::GENERICA_DE_SERVICIOS], AmbitoCatalogo::Servicios);
        $this->clasificarElResto($porCodigo[self::GENERICA_DE_PRODUCTOS], AmbitoCatalogo::Productos);

        $this->informarLoQueQuedaPendiente();
    }

    /**
     * Los ítems cuyo código empieza con el prefijo de esta categoría,
     * siempre que estén del mismo lado del catálogo que ella.
     */
    private function clasificarPorPrefijo(CategoriaItem $categoria, string $prefijo): void
    {
        $tocados = DB::table('items')
            ->whereNull('categoria_id')
            ->where('se_almacena', $categoria->ambito->seAlmacena())
            ->where('codigo', 'like', $prefijo.'%')
            ->update([
                'categoria_id'     => $categoria->getKey(),
                'categoria_ambito' => $categoria->ambito->value,
            ]);

        if ($tocados > 0) {
            $this->command?->info("  {$categoria->nombre}: {$tocados} ítems clasificados por código.");
        }
    }

    /**
     * Lo que no reclamó ningún prefijo. No se deja sin categoría: un
     * ítem huérfano no aparece en ningún grupo del listado y no suma en
     * el reporte de ingresos por área — desaparece sin dar error.
     */
    private function clasificarElResto(CategoriaItem $generica, AmbitoCatalogo $ambito): void
    {
        $tocados = DB::table('items')
            ->whereNull('categoria_id')
            ->where('se_almacena', $ambito->seAlmacena())
            ->update([
                'categoria_id'     => $generica->getKey(),
                'categoria_ambito' => $generica->ambito->value,
            ]);

        if ($tocados > 0) {
            $this->command?->warn(
                "  {$generica->nombre}: {$tocados} ítems sin prefijo conocido. Revisalos y reclasificalos."
            );
        }
    }

    private function informarLoQueQuedaPendiente(): void
    {
        $huerfanos = DB::table('items')->whereNull('categoria_id')->count();

        if ($huerfanos > 0) {
            $this->command?->error("  ⚠️ Quedaron {$huerfanos} ítems sin categoría. No debería pasar: revisá `se_almacena`.");

            return;
        }

        $this->command?->info('  Todo el catálogo quedó clasificado.');
    }
}
