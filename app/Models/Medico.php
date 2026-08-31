<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\MedicoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un médico que cobra honorario en el hospital.
 *
 * 🔴 NO es un usuario del sistema. El cirujano externo que opera un
 * sábado cobra honorario y nunca entra a SIHLA. `user_id` existe para el
 * que sí entra, y es nulo la mayoría de las veces.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $identidad
 * @property int $especialidad_id
 * @property string|null $colegiacion
 * @property string|null $telefono
 * @property int|null $user_id
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 * @property-read Especialidad $especialidad
 */
class Medico extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<MedicoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'identidad',
        'especialidad_id',
        'colegiacion',
        'telefono',
        'user_id',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * El teléfono NO se toca: no es texto que se lea, es un dato que se
     * marca, y pasarlo por la forma canónica solo puede romperlo.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['nombre', 'colegiacion'];
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
     * @return BelongsTo<Especialidad, $this>
     */
    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lo que este médico cobra, honorario por honorario.
     *
     * @return HasMany<HonorarioMedico, $this>
     */
    public function honorarios(): HasMany
    {
        return $this->hasMany(HonorarioMedico::class);
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
     * @param Builder<Medico> $query
     *
     * @return Builder<Medico>
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

    /**
     * Lo que este médico cobra por ese honorario, o nulo si no tiene
     * lista propia y hay que ir al tarifario.
     *
     * ⚠️ La relación tiene que venir cargada o esto es una consulta por
     * renglón. Los llamadores de pantalla usan `ResolutorDeHonorario`,
     * que memoriza; esto está acá para el que ya tiene el médico con sus
     * honorarios en la mano.
     */
    public function loQueCobraPor(int $itemId, ?CarbonInterface $momento = null): ?Monto
    {
        $fila = $this->honorarios
            ->where('item_id', $itemId)
            ->first(fn (HonorarioMedico $honorario): bool => $honorario->estaVigente($momento));

        return $fila instanceof HonorarioMedico ? $fila->monto() : null;
    }

    /**
     * «CARLOS PINEDA · CIRUGÍA GENERAL» — lo que se lee en el selector y
     * lo que queda escrito en el renglón de la cuenta.
     */
    public function etiqueta(): string
    {
        $especialidad = $this->especialidad instanceof Especialidad
            ? ' · '.$this->especialidad->nombre
            : '';

        return $this->nombre.$especialidad;
    }
}
