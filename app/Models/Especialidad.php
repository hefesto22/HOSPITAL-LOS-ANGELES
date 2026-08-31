<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\EspecialidadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Especialidad médica.
 *
 * Catálogo global: un cirujano general lo es en todas las sedes.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Especialidad extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<EspecialidadFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['codigo', 'nombre'];
    }

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
     * @return HasMany<Medico, $this>
     */
    public function medicos(): HasMany
    {
        return $this->hasMany(Medico::class);
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
     * @param  Builder<Especialidad>  $query
     * @return Builder<Especialidad>
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

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
