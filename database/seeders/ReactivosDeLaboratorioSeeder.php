<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoItem;
use App\Domain\Enums\TipoServicio;
use App\Models\Almacen;
use App\Models\CategoriaItem;
use App\Models\ConsumoDeServicio;
use App\Models\Item;
use App\Models\Sede;
use App\Models\Servicio;
use App\Models\Unidad;
use App\Services\AsignadorDeCodigoDeAlmacen;
use App\Services\AsignadorDeCodigoDeItem;
use App\Services\UbicadorDeProducto;
use Database\Seeders\Support\ListaPendiente;
use Illuminate\Database\Seeder;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LOS KITS DEL LABORATORIO (16-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lista de pruebas de Mauricio: cada examen de adentro gasta pruebas
 * de un kit, y los kits se guardan en LABORATORIO.
 *
 *   · Por sede: el servicio LABORATORIO y su estante LABORATORIO.
 *   · La unidad PRUEBA y la categoría REACTIVOS DE LABORATORIO.
 *   · Un ítem por kit, contado en pruebas, «gasto del servicio» (no se
 *     cobra aparte: lo cobra el examen) y en stock infinito según
 *     `sihla.inventario.stock_infinito_al_crear`. Con infinito solo se
 *     anota cuánto se usa; el día que laboratorio reciba la caja y le
 *     apague el infinito, se cuenta de verdad.
 *   · Qué gasta cada examen de adentro. Los de afuera no gastan nada.
 *   · El examen DENGUE NS1 + IGG/IGM (Mauricio: «un solo examen
 *     DENGUE»). Los dos de antes quedan para el histórico y para los
 *     convenios que los pactan por separado.
 *
 * Idempotente y sin borrar: se puede correr en producción. Lo que ya
 * existe —un kit, una receta, el estante— se deja como está; lo que el
 * hospital ya cambió en la ficha no se pisa.
 */
final class ReactivosDeLaboratorioSeeder extends Seeder
{
    private const VIGENCIA_DESDE = '2026-11-16';

    /**
     * Los kits, como los nombró Mauricio.
     *
     * @var list<string>
     */
    private const KITS = [
        // Química sanguínea
        'GLUCOSA', 'COLESTEROL TOTAL', 'COLESTEROL HDL', 'TRIGLICÉRIDOS', 'ÁCIDO ÚRICO', 'TGO', 'TGP',
        'ELECTROLITOS', 'BUN', 'CREATININA', 'PROTEÍNAS TOTALES', 'ALBÚMINA', 'AMILASA', 'LIPASA',
        'CALCIO', 'LDH', 'BILIRRUBINA TOTAL', 'BILIRRUBINA DIRECTA', 'CPK TOTAL', 'CPK MB',
        'FOSFATASA ALCALINA',
        // Especiales
        'PROTEÍNA C REACTIVA', 'PROCALCITONINA', 'HEMOGLOBINA GLICOSILADA', 'NT PROBNP', 'PSA TOTAL',
        'DÍMERO D', 'FERRITINA',
        // Otras
        'GENERAL DE ORINA', 'HISOPADO COVID/INFLUENZA A + B', 'HISOPADO COVID-19',
        'HISOPADO INFLUENZA A + B', 'DENGUE NS1 + DENGUE IGG/IGM', 'AG HELICOBACTER PYLORI',
        'PRUEBA DE EMBARAZO', 'SANGRE OCULTA EN HECES', 'VES',
    ];

    /**
     * El examen de adentro (como está en el catálogo) → los kits que gasta,
     * una prueba de cada uno.
     *
     * Sin receta a propósito: COLESTEROL LDL (sale calculado del total, el
     * HDL y los triglicéridos), PCR ULTRASENSIBLE y DENGUE ANTICUERPO. Si
     * gastan, se les agrega en la ficha, «Qué gasta».
     *
     * @var array<string, list<string>>
     */
    private const RECETAS = [
        'GLUCOSA'                      => ['GLUCOSA'],
        'GLUCOSA POST PRANDIAL'        => ['GLUCOSA'],
        'GLUCOSA AL AZAR'              => ['GLUCOSA'],
        'COLESTEROL TOTAL'             => ['COLESTEROL TOTAL'],
        'COLESTEROL HDL'               => ['COLESTEROL HDL'],
        'TRIGLICERIDOS'                => ['TRIGLICÉRIDOS'],
        'ACIDO URICO'                  => ['ÁCIDO ÚRICO'],
        'TGO'                          => ['TGO'],
        'TGP'                          => ['TGP'],
        'ELECTROLITOS'                 => ['ELECTROLITOS'],
        'BUN / UREA'                   => ['BUN'],
        'CREATININA'                   => ['CREATININA'],
        'PROTEINAS TOTALES'            => ['PROTEÍNAS TOTALES'],
        'ALBUMINA'                     => ['ALBÚMINA'],
        'AMILASA'                      => ['AMILASA'],
        'LIPASA'                       => ['LIPASA'],
        'CALCIO'                       => ['CALCIO'],
        'LDH'                          => ['LDH'],
        'BILIRRUBINA'                  => ['BILIRRUBINA TOTAL', 'BILIRRUBINA DIRECTA'],
        'CPK'                          => ['CPK TOTAL'],
        'CKMB-P'                       => ['CPK MB'],
        'FOSFATASA ALCALINA'           => ['FOSFATASA ALCALINA'],
        'PCR'                          => ['PROTEÍNA C REACTIVA'],
        'PROCALCITONINA'               => ['PROCALCITONINA'],
        'HEMOGLOBINA GLICOSILADA'      => ['HEMOGLOBINA GLICOSILADA'],
        'PROBNP'                       => ['NT PROBNP'],
        'PSA'                          => ['PSA TOTAL'],
        'DIMERO D'                     => ['DÍMERO D'],
        'FERRITINA'                    => ['FERRITINA'],
        'GENERAL DE ORINA'             => ['GENERAL DE ORINA'],
        'COMBO INFLUENZA MAS COVID 19' => ['HISOPADO COVID/INFLUENZA A + B'],
        'DENGUE ANTIGENO'              => ['DENGUE NS1 + DENGUE IGG/IGM'],
        'DENGUE NS1 + IGG/IGM'         => ['DENGUE NS1 + DENGUE IGG/IGM'],
        'HELICOBACTER PYLORI EN HECES' => ['AG HELICOBACTER PYLORI'],
        'EMBARAZO'                     => ['PRUEBA DE EMBARAZO'],
        'SANGRE OCULTA EN HECES'       => ['SANGRE OCULTA EN HECES'],
        'VES'                          => ['VES'],
    ];

    /** El examen nuevo de dengue y su precio de lista, sin ISV (exento). */
    private const DENGUE = 'DENGUE NS1 + IGG/IGM';

    private const PRECIO_DEL_DENGUE = '850.0000';

    public function run(): void
    {
        $prueba = Unidad::query()->firstOrCreate(['codigo' => 'PRUEBA'], [
            'nombre'           => 'PRUEBA',
            'simbolo'          => 'prueba',
            'magnitud'         => MagnitudDeMedida::Conteo,
            'permite_fraccion' => false,
            'contiene_envases' => false,
        ]);

        $categoria = CategoriaItem::query()->firstOrCreate(['codigo' => 'REA'], [
            'nombre'         => 'REACTIVOS DE LABORATORIO',
            'ambito'         => AmbitoCatalogo::Productos,
            'orden'          => 50,
            'vigencia_desde' => self::VIGENCIA_DESDE,
        ]);

        $estantes = [];

        foreach (Sede::query()->orderBy('id')->get() as $sede) {
            $estantes[] = $this->estanteDe($sede);
        }

        $infinito = config('sihla.inventario.stock_infinito_al_crear') === true;
        $kits = [];
        $nuevos = 0;

        foreach (self::KITS as $nombre) {
            $nombreDelKit = 'KIT '.$nombre;
            $kit = Item::query()->withTrashed()->where('nombre', $nombreDelKit)->first();

            if (! $kit instanceof Item) {
                $kit = Item::query()->create([
                    'codigo'                    => app(AsignadorDeCodigoDeItem::class)->siguiente($categoria),
                    'nombre'                    => $nombreDelKit,
                    'tipo'                      => TipoItem::Insumo,
                    'se_almacena'               => true,
                    'stock_infinito'            => $infinito,
                    'categoria_id'              => $categoria->id,
                    'regimen_isv'               => RegimenIsv::Exento,
                    'politica_cargo'            => PoliticaCargo::GastoDelServicio,
                    'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                    'unidad_dispensacion_id'    => $prueba->id,
                    'requiere_lote'             => true,
                    'requiere_receta'           => false,
                    'es_controlado'             => false,
                    'vigencia_desde'            => self::VIGENCIA_DESDE,
                ]);
                $nuevos++;
            }

            if ($kit->tieneStockInfinito()) {
                foreach ($estantes as $estante) {
                    app(UbicadorDeProducto::class)->ubicar($kit, $estante);
                }
            }

            $kits[$nombre] = $kit;
        }

        $this->examenDeDengue();

        $recetas = 0;
        $faltan = [];

        foreach (self::RECETAS as $examen => $gasta) {
            $item = Item::query()
                ->where('nombre', $examen)
                ->where('codigo', 'like', 'LAB-%')
                ->first();

            if (! $item instanceof Item) {
                $faltan[] = $examen;

                continue;
            }

            foreach ($gasta as $nombre) {
                $kit = $kits[$nombre] ?? null;

                if (! $kit instanceof Item) {
                    continue;
                }

                $receta = ConsumoDeServicio::query()->firstOrCreate(
                    ['item_id' => $item->id, 'insumo_id' => $kit->id],
                    ['cantidad' => '1'],
                );

                if ($receta->wasRecentlyCreated) {
                    $recetas++;
                }
            }
        }

        $this->command?->info("✓ {$nuevos} kits nuevos; {$recetas} exámenes con lo que gastan.");

        if ($faltan !== []) {
            $this->command?->warn('  No están en el catálogo (no se les puso qué gastan): '.implode(', ', $faltan).'.');
        }
    }

    /**
     * El servicio LABORATORIO de la sede y su estante. Si ya hay uno —con
     * el nombre que la sede le haya puesto—, se usa ese.
     */
    private function estanteDe(Sede $sede): Almacen
    {
        $existente = Almacen::query()
            ->deTodasLasSedes()
            ->where('sede_id', $sede->id)
            ->delLaboratorio()
            ->orderBy('id')
            ->first();

        if ($existente instanceof Almacen) {
            return $existente;
        }

        $servicio = Servicio::query()
            ->deTodasLasSedes()
            ->where('sede_id', $sede->id)
            ->where('tipo', TipoServicio::Laboratorio->value)
            ->orderBy('id')
            ->first()
            ?? Servicio::query()->create([
                'sede_id'        => $sede->id,
                'nombre'         => 'LABORATORIO',
                'tipo'           => TipoServicio::Laboratorio,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ]);

        return Almacen::query()->create([
            'sede_id'            => $sede->id,
            'servicio_id'        => $servicio->id,
            'codigo'             => app(AsignadorDeCodigoDeAlmacen::class)->siguiente(TipoAlmacen::StockDeServicio, $sede->id),
            'nombre'             => 'LABORATORIO',
            'tipo'               => TipoAlmacen::StockDeServicio,
            'maneja_controlados' => false,
            'vigencia_desde'     => self::VIGENCIA_DESDE,
        ]);
    }

    /**
     * «Un solo examen DENGUE» (Mauricio, 16-nov-2026): NS1 + IgG/IgM, con un
     * casete. Se crea una vez; si ya existe, no se le toca el precio.
     */
    private function examenDeDengue(): void
    {
        if (Item::query()->withTrashed()->where('nombre', self::DENGUE)->exists()) {
            return;
        }

        $laboratorio = CategoriaItem::query()->where('codigo', 'LAB')->first();

        if (! $laboratorio instanceof CategoriaItem) {
            $this->command?->warn('  Sin la categoría LABORATORIO no se creó el examen '.self::DENGUE.'.');

            return;
        }

        $und = Unidad::query()->where('codigo', 'UND')->firstOrFail();

        $dengue = Item::query()->create([
            'codigo'                    => app(AsignadorDeCodigoDeItem::class)->siguiente($laboratorio),
            'nombre'                    => self::DENGUE,
            'tipo'                      => TipoItem::EstudioLaboratorio,
            'categoria_id'              => $laboratorio->id,
            'regimen_isv'               => RegimenIsv::Exento,
            'politica_cargo'            => PoliticaCargo::Cobrable,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
            'unidad_dispensacion_id'    => $und->id,
            'requiere_lote'             => false,
            'requiere_receta'           => false,
            'es_controlado'             => false,
            'vigencia_desde'            => self::VIGENCIA_DESDE,
        ]);

        ListaPendiente::precioReal(
            item: $dengue,
            precio: self::PRECIO_DEL_DENGUE,
            motivo: 'Examen nuevo DENGUE NS1 + IGG/IGM (Mauricio, 16-nov-2026): un solo examen con un casete.',
            vigenciaDesde: self::VIGENCIA_DESDE,
        );
    }
}
