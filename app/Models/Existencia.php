<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use Database\Factories\ExistenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuánto hay de un lote en un almacén.
 *
 * ⚠️ Esto es un SALDO, no la verdad. La verdad es la suma del kardex;
 * esta fila es esa suma ya calculada para no recorrer dos años de
 * movimientos cada vez que alguien pregunta cuánto hay.
 *
 * Se escribe únicamente desde `RegistradorDeMovimiento`, en la misma
 * transacción que asienta el movimiento. Cualquier otra escritura deja el
 * saldo divergido del kardex.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $lote_id
 * @property int $almacen_id
 * @property string $cantidad
 */
class Existencia extends Model
{
    /** @use HasFactory<ExistenciaFactory> */
    use HasFactory;

    protected $table = 'existencias';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'lote_id',
        'almacen_id',
        'cantidad',
    ];

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }

    public function hayAlgo(): bool
    {
        return ! $this->cantidadDecimal()->esCero();
    }

    /**
     * Solo las filas que todavía tienen algo que despachar.
     *
     * La columna va calificada —`existencias.cantidad`— porque este scope
     * se usa también dentro de consultas con join, y ahí una columna
     * suelta es una consulta que se cae con «ambiguous». Que el prefijo lo
     * ponga `qualifyColumn()` y no una cadena escrita a mano es lo que lo
     * mantiene correcto si algún día la tabla cambia de nombre.
     *
     * @param Builder<Existencia> $consulta
     *
     * @return Builder<Existencia>
     */
    public function scopeConSaldo(Builder $consulta): Builder
    {
        return $consulta->where($consulta->qualifyColumn('cantidad'), '>', 0);
    }
}
