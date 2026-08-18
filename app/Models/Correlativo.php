<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoCorrelativo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contador de una secuencia por (sede, tipo, año).
 *
 * ⚠️ NADIE debe escribir en este modelo directamente. La única puerta es
 * `App\Services\AsignadorDeCorrelativo`, que es quien toma el lock. Un
 * `$correlativo->increment('ultimo_numero')` suelto por ahí se salta la
 * serialización y produce duplicados bajo concurrencia.
 *
 * No usa BelongsToSede a propósito: el asignador consulta la fila de una
 * sede explícita, y un scope global que dependa del usuario autenticado
 * haría que un job de consola no encuentre el contador.
 *
 * @property int $id
 * @property int $sede_id
 * @property TipoCorrelativo $tipo
 * @property int|null $anio
 * @property int $ultimo_numero
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Correlativo extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'tipo',
        'anio',
        'ultimo_numero',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'          => TipoCorrelativo::class,
            'anio'          => 'integer',
            'ultimo_numero' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }
}
