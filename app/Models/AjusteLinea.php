<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto que se ajustó, con su motivo, su lote y lo que costó.
 *
 * `movimiento_id` apunta a la línea exacta del kardex que esta línea
 * produjo, y `conteo_linea_id` a la línea del conteo que la originó
 * cuando el ajuste vino de un conteo. Entre las dos, cualquier número
 * raro del kardex se puede seguir hasta la persona que lo contó y el
 * saldo que había en ese instante.
 *
 * Append-only, con el mismo trigger que protege al ajuste.
 *
 * @property int $id
 * @property int $ajuste_id
 * @property int|null $conteo_linea_id
 * @property int|null $movimiento_id
 * @property int $item_id
 * @property int|null $lote_id
 * @property MotivoDeAjuste $motivo
 * @property string $cantidad
 * @property string $costo_unitario
 * @property string $valor
 * @property string|null $texto
 */
class AjusteLinea extends Model
{
    protected $table = 'ajuste_lineas';

    /** @var list<string> */
    protected $fillable = [
        'ajuste_id',
        'conteo_linea_id',
        'movimiento_id',
        'item_id',
        'lote_id',
        'motivo',
        'cantidad',
        'costo_unitario',
        'valor',
        'texto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivo' => MotivoDeAjuste::class,
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Ajuste, $this>
     */
    public function ajuste(): BelongsTo
    {
        return $this->belongsTo(Ajuste::class);
    }

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
     * @return BelongsTo<MovimientoKardex, $this>
     */
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoKardex::class, 'movimiento_id');
    }

    /**
     * @return BelongsTo<ConteoLinea, $this>
     */
    public function conteoLinea(): BelongsTo
    {
        return $this->belongsTo(ConteoLinea::class, 'conteo_linea_id');
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }

    public function esEntrada(): bool
    {
        return ! $this->cantidadDecimal()->esNegativo();
    }

    public function valorMonto(): Monto
    {
        return Monto::de(Decimal::de($this->valor));
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /**
     * @param Builder<AjusteLinea> $consulta
     *
     * @return Builder<AjusteLinea>
     */
    public function scopeDelMotivo(Builder $consulta, MotivoDeAjuste $motivo): Builder
    {
        return $consulta->where($consulta->qualifyColumn('motivo'), $motivo->value);
    }

    /**
     * @param Builder<AjusteLinea> $consulta
     *
     * @return Builder<AjusteLinea>
     */
    public function scopeDelItem(Builder $consulta, int $itemId): Builder
    {
        return $consulta->where($consulta->qualifyColumn('item_id'), $itemId);
    }
}
