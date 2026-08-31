<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\HonorarioMedicoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que un médico cobra por un honorario del catálogo, para un pagador.
 *
 * `convenio_id` nulo = el precio GENERAL del médico: vale para todo
 * pagador que no tenga fila propia. Un doctor no le cobra lo mismo al
 * particular que al del Hospital Militar o al de PALIG, y esa diferencia
 * es una fila más, no una corrección a mano en cada cobro.
 *
 * ⚠️ MISMA BASE QUE EL TARIFARIO: precio unitario ANTES de ISV.
 *
 * @property int $id
 * @property int $medico_id
 * @property int $item_id
 * @property int|null $convenio_id
 * @property numeric-string $precio
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 * @property-read Item $item
 * @property-read Medico $medico
 * @property-read Convenio|null $convenio
 */
class HonorarioMedico extends Model
{
    use HasAuditFields;

    /** @use HasFactory<HonorarioMedicoFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'honorarios_medicos';

    /** @var list<string> */
    protected $fillable = [
        'medico_id',
        'item_id',
        'convenio_id',
        'precio',
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
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * El pagador al que le cobra este precio, o nulo cuando es el precio
     * general del médico —el que vale para todo pagador que no tenga
     * fila propia—.
     *
     * @return BelongsTo<Convenio, $this>
     */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /**
     * El precio como monto, que es como lo pide `LineaDeCargo`.
     */
    public function monto(): Monto
    {
        return Monto::de($this->precio);
    }

    public function estaVigente(?CarbonInterface $momento = null): bool
    {
        $dia = ($momento ?? now())->startOfDay();

        if ($this->vigencia_desde->greaterThan($dia)) {
            return false;
        }

        return $this->vigencia_hasta === null || ! $this->vigencia_hasta->lessThan($dia);
    }

    /**
     * @param Builder<HonorarioMedico> $query
     *
     * @return Builder<HonorarioMedico>
     */
    public function scopeVigentes(Builder $query, ?CarbonInterface $momento = null): Builder
    {
        $dia = ($momento ?? now())->toDateString();

        return $query
            ->whereDate('vigencia_desde', '<=', $dia)
            ->where(fn (Builder $consulta): Builder => $consulta
                ->whereNull('vigencia_hasta')
                ->orWhereDate('vigencia_hasta', '>=', $dia));
    }
}
