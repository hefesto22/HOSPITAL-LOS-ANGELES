<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que gasta un examen (o un servicio) cada vez que se hace: «GLUCOSA
 * gasta 1 prueba del kit de glucosa» (16-nov-2026).
 *
 * Es configuración, no código (§1.1): se arma en la ficha del examen,
 * en «Qué gasta». Un examen sin filas no gasta nada —así quedan los que
 * se mandan a un laboratorio externo—.
 *
 * Lo que de verdad se gastó en cada cargo vive en `ConsumoDeCargo`:
 * cambiar esta receta no reescribe lo que ya pasó.
 *
 * @property int $id
 * @property int $item_id
 * @property int $insumo_id
 * @property numeric-string $cantidad
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ConsumoDeServicio extends Model
{
    use HasAuditFields;

    protected $table = 'consumos_de_servicio';

    protected $fillable = [
        'item_id',
        'insumo_id',
        'cantidad',
    ];

    /**
     * El examen que gasta.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * El kit (o el insumo) que se gasta.
     *
     * @return BelongsTo<Item, $this>
     */
    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'insumo_id');
    }

    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }
}
