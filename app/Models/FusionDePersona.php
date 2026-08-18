<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoFusion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El expediente de una decisión de fusión (§9.D4).
 *
 * ⚠️ Este modelo NO se escribe fuera de `App\Services\FusionadorDePersonas`.
 * Cambiar `estado` a mano dejaría la fila diciendo "aplicada" sin que
 * `personas.merged_into` exista, o al revés — dos versiones distintas de
 * la verdad sobre si dos pacientes son el mismo.
 *
 * @property int $id
 * @property int $persona_duplicada_id
 * @property int $persona_sobreviviente_id
 * @property EstadoFusion $estado
 * @property string $motivo
 * @property int $propuesta_por
 * @property CarbonInterface $propuesta_en
 * @property int|null $resuelta_por
 * @property CarbonInterface|null $resuelta_en
 * @property string|null $resolucion_nota
 * @property int|null $deshecha_por
 * @property CarbonInterface|null $deshecha_en
 * @property string|null $deshecha_motivo
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class FusionDePersona extends Model
{
    protected $table = 'fusiones_de_persona';

    /** @var list<string> */
    protected $fillable = [
        'persona_duplicada_id',
        'persona_sobreviviente_id',
        'estado',
        'motivo',
        'propuesta_por',
        'propuesta_en',
        'resuelta_por',
        'resuelta_en',
        'resolucion_nota',
        'deshecha_por',
        'deshecha_en',
        'deshecha_motivo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'       => EstadoFusion::class,
            'propuesta_en' => 'datetime',
            'resuelta_en'  => 'datetime',
            'deshecha_en'  => 'datetime',
        ];
    }

    /**
     * La bandeja: lo que espera que alguien decida.
     *
     * @param Builder<FusionDePersona> $consulta
     *
     * @return Builder<FusionDePersona>
     */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoFusion::Propuesta->value);
    }

    /**
     * ¿Este usuario puede resolverla?
     *
     * Solo comprueba el control de cuatro ojos. Si además TIENE permiso
     * para fusionar lo decide la matriz de roles: son dos preguntas
     * distintas y mezclarlas hace que ninguna se pueda leer.
     */
    public function puedeResolverla(User $usuario): bool
    {
        return $this->estado->esperaDecision()
            && $this->propuesta_por !== $usuario->getKey();
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function duplicada(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_duplicada_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function sobreviviente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_sobreviviente_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function propuestaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propuesta_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deshechaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deshecha_por');
    }
}
