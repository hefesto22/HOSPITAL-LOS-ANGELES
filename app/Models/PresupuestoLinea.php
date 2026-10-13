<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\OrigenLineaPresupuesto;
use App\Domain\Enums\RegimenIsv;
use Database\Factories\PresupuestoLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del presupuesto, con el precio congelado (ADR-0008).
 *
 * ⚠️ Un trigger de la base rechaza cualquier escritura sobre las líneas
 * de un presupuesto que ya no está en `borrador`. Corregir un emitido es
 * emitir uno nuevo que lo sustituya, no editar este.
 *
 * @property int $id
 * @property int $presupuesto_id
 * @property int $orden
 * @property int|null $item_id
 * @property int|null $presentacion_id
 * @property OrigenLineaPresupuesto $origen
 * @property string $texto
 * @property numeric-string $cantidad
 * @property int|null $unidad_id
 * @property numeric-string $precio_unitario
 * @property int|null $tarifario_id
 * @property OrigenDelPrecio|null $origen_precio
 * @property CategoriaLegalDeDescuento|null $categoria_legal
 * @property numeric-string $descuento_legal_fraccion
 * @property numeric-string $descuento
 * @property RegimenIsv $regimen_isv
 * @property numeric-string $tasa_isv
 * @property numeric-string $bruto
 * @property numeric-string $subtotal
 * @property numeric-string $base_exenta
 * @property numeric-string $base_gravada
 * @property numeric-string $isv
 * @property numeric-string $total
 * @property bool $opcional
 * @property string|null $nota
 * @property bool $completa_el_monto
 */
class PresupuestoLinea extends Model
{
    /** @use HasFactory<PresupuestoLineaFactory> */
    use HasFactory;

    protected $table = 'presupuesto_lineas';

    /** @var list<string> */
    protected $fillable = [
        'presupuesto_id',
        'orden',
        'item_id',
        'presentacion_id',
        'origen',
        'texto',
        'cantidad',
        'unidad_id',
        'precio_unitario',
        'tarifario_id',
        'origen_precio',
        'categoria_legal',
        'descuento_legal_fraccion',
        'descuento',
        'regimen_isv',
        'tasa_isv',
        'bruto',
        'subtotal',
        'base_exenta',
        'base_gravada',
        'isv',
        'total',
        'opcional',
        'nota',
        'completa_el_monto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden'             => 'integer',
            'origen'            => OrigenLineaPresupuesto::class,
            'origen_precio'     => OrigenDelPrecio::class,
            'categoria_legal'   => CategoriaLegalDeDescuento::class,
            'regimen_isv'       => RegimenIsv::class,
            'opcional'          => 'boolean',
            'completa_el_monto' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Presupuesto, $this>
     */
    public function presupuesto(): BelongsTo
    {
        return $this->belongsTo(Presupuesto::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * El envase del que sale: el precio del medicamento depende de él.
     *
     * @return BelongsTo<ItemPresentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        /*
         * Con las retiradas: un envase que cambió de tamaño se reemplaza y
         * el viejo queda borrado lógicamente, pero lo que pasó con él se
         * sigue leyendo como era (13-oct-2026).
         */
        return $this->belongsTo(ItemPresentacion::class, 'presentacion_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    /**
     * @return BelongsTo<Tarifario, $this>
     */
    public function tarifario(): BelongsTo
    {
        return $this->belongsTo(Tarifario::class);
    }

    /**
     * ¿Se le puede cambiar el nombre a este renglón?
     *
     * Solo a los que escribió una persona: la holgura —el colchón se
     * llama como se acuerde con la familia: IMPREVISTOS, MARGEN POR
     * COMPLICACIONES— y lo agregado a mano sin ítem detrás. Un renglón
     * del catálogo dice lo que dice su ítem: renombrarlo dejaría el papel
     * diciendo una cosa y el reporte de presupuestado contra real
     * comparando otra.
     */
    public function seLePuedeCambiarElNombre(): bool
    {
        return $this->origen === OrigenLineaPresupuesto::Holgura
            || ($this->origen === OrigenLineaPresupuesto::Manual && $this->item_id === null);
    }

    /**
     * ¿Su precio es el de lista, sin negociación?
     *
     * Medicamentos y materiales (Mauricio, 12-oct-2026): su precio lo
     * deriva el costo por margen —la Ruta A del motor de precios— y no se
     * regatea en el presupuesto. Lo que se negocia con la familia son los
     * servicios, los honorarios y la holgura.
     *
     * ⚠️ Usa el ítem: cargarlo antes (`with('item')`) en las listas, o es
     * una consulta por renglón.
     */
    public function precioEsDeLista(): bool
    {
        if ($this->item_id === null) {
            return false;
        }

        $item = $this->item;

        return $item instanceof Item && $item->tipo->precioDerivadoDelCosto();
    }
}
