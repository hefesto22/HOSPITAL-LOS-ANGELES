<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * De qué mínimo salen los precios de farmacia de un producto, en una sede,
 * entre dos fechas (13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE SE GUARDA ES EL MÍNIMO POR UNIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se escribe «lo mínimo por la CAJA X 100: L 120», y eso se guarda tal
 * cual —`minimo` 120, `contenido` 100— para que se lea como se escribió.
 * Pero lo que se conserva cuando algo cambia es el cociente: L 1.20 por
 * tableta. Si la caja pasa a traer 120, su mínimo pasa a L 144; si la
 * principal pasa a ser el blíster de 10, su mínimo es L 12. Ninguna
 * tableta queda más barata que lo que se decidió (Mauricio: «no vender a
 * pérdida»).
 *
 * La escribe una persona (`FijadorDePreciosPorForma::fijar`). Los ajustes
 * automáticos la leen y no la tocan (`ReajustadorDePrecios`).
 *
 * `sede_id` nulo vale para todas las sedes y pierde contra la de la sede,
 * igual que el tarifario.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $sede_id
 * @property int|null $item_presentacion_id el envase en que se pensó, solo para mostrar
 * @property string $minimo lempiras por ese envase, cuatro decimales
 * @property string $contenido unidades del kardex en ese envase
 * @property string $recargo_envase fracción: 0.1000 es 10 %
 * @property string $recargo_suelta fracción: 0.2500 es 25 %
 * @property string $motivo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class PrecioMinimo extends Model
{
    use HasAuditFields;
    use SoftDeletes;

    protected $table = 'precios_minimos';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'sede_id',
        'item_presentacion_id',
        'minimo',
        'contenido',
        'recargo_envase',
        'recargo_suelta',
        'motivo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
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
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * El mínimo por unidad del kardex: L 1.20 la tableta de la caja de
     * 100 a L 120. Es lo que se conserva cuando cambia un envase.
     */
    public function porUnidad(): Decimal
    {
        return Decimal::de($this->minimo)->entre($this->contenido);
    }

    /**
     * Lo mínimo por un envase que trae `$contenido` unidades.
     *
     * Si es el mismo envase con que se escribió, el número exacto que se
     * escribió —L 120.00, no 1.2 × 100 vuelto a multiplicar—: así un
     * redondeo de la división nunca empuja la lista un escalón arriba.
     */
    public function minimoPara(Decimal $contenido): Decimal
    {
        if ($contenido->igualA($this->contenido)) {
            return Decimal::de($this->minimo);
        }

        return Decimal::de($this->minimo)->por($contenido)->entre($this->contenido);
    }

    public function recargoEnvase(): Decimal
    {
        return Decimal::de($this->recargo_envase);
    }

    public function recargoSuelta(): Decimal
    {
        return Decimal::de($this->recargo_suelta);
    }

    /**
     * El que rige ese día para esa sede: el de la sede gana al de todas.
     *
     * ⚠️ La fecha es obligatoria, igual que en el tarifario: un mínimo
     * resuelto contra «hoy» recalcularía el precio de marzo con la
     * política de septiembre.
     */
    public static function queRigePara(Item $item, ?int $sedeId, CarbonInterface $dia): ?self
    {
        $minimo = static::query()
            ->where('item_id', $item->id)
            ->where(function (Builder $sub) use ($sedeId): void {
                $sub->whereNull('sede_id');

                if ($sedeId !== null) {
                    $sub->orWhere('sede_id', $sedeId);
                }
            })
            ->vigentesEn($dia)
            ->orderByRaw('sede_id is null')
            ->first();

        return $minimo instanceof self ? $minimo : null;
    }

    /**
     * @param Builder<PrecioMinimo> $consulta
     *
     * @return Builder<PrecioMinimo>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->toDateString();

        return $consulta
            ->whereDate('vigencia_desde', '<=', $dia)
            ->where(function (Builder $sub) use ($dia): void {
                $sub->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $dia);
            });
    }
}
