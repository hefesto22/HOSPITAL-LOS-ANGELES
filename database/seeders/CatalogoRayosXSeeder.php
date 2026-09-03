<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Item;
use App\Models\Tarifario;
use App\Models\Unidad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La lista de radiografías del hospital — el PRIMER precio de lista real.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 *     php artisan db:seed --class=CatalogoRayosXSeeder
 *
 * Es idempotente: busca por NOMBRE y solo crea lo que falta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ TIENE DE DISTINTO ESTE DOCUMENTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `CatalogoPaligSeeder` y `CatalogoMilitarSeeder` cargan lo que le cobra
 * el hospital a UN PAGADOR. Este archivo no: la fuente es «Servicios Rx»
 * —la lista de precios del propio hospital— y su columna dice «Precio de
 * Venta». Es lo que paga el paciente particular.
 *
 * Por eso es el único de los tres que escribe un precio de lista de
 * verdad. En los otros dos el de lista quedó en el centinela de
 * `Database\Seeders\Support\ListaPendiente`, porque nadie lo ha fijado
 * todavía.
 *
 * 38 radiografías a L 400 y un recargo de L 200.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CÓDIGO: RX-008 EN ADELANTE, NO Rx-001
 * ─────────────────────────────────────────────────────────────────────
 *
 * El papel numera Rx-001 … Rx-039. No se usa tal cual: el catálogo ya
 * tiene RX-001 … RX-007 —las proyecciones genéricas de PALIG y del
 * Militar— y el índice único de `items.codigo` es sensible a mayúsculas,
 * así que `Rx-001` y `RX-001` convivirían como dos estudios de rayos X
 * distintos separados por una letra minúscula. Es una trampa de captura
 * esperando a alguien apurado.
 *
 * Además `CategoriasDelCatalogoSeeder` reparte por prefijo exacto
 * (`RX-`): con la minúscula, las 39 caerían en «Otros servicios».
 *
 * Se continúa la numeración de la familia. En una base virgen el papel
 * mapea en orden: Rx-001 → RX-008 … Rx-039 → RX-046. El número exacto se
 * pide en tiempo de ejecución —nunca se escribe acá— porque el catálogo
 * está vivo y cualquier número que este archivo fije hoy puede ser de
 * otro ítem mañana.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CONVIVEN CON LAS PROYECCIONES GENÉRICAS. ES A PROPÓSITO.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Siguen existiendo PROYECCION DE RAYOS X CON LECTURA (RX-001) y SIN
 * LECTURA (RX-007), que son sobre las que PALIG y el Militar negociaron.
 * Estas 39 son el menú detallado del hospital.
 *
 * El mismo ítem vale distinto según el pagador, y eso es DATO del
 * tarifario, no código: el día que una aseguradora ponga precio a
 * «RADIOGRAFIA AP DE TORAX», es una fila en `tarifarios` cargada por
 * pantalla y este seeder no se toca.
 *
 * ⚠️ Mientras tanto ninguna de las 39 tiene fila de convenio, así que a
 * un paciente asegurado se le factura el precio de LISTA —L 400—. Es el
 * fallback correcto por diseño, pero conviene saberlo: con PALIG la
 * proyección con lectura estaba negociada en L 500.
 *
 * ⚠️ El precio se guarda SIN ISV. Radiología es exenta por el Art. 15
 * inciso d de la Ley del ISV.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 QUÉ PREGUNTARLE AL HOSPITAL
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · «RADIOGRAFIA DE PELVIS» (Rx-015) y «RADIOGRAFIA AP DE PELVIS»
 *     (Rx-036) son dos renglones del mismo papel al mismo precio. O es
 *     un duplicado, o la primera significa otra cosa. Se cargan las dos
 *     tal cual vienen: corregir el documento es decisión del hospital,
 *     no mía.
 *   · «RECARGO EXTRA POR RADIOGRAFIA» L 200: el papel no dice qué lo
 *     dispara —¿proyección adicional? ¿hora inhábil?—. Queda como ítem
 *     cobrable suelto hasta que alguien lo defina.
 *   · Ninguna de las 39 dice si incluye lectura del radiólogo. Las L 400
 *     coinciden con la proyección digital SIN lectura del tarifario
 *     anterior, así que se asume que no la incluye.
 */
class CatalogoRayosXSeeder extends Seeder
{
    /**
     * Desde cuándo rige esta lista.
     *
     * Septiembre y no agosto como los otros dos seeders: estos ítems no
     * existían antes: la lista llegó ahora. Fija y declarada, no `now()`,
     * para que volver a correr el seeder actualice la MISMA fila en vez
     * de abrir una vigencia que choque con el EXCLUDE de traslape.
     */
    private const VIGENCIA_DESDE = '2026-09-01';

    /** La familia de códigos que continúa. */
    private const PREFIJO = 'RX';

    /** Solo se usa si no existiera ningún RX-; los que hay traen tres. */
    private const ANCHO_POR_DEFECTO = 3;

    /**
     * La lista, en el orden del papel.
     *
     * Todas son estudio de imagen y caen bajo el mismo numeral del
     * Art. 30, así que alcanza con nombre y precio.
     *
     * @var list<array{0: string, 1: numeric-string}>
     */
    private const RADIOGRAFIAS = [
        ['RADIOGRAFIA AP DE TORAX', '400.00'],
        ['RADIOGRAFIA LATERAL DE TORAX', '400.00'],
        ['RADIOGRAFIA AP DE DEDO', '400.00'],
        ['RADIOGRAFIA LATERAL DE DEDO', '400.00'],
        ['RADIOGRAFIA OBLICUA DE PIE', '400.00'],
        ['RADIOGRAFIA AP DE PIE', '400.00'],
        ['RADIOGRAFIA LATERAL DE PIE', '400.00'],
        ['RADIOGRAFIA LATERAL DE TOBILLO', '400.00'],
        ['RADIOGRAFIA AP DE TOBILLO', '400.00'],
        ['RADIOGRAFIA DE HOMBRO', '400.00'],
        ['RADIOGRAFIA AP DE CRANEO', '400.00'],
        ['RADIOGRAFIA LATERAL DE CRANEO', '400.00'],
        ['RADIOGRAFIA AP DE CERVICALES', '400.00'],
        ['RADIOGRAFIA LATERAL DE CERVICALES', '400.00'],
        ['RADIOGRAFIA DE PELVIS', '400.00'],
        ['RADIOGRAFIA AP RODILLA', '400.00'],
        ['RADIOGRAFIA LATERAL RODILLA', '400.00'],
        ['RADIOGRAFIA AP DE MANO', '400.00'],
        ['RADIOGRAFIA LATERAL DE MANO', '400.00'],
        ['RADIOGRAFIA OBLICUA DE MANO', '400.00'],
        ['RADIOGRAFIA AP DE ABDOMEN DE PIE', '400.00'],
        ['RADIOGRAFIA AP DE ABDOMEN ACOSTADO', '400.00'],
        ['RADIOGRAFIA AP DE FEMUR', '400.00'],
        ['RADIOGRAFIA LATERAL DE FEMUR', '400.00'],
        ['RADIOGRAFIA AP DE PIERNA', '400.00'],
        ['RADIOGRAFIA LATERAL DE PIERNA', '400.00'],
        ['RADIOGRAFIA AP DE COLUMNA', '400.00'],
        ['RADIOGRAFIA LATERAL DE COLUMNA', '400.00'],
        ['RADIOGRAFIA AP DE CODO', '400.00'],
        ['RADIOGRAFIA LATERAL DE CODO', '400.00'],
        ['RADIOGRAFIA OBLICUA DE CODO', '400.00'],
        ['RADIOGRAFIA AP DE BRAZO', '400.00'],
        ['RADIOGRAFIA LATERAL DE BRAZO', '400.00'],
        ['RADIOGRAFIA HUESOS PROPIOS DE LA CARA', '400.00'],
        ['RADIOGRAFIA HUESOS PROPIOS DE LA NARIZ', '400.00'],
        ['RADIOGRAFIA AP DE PELVIS', '400.00'],
        ['RADIOGRAFIA LATERAL DE PELVIS', '400.00'],
        ['RADIOGRAFIA LUMBAR', '400.00'],
        ['RECARGO EXTRA POR RADIOGRAFIA', '200.00'],
    ];

    /**
     * El próximo número y su ancho. Se llena solo en la primera consulta.
     *
     * @var array{0: int, 1: int}|null
     */
    private ?array $numeracion = null;

    /** Cuántos ítems creó de verdad esta corrida. */
    private int $creados = 0;

    public function run(): void
    {
        foreach (self::RADIOGRAFIAS as [$nombre, $precio]) {
            $this->precioDeLista($this->item($nombre), $precio);
        }

        $total = count(self::RADIOGRAFIAS);

        $this->command?->info("✓ {$total} estudios de rayos X con su precio de lista.");
        $this->command?->comment("  {$this->creados} se crearon; el resto ya estaba en el catálogo.");
        $this->command?->comment('  Es precio de LISTA real —lo que paga el particular—, no el centinela de L 10.');
        $this->command?->warn('  ⚠️ Ninguna tiene precio de convenio: hoy a un asegurado se le factura la lista.');
        $this->command?->warn('  ⚠️ Corré CategoriasDelCatalogoSeeder después para que caigan en la categoría RAYOS X.');
    }

    /**
     * El ítem: por NOMBRE, y solo se crea si de verdad no está.
     *
     * Así correrlo dos veces no duplica nada ni pisa correcciones hechas
     * a mano desde la pantalla.
     */
    private function item(string $nombre): Item
    {
        $existente = Item::query()->where('nombre', $nombre)->first();

        if ($existente instanceof Item) {
            return $existente;
        }

        $this->creados++;

        /** @var Item $item */
        $item = Item::query()->create(
            [
                'codigo' => $this->codigoLibre(),
                'nombre' => $nombre,
                'tipo'   => TipoItem::EstudioImagen,

                /*
                 * Exento por el Art. 15 inciso d de la Ley del ISV:
                 * radiología es servicio médico. La excepción de ese
                 * inciso son los tratamientos de belleza estética.
                 */
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                'unidad_dispensacion_id'    => $this->unidad('UND')->id,
                'requiere_lote'             => false,
                'requiere_receta'           => false,
                'es_controlado'             => false,
                'vigencia_desde'            => self::VIGENCIA_DESDE,
            ],
        );

        return $item;
    }

    /**
     * El precio de lista de verdad: `convenio_id` nulo.
     *
     * @param numeric-string $precio
     */
    private function precioDeLista(Item $item, string $precio): void
    {
        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => null,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => bcmul($precio, '1', 4),
                'motivo' => 'Precio de venta de la lista de radiografías del hospital '
                    .'(documento «Servicios Rx», septiembre de 2026).',
            ],
        );
    }

    /** El siguiente RX- libre, pedido y no escrito. Ver el encabezado. */
    private function codigoLibre(): string
    {
        [$ultimo, $ancho] = $this->numeracion ?? $this->ultimoRx();

        $this->numeracion = [$ultimo + 1, $ancho];

        return self::PREFIJO.'-'.str_pad((string) ($ultimo + 1), $ancho, '0', STR_PAD_LEFT);
    }

    /**
     * El número más alto de la familia y con cuántos dígitos se escribe.
     *
     * @return array{0: int, 1: int}
     */
    private function ultimoRx(): array
    {
        /** @var array<int, string> $codigos */
        $codigos = DB::table('items')
            ->where('codigo', 'like', self::PREFIJO.'-%')
            ->pluck('codigo')
            ->all();

        $patron = '/^'.preg_quote(self::PREFIJO, '/').'-(\d+)$/';

        $mayor = 0;
        $ancho = self::ANCHO_POR_DEFECTO;

        foreach ($codigos as $codigo) {
            if (preg_match($patron, $codigo, $partes) !== 1) {
                continue;
            }

            $numero = (int) $partes[1];

            if ($numero >= $mayor) {
                $mayor = $numero;
                $ancho = mb_strlen($partes[1]);
            }
        }

        return [$mayor, $ancho];
    }

    private function unidad(string $codigo): Unidad
    {
        $unidad = Unidad::query()->where('codigo', $codigo)->first();

        if (! $unidad instanceof Unidad) {
            throw new RuntimeException(
                "Falta la unidad «{$codigo}». Corré UnidadesSeeder antes que este seeder."
            );
        }

        return $unidad;
    }
}
