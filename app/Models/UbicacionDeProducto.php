<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un almacén donde está un producto con stock infinito (13-oct-2026).
 *
 * No hay cantidad: el producto no se cuenta mientras sea infinito. Lo que
 * dice esta fila es «hay de esto en el CARRITO ROJO desde tal hora», y
 * sacarlo de ahí la cierra con `hasta` — nunca se borra.
 *
 * Se escribe únicamente desde `UbicadorDeProducto`.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $item_id
 * @property int $almacen_id
 * @property CarbonInterface $desde
 * @property CarbonInterface|null $hasta
 * @property string|null $motivo_cierre
 * @property int|null $cerrada_por
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class UbicacionDeProducto extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    protected $table = 'ubicaciones_de_producto';

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'item_id',
        'almacen_id',
        'desde',
        'hasta',
        'motivo_cierre',
        'cerrada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sede_id'     => 'integer',
            'item_id'     => 'integer',
            'almacen_id'  => 'integer',
            'cerrada_por' => 'integer',
            'desde'       => 'datetime',
            'hasta'       => 'datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Almacen, $this> */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * Las que siguen abiertas: dónde está HOY.
     *
     * @param Builder<UbicacionDeProducto> $consulta
     *
     * @return Builder<UbicacionDeProducto>
     */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->whereNull($consulta->qualifyColumn('hasta'));
    }
}
