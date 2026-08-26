<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\PlantillaPresupuestoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La lista típica de una cirugía (ADR-0008).
 *
 * No guarda precios: guarda ítems y cantidades. El precio se resuelve al
 * cotizar con el convenio del caso, y por eso una sola plantilla sirve
 * para el particular y para PALIG.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $dias_vigencia
 * @property numeric-string $holgura_fraccion
 * @property numeric-string|null $tope_referencia
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class PlantillaPresupuesto extends Model
{
    use HasAuditFields;

    /** @use HasFactory<PlantillaPresupuestoFactory> */
    use HasFactory;

    protected $table = 'plantillas_presupuesto';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'dias_vigencia',
        'holgura_fraccion',
        'tope_referencia',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dias_vigencia'  => 'integer',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * @return HasMany<PlantillaLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(PlantillaLinea::class, 'plantilla_id')->orderBy('orden');
    }

    /**
     * Cuántos presupuestos salieron de esta plantilla.
     *
     * No es estadística: es lo que hace flotar solas a las plantillas
     * buenas y deja a la vista las muertas para retirarlas con vigencia.
     *
     * @return HasMany<Presupuesto, $this>
     */
    public function presupuestos(): HasMany
    {
        return $this->hasMany(Presupuesto::class, 'plantilla_id');
    }

    /**
     * Mismo criterio que `CategoriaItem::scopeVigentesEn`, a propósito:
     * una plantilla retirada se deja de ofrecer igual que un ítem
     * retirado, y sigue explicando los presupuestos que la usaron.
     *
     * @param Builder<PlantillaPresupuesto> $consulta
     *
     * @return Builder<PlantillaPresupuesto>
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

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
