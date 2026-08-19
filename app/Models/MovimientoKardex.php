<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\ValueObjects\Decimal;
use Carbon\CarbonInterface;
use Database\Factories\MovimientoKardexFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un movimiento del kardex. Nunca se edita, nunca se borra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS CANDADOS, Y EL DE ABAJO ES EL QUE MANDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un trigger de PostgreSQL rechaza `UPDATE` y `DELETE` sobre esta tabla.
 * Ese es el candado real: vale aunque la escritura venga de un comando,
 * de tinker o de una consulta cruda.
 *
 * Los eventos de abajo son el segundo, y existen solo para dar un mensaje
 * en castellano antes de llegar a la base. No confiar en ellos: un
 * `DB::table('movimientos_kardex')->delete()` los esquiva y solo lo para
 * el trigger.
 *
 * Un movimiento equivocado se corrige **con otro movimiento**. Si se
 * pudiera borrar, la pregunta «¿dónde se fueron las 40 ampollas?» no
 * tendría respuesta posible.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $lote_id
 * @property int $almacen_id
 * @property TipoMovimiento $tipo
 * @property string $cantidad con signo: positiva entra, negativa sale
 * @property string $saldo_despues
 * @property string|null $motivo
 * @property string|null $referencia
 * @property CarbonInterface $ocurrido_en
 * @property string|null $costo_unitario
 * @property string|null $costo_promedio_despues
 */
class MovimientoKardex extends Model
{
    /** @use HasFactory<MovimientoKardexFactory> */
    use HasFactory;

    protected $table = 'movimientos_kardex';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'lote_id',
        'almacen_id',
        'tipo',
        'cantidad',
        'saldo_despues',
        'motivo',
        'referencia',
        'ocurrido_en',
        'costo_unitario',
        'costo_promedio_despues',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'        => TipoMovimiento::class,
            'ocurrido_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'El kardex es append-only: un movimiento equivocado se corrige con otro '
                .'movimiento, no editando el original.'
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'El kardex es append-only: un movimiento no se borra. Si estuvo mal, se asienta '
                .'el ajuste que lo corrige y quedan los dos.'
            );
        });
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
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * La cantidad con su signo. Positiva entró, negativa salió.
     */
    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }

    /**
     * La cantidad sin signo, que es como se lee en un reporte.
     */
    public function cantidadAbsoluta(): Decimal
    {
        $cantidad = $this->cantidadDecimal();

        return $cantidad->esNegativo() ? $cantidad->por('-1') : $cantidad;
    }

    /**
     * Lo que costó la unidad en ESTE movimiento, o nulo si no se sabe.
     *
     * Nulo y no cero: los movimientos anteriores a que el sistema
     * costeara no tienen costo y no lo van a tener —el kardex es
     * append-only, rellenarlos sería inventar el dato—. Un cero
     * significaría «costó cero», que es otra cosa y pasa de verdad con
     * las donaciones.
     */
    public function costoUnitarioDecimal(): ?Decimal
    {
        return $this->costo_unitario === null ? null : Decimal::de($this->costo_unitario);
    }

    /**
     * El promedio ponderado que quedó vigente después de este movimiento.
     */
    public function costoPromedioDespuesDecimal(): ?Decimal
    {
        return $this->costo_promedio_despues === null
            ? null
            : Decimal::de($this->costo_promedio_despues);
    }

    public function saldoDespuesDecimal(): Decimal
    {
        return Decimal::de($this->saldo_despues);
    }

    /**
     * @param Builder<MovimientoKardex> $consulta
     *
     * @return Builder<MovimientoKardex>
     */
    public function scopeDelItemEnElAlmacen(Builder $consulta, int $itemId, int $almacenId): Builder
    {
        return $consulta
            ->where('item_id', $itemId)
            ->where('almacen_id', $almacenId);
    }
}
