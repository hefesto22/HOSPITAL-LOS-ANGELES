<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoDiagnostico;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\TipoDiagnostico;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Con qué entró el paciente y con qué salió.
 *
 * ⚠️ APPEND-ONLY (ADR-0004). No hay `SoftDeletes` y no se edita: se
 * corrige escribiendo otro que apunta a este, y este queda legible y
 * tachado. Lo que un perito busca no es el diagnóstico final — es qué se
 * pensó, cuándo se cambió de idea y quién lo cambió.
 *
 * @property int $id
 * @property int $encuentro_id
 * @property int $cie10_id
 * @property TipoDiagnostico $tipo
 * @property MomentoDiagnostico $momento
 * @property EstadoDiagnostico $estado
 * @property bool $confirmado
 * @property string|null $observacion
 * @property int $diagnosticado_por
 * @property CarbonInterface $diagnosticado_en
 * @property int|null $corrige_a_id
 * @property string|null $motivo_correccion
 */
class Diagnostico extends Model
{
    protected $table = 'diagnosticos';

    /** @var list<string> */
    protected $fillable = [
        'encuentro_id',
        'cie10_id',
        'tipo',
        'momento',
        'estado',
        'confirmado',
        'observacion',
        'diagnosticado_por',
        'diagnosticado_en',
        'corrige_a_id',
        'motivo_correccion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'             => TipoDiagnostico::class,
            'momento'          => MomentoDiagnostico::class,
            'estado'           => EstadoDiagnostico::class,
            'confirmado'       => 'boolean',
            'diagnosticado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Encuentro, $this>
     */
    public function encuentro(): BelongsTo
    {
        return $this->belongsTo(Encuentro::class);
    }

    /**
     * @return BelongsTo<Cie10, $this>
     */
    public function cie10(): BelongsTo
    {
        return $this->belongsTo(Cie10::class, 'cie10_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosticado_por');
    }

    /**
     * El diagnóstico al que este reemplaza, si es una enmienda.
     *
     * @return BelongsTo<self, $this>
     */
    public function corrigeA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrige_a_id');
    }

    /**
     * @param Builder<Diagnostico> $consulta
     *
     * @return Builder<Diagnostico>
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoDiagnostico::Vigente->value);
    }

    public function esPrincipal(): bool
    {
        return $this->tipo === TipoDiagnostico::Principal;
    }

    /**
     * ¿Este caso hay que reportarlo a la Secretaría de Salud?
     *
     * La respuesta no la escribe nadie en el diagnóstico: sale del
     * catálogo. Que la obligación del Art. 180 dependa de que alguien se
     * acuerde de marcar una casilla es lo mismo que no tenerla.
     */
    public function esNotificable(): bool
    {
        return $this->cie10?->es_notificable === true;
    }

    public function etiqueta(): string
    {
        return $this->cie10?->etiqueta() ?? 'Diagnóstico '.$this->id;
    }
}
