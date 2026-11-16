<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Models\Concerns\BelongsToSede;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que se gastó de verdad al cargar un examen: qué kit, de qué lote,
 * cuánto y con qué movimiento de kardex (16-nov-2026).
 *
 * Es la trazabilidad lote → paciente (§9.F9): ante un lote de reactivo
 * retirado, dice a qué pacientes se les hizo el examen con él. Y es lo
 * que se devuelve al estante si el cargo se anula —`revertido_en`—.
 *
 * Con el kit en stock infinito se anota igual, `sin_existencia`: no
 * mueve el kardex, pero dice cuánto se usó mientras no se cuenta.
 *
 * Sin `HasAuditFields`: la tabla no tiene `updated_by`, y la fila solo
 * se toca una vez después de nacer —al revertirse—, con su propia fecha.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $cargo_id
 * @property CarbonInterface $fecha_operacion
 * @property int $insumo_id
 * @property int $almacen_id
 * @property int|null $lote_id
 * @property int|null $movimiento_id
 * @property int|null $devolucion_id
 * @property numeric-string $cantidad
 * @property numeric-string|null $costo_unitario
 * @property bool $sin_existencia
 * @property CarbonInterface $ocurrido_en
 * @property CarbonInterface|null $revertido_en
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ConsumoDeCargo extends Model
{
    use BelongsToSede;

    protected $table = 'consumos_de_cargo';

    /**
     * Ningún campo se llena de afuera: lo escribe `GastosDelServicio`, con
     * `forceCreate`, igual que los cargos.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_operacion' => 'date',
            'sin_existencia'  => 'boolean',
            'ocurrido_en'     => 'datetime',
            'revertido_en'    => 'datetime',
        ];
    }

    /**
     * Con `withTrashed()`: lo que ya pasó se sigue leyendo aunque el kit,
     * el almacén o el lote se hayan retirado después (§9.M21).
     *
     * @return BelongsTo<Item, $this>
     */
    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'insumo_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class)->withoutGlobalScope('sede')->withTrashed();
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class)->withTrashed();
    }

    /**
     * Los que todavía cuentan: no se devolvieron por una anulación.
     *
     * @param Builder<static> $consulta
     *
     * @return Builder<static>
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->whereNull($consulta->qualifyColumn('revertido_en'));
    }

    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }
}
