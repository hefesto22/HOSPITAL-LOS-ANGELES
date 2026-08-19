<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\LoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un lote del fabricante: mismo producto, distinto vencimiento.
 *
 * No lleva `almacen_id`. Un lote puede estar repartido entre la bodega y
 * la farmacia, y su vencimiento es el mismo en las dos porque lo puso el
 * laboratorio. Cuánto hay en cada lugar es `Existencia`.
 *
 * @property int $id
 * @property int $item_id
 * @property string $numero
 * @property CarbonInterface|null $fecha_vencimiento
 * @property CarbonInterface|null $fecha_fabricacion
 * @property string|null $registro_sanitario
 * @property string|null $proveedor
 * @property string|null $notas
 */
class Lote extends Model
{
    use HasAuditFields;

    /** @use HasFactory<LoteFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'lotes';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'numero',
        'fecha_vencimiento',
        'fecha_fabricacion',
        'registro_sanitario',
        'proveedor',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'fecha_fabricacion' => 'date',
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
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * ¿Ya venció a esa fecha?
     *
     * Un lote sin fecha de vencimiento nunca vence. Es el caso del
     * material que se rastrea por número de serie y no caduca.
     */
    public function estaVencidoAl(CarbonInterface $fecha): bool
    {
        return $this->fecha_vencimiento !== null
            && $this->fecha_vencimiento->endOfDay()->lessThan($fecha);
    }

    /**
     * Días que faltan para vencer, o `null` si no vence.
     *
     * Negativo si ya venció: quien lo lee necesita saber hace cuánto.
     */
    public function diasParaVencerDesde(CarbonInterface $fecha): ?int
    {
        if ($this->fecha_vencimiento === null) {
            return null;
        }

        /*
         * El cast a int no es cosmético: desde Carbon 3 los `diff*`
         * devuelven float, y con `strict_types` eso sería un TypeError
         * contra el `?int` declarado. Como los dos extremos pasan por
         * `startOfDay()`, la parte decimal siempre es cero.
         */
        return (int) $fecha->copy()->startOfDay()
            ->diffInDays($this->fecha_vencimiento->copy()->startOfDay(), false);
    }

    /**
     * FEFO — First Expired, First Out.
     *
     * Sale primero lo que vence primero, NO lo que entró primero. Con
     * medicamentos, FIFO deja vencer en el estante el lote viejo mientras
     * se despacha el nuevo.
     *
     * Los lotes sin vencimiento van al final: no corren riesgo, así que no
     * tienen por qué desplazar a los que sí.
     *
     * @param Builder<Lote> $consulta
     *
     * @return Builder<Lote>
     */
    public function scopeEnOrdenFefo(Builder $consulta): Builder
    {
        return $consulta
            ->orderByRaw('fecha_vencimiento asc nulls last')
            ->orderBy('id');
    }

    /**
     * @param Builder<Lote> $consulta
     *
     * @return Builder<Lote>
     */
    public function scopeVigentesAl(Builder $consulta, CarbonInterface $fecha): Builder
    {
        return $consulta->where(function (Builder $sub) use ($fecha): void {
            $sub->whereNull('fecha_vencimiento')
                ->orWhereDate('fecha_vencimiento', '>=', $fecha->toDateString());
        });
    }
}
