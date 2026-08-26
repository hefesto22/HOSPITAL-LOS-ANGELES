<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlantillaLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la plantilla: qué ítem y cuánto, sin precio (ADR-0008).
 *
 * @property int $id
 * @property int $plantilla_id
 * @property int $item_id
 * @property numeric-string $cantidad
 * @property int $orden
 * @property bool $opcional
 * @property string|null $nota
 */
class PlantillaLinea extends Model
{
    /** @use HasFactory<PlantillaLineaFactory> */
    use HasFactory;

    protected $table = 'plantilla_lineas';

    /** @var list<string> */
    protected $fillable = [
        'plantilla_id',
        'item_id',
        'cantidad',
        'orden',
        'opcional',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden'    => 'integer',
            'opcional' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PlantillaPresupuesto, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaPresupuesto::class, 'plantilla_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
