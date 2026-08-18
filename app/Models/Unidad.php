<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\MagnitudDeMedida;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Database\Factories\UnidadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidad de medida del catálogo.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $simbolo
 * @property MagnitudDeMedida $magnitud
 * @property bool $permite_fraccion
 */
class Unidad extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<UnidadFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'unidades';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'simbolo',
        'magnitud',
        'permite_fraccion',
    ];

    /**
     * El símbolo NO se pasa a mayúsculas: "ml" y "ML" son cosas
     * distintas en el Sistema Internacional, y "mg" en mayúscula deja de
     * ser miligramo. Es el mismo criterio por el que el email y el
     * teléfono quedaron fuera en `Persona`.
     *
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
            'magnitud'         => MagnitudDeMedida::class,
            'permite_fraccion' => 'boolean',
        ];
    }

    /**
     * Ítems que se dispensan en esta unidad.
     *
     * @return HasMany<Item, $this>
     */
    public function itemsQueDispensa(): HasMany
    {
        return $this->hasMany(Item::class, 'unidad_dispensacion_id');
    }

    /**
     * Presentaciones de compra expresadas en esta unidad.
     *
     * @return HasMany<ItemPresentacion, $this>
     */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(ItemPresentacion::class, 'unidad_id');
    }

    /**
     * ¿Alguien la está usando? Si sí, no se debería dar de baja.
     */
    public function estaEnUso(): bool
    {
        return $this->itemsQueDispensa()->exists()
            || $this->presentaciones()->exists()
            || Item::query()->where('unidad_fraccion_id', $this->getKey())->exists();
    }

    /**
     * @param Builder<Unidad> $consulta
     *
     * @return Builder<Unidad>
     */
    public function scopeDeMagnitud(Builder $consulta, MagnitudDeMedida $magnitud): Builder
    {
        return $consulta->where('magnitud', $magnitud->value);
    }

    public function etiqueta(): string
    {
        return $this->simbolo === null || $this->simbolo === ''
            ? $this->nombre
            : "{$this->nombre} ({$this->simbolo})";
    }
}
