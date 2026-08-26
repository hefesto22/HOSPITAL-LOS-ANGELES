<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEgreso;
use App\Domain\Enums\TipoEncuentro;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\EncuentroFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El eje del que cuelga todo lo clínico y todo el dinero (§8.3).
 *
 * No lleva `SoftDeletes`: §12 lo reserva para catálogos y personas. Un
 * encuentro se anula con motivo y se queda a la vista.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $expediente_id
 * @property int $persona_id
 * @property string $numero
 * @property TipoEncuentro $tipo
 * @property EstadoEncuentro $estado
 * @property int|null $servicio_id
 * @property int|null $medico_tratante_id
 * @property string|null $motivo
 * @property CarbonInterface $abierto_en
 * @property CarbonInterface|null $alta_medica_en
 * @property CarbonInterface|null $alta_administrativa_en
 * @property CarbonInterface|null $salida_fisica_en
 * @property TipoEgreso|null $tipo_egreso
 * @property CarbonInterface|null $cerrado_en
 * @property CarbonInterface|null $anulado_en
 * @property string|null $motivo_anulacion
 * @property int|null $encuentro_anterior_id
 */
class Encuentro extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<EncuentroFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'expediente_id',
        'persona_id',
        'numero',
        'tipo',
        'estado',
        'servicio_id',
        'medico_tratante_id',
        'motivo',
        'abierto_en',
        'encuentro_anterior_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                   => TipoEncuentro::class,
            'estado'                 => EstadoEncuentro::class,
            'tipo_egreso'            => TipoEgreso::class,
            'abierto_en'             => 'datetime',
            'alta_medica_en'         => 'datetime',
            'alta_administrativa_en' => 'datetime',
            'salida_fisica_en'       => 'datetime',
            'cerrado_en'             => 'datetime',
            'anulado_en'             => 'datetime',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<Servicio, $this>
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function medicoTratante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medico_tratante_id');
    }

    /**
     * @return HasMany<Cuenta, $this>
     */
    public function cuentas(): HasMany
    {
        return $this->hasMany(Cuenta::class);
    }

    /**
     * @return HasMany<Cargo, $this>
     */
    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function encuentroAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'encuentro_anterior_id');
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * @param Builder<static> $consulta
     *
     * @return Builder<static>
     */
    public function scopeVivos(Builder $consulta): Builder
    {
        return $consulta->whereIn(
            $consulta->qualifyColumn('estado'),
            EstadoEncuentro::valoresVivos(),
        );
    }

    // ── Preguntas ─────────────────────────────────────────────────────

    public function admiteCargos(): bool
    {
        return $this->estado->admiteCargos();
    }

    public function estaVivo(): bool
    {
        return $this->estado->estaVivo();
    }

    /**
     * La cuenta que está recibiendo cargos ahora. Puede no haber ninguna
     * si el pagador se está cambiando en este instante.
     */
    public function cuentaAbierta(): ?Cuenta
    {
        $cuenta = $this->cuentas()
            ->where('estado', EstadoCuenta::Abierta->value)
            ->first();

        return $cuenta instanceof Cuenta ? $cuenta : null;
    }

    /**
     * Cuántas horas lleva adentro. Es el número que la tarjeta muestra
     * cuando el ingreso es de hoy, y el que alimenta el indicador de
     * demora del egreso.
     */
    public function horasDesdeElIngreso(?CarbonInterface $ahora = null): int
    {
        $referencia = $this->salida_fisica_en ?? $ahora ?? now();

        return (int) $this->abierto_en->diffInHours($referencia);
    }

    public function etiqueta(): string
    {
        return $this->numero.' · '.$this->tipo->etiqueta();
    }
}
