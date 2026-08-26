<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AmbitoCatalogo;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\CategoriaItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Categoría del catálogo — la hoja del tarifario.
 *
 * Las que trae el hospital arrancan calcadas del tarifario impreso de
 * PALIG, que es como el personal ya lee sus precios: Consulta externa,
 * Área de hospitalización, Equipo médico, Rayos X, Laboratorio. Del lado
 * de farmacia, lo que se guarda en el estante.
 *
 * ⚠️ No es el área que ejecuta (`Servicio`) ni el centro de costo. Las
 * bombas de infusión son categoría «Equipo médico» y se cobran en
 * hospitalización.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property AmbitoCatalogo $ambito
 * @property string|null $descripcion
 * @property int $orden
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class CategoriaItem extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<CategoriaItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'categorias_item';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'ambito',
        'descripcion',
        'orden',
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
            'ambito'         => AmbitoCatalogo::class,
            'orden'          => 'integer',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'categoria_id');
    }

    /**
     * Categorías de un lado del catálogo.
     *
     * @param Builder<CategoriaItem> $consulta
     *
     * @return Builder<CategoriaItem>
     */
    public function scopeDelAmbito(Builder $consulta, AmbitoCatalogo $ambito): Builder
    {
        return $consulta->where('ambito', $ambito->value);
    }

    /**
     * Mismo criterio que `Item::scopeVigentesEn`, a propósito: una
     * categoría cerrada y un ítem cerrado se dejan de ofrecer igual.
     *
     * @param Builder<CategoriaItem> $consulta
     *
     * @return Builder<CategoriaItem>
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
