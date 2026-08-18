<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoExpediente;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ExpedienteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Expediente — la carpeta de un paciente en una sede.
 *
 * Usa BelongsToSede, a diferencia de Persona: la identidad es de la
 * organización, la carpeta es de la sede (ADR-0002).
 *
 * ⚠️ Este modelo NO se crea a mano en ningún lado que no sea
 * `App\Services\RegistradorDePacientes`. Un expediente sin correlativo
 * tomado con lock, o sin la versión 1 del historial de la persona, es un
 * registro a medias que después nadie puede explicar (§11: los Services
 * son la única puerta de escritura).
 *
 * @property int $id
 * @property int $sede_id
 * @property int $persona_id
 * @property string $numero
 * @property CarbonInterface $abierto_el
 * @property EstadoExpediente $estado
 * @property CarbonInterface|null $ultima_atencion_el
 * @property string|null $ubicacion_fisica
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Expediente extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<ExpedienteFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'persona_id',
        'numero',
        'abierto_el',
        'estado',
        'ultima_atencion_el',
        'ubicacion_fisica',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'             => EstadoExpediente::class,
            'abierto_el'         => 'date',
            'ultima_atencion_el' => 'datetime',
        ];
    }

    /**
     * Las URLs llevan el número del expediente, no el id.
     *
     * Además de no filtrar cuántos pacientes tiene el hospital, es lo que
     * el personal ya tiene escrito en la carpeta: pegar EXP-HLA-00000042 en
     * la barra del navegador lleva al expediente correcto.
     */
    public function getRouteKeyName(): string
    {
        return 'numero';
    }

    /**
     * Deja constancia de que hubo atención hoy y recalcula el archivo.
     *
     * Un paciente que vuelve después de ocho años saca su carpeta del
     * archivo pasivo: el estado no es una etiqueta que alguien pone, es
     * consecuencia de la última atención.
     */
    public function registrarAtencion(CarbonInterface $momento): void
    {
        $this->ultima_atencion_el = $momento;
        $this->estado = EstadoExpediente::resolverPara(
            $momento,
            $this->abierto_el,
            $momento,
        );

        $this->save();
    }

    public function estadoEn(CarbonInterface $fecha): EstadoExpediente
    {
        return EstadoExpediente::resolverPara(
            $this->ultima_atencion_el,
            $this->abierto_el,
            $fecha,
        );
    }

    /**
     * @param Builder<Expediente> $consulta
     *
     * @return Builder<Expediente>
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoExpediente::Activo->value);
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
