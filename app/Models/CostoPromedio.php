<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuánto cuesta hoy una unidad, en este almacén.
 *
 * ⚠️ Esto es un SALDO, no la verdad. La verdad son las líneas de
 * recepción, que guardan cada costo con su fecha; esta fila es ese
 * promedio ponderado ya calculado para no recorrer dos años de compras
 * cada vez que alguien pregunta cuánto vale el inventario.
 *
 * Se escribe únicamente desde `CalculadoraDeCostoPromedio`, con la fila
 * bloqueada y dentro de la transacción que asienta la recepción.
 * Cualquier otra escritura deja el costo divorciado de su historia — y
 * hay un test que compara los dos números.
 *
 * @property int $id
 * @property int $item_id
 * @property int $almacen_id
 * @property string $costo_unitario
 * @property string $cantidad_base
 * @property CarbonInterface|null $actualizado_en
 */
class CostoPromedio extends Model
{
    protected $table = 'costos_promedio';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'almacen_id',
        'costo_unitario',
        'cantidad_base',
        'actualizado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actualizado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function costoDecimal(): Decimal
    {
        return Decimal::de($this->costo_unitario);
    }

    public function cantidadBaseDecimal(): Decimal
    {
        return Decimal::de($this->cantidad_base);
    }
}
