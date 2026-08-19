<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\MargenObjetivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El margen que el hospital quiere ganar sobre el costo.
 *
 * `1.2000` es 120 %: un producto que costó L 10.00 deja L 12.00.
 *
 * @property int $id
 * @property TipoItem|null $tipo_item nulo = default de la instalación
 * @property string $porcentaje
 * @property string $motivo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class MargenObjetivo extends Model
{
    use HasAuditFields;

    /** @use HasFactory<MargenObjetivoFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'margenes_objetivo';

    /** @var list<string> */
    protected $fillable = [
        'tipo_item',
        'porcentaje',
        'motivo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /** @var list<string> */
    protected $guarded = ['vigencia'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_item'      => TipoItem::class,
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    public function fraccion(): Decimal
    {
        return Decimal::de($this->porcentaje);
    }

    public function esElDefault(): bool
    {
        return $this->tipo_item === null;
    }

    /**
     * ⚠️ La fecha es obligatoria. Un margen resuelto contra "hoy"
     * explica el precio de 2026 con la política de 2029.
     *
     * @param Builder<MargenObjetivo> $consulta
     *
     * @return Builder<MargenObjetivo>
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

    /**
     * El margen del tipo, o el default si ese tipo no tiene el suyo.
     *
     * Los dos vienen en la misma consulta y se ordena poniendo primero
     * el específico: una sola ida a la base y sin `if` en el servicio.
     *
     * @param Builder<MargenObjetivo> $consulta
     *
     * @return Builder<MargenObjetivo>
     */
    public function scopeParaElTipo(Builder $consulta, TipoItem $tipo): Builder
    {
        return $consulta
            ->where(function (Builder $sub) use ($tipo): void {
                $sub->where('tipo_item', $tipo->value)
                    ->orWhereNull('tipo_item');
            })
            ->orderByRaw('tipo_item is null');
    }
}
