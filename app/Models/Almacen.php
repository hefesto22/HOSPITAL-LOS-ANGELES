<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoAlmacen;
use App\Models\Concerns\BelongsToSede;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\AlmacenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Almacén — dónde vive físicamente el producto (§8.1).
 *
 * Cada almacén lleva su propio kardex y su propio costo promedio
 * ponderado: dos sedes, o dos bodegas, que compran al mismo proveedor a
 * precios distintos no comparten costo.
 *
 * @property int $id
 * @property int $sede_id
 * @property int|null $servicio_id
 * @property string $codigo
 * @property string $nombre
 * @property TipoAlmacen $tipo
 * @property bool $maneja_controlados
 * @property CarbonInterface|null $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Almacen extends Model
{
    use BelongsToSede;
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<AlmacenFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'almacenes';

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'servicio_id',
        'codigo',
        'nombre',
        'tipo',
        'maneja_controlados',
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
            'tipo'               => TipoAlmacen::class,
            'maneja_controlados' => 'boolean',
            'vigencia_desde'     => 'date',
            'vigencia_hasta'     => 'date',
        ];
    }

    /**
     * Servicio dueño del almacén. Null en bodega central y farmacia de
     * venta, que no cuelgan de ningún área.
     *
     * @return BelongsTo<Servicio, $this>
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
