<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\SedeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sede — establecimiento del hospital.
 *
 * Es la raíz de la jerarquía del §8.1 y el eje del alcance de datos
 * (ADR-0002). Toda tabla transaccional cuelga de acá vía `sede_id`.
 *
 * ⚠️ Esta clase NO usa el trait BelongsToSede: sería recursivo, y además
 * un usuario tiene que poder ver la lista de sedes para elegir una.
 *
 * Los @property de abajo no son decoración: Larastan no deduce el tipo de
 * un atributo desde el método casts(), así que sin ellos ve `string` donde
 * hay un Carbon y marca error al llamar greaterThan(). Declararlos es la
 * forma correcta de enseñarle el modelo — y de paso el IDE autocompleta.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string $razon_social
 * @property string|null $rtn
 * @property string|null $codigo_establecimiento
 * @property string|null $registro_sesal
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $email
 * @property CarbonInterface|null $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Sede extends Model
{
    use HasAuditFields;

    /** @use HasFactory<SedeFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'razon_social',
        'rtn',
        'codigo_establecimiento',
        'registro_sesal',
        'direccion',
        'telefono',
        'email',
        'vigencia_desde',
        'vigencia_hasta',
    ];

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
     * Sedes vigentes en una fecha dada.
     *
     * La fecha es OBLIGATORIA a propósito, igual que en RangoEdad: un
     * default a "hoy" es cómodo hasta que alguien reimprime un documento
     * de hace dos años y la sede que lo emitió ya no aparece.
     *
     * @param Builder<Sede> $consulta
     *
     * @return Builder<Sede>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        return $consulta
            ->whereDate('vigencia_desde', '<=', $fecha)
            ->where(function (Builder $q) use ($fecha): void {
                $q->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $fecha);
            });
    }

    public function estaVigenteEn(CarbonInterface $fecha): bool
    {
        if ($this->vigencia_desde?->greaterThan($fecha) === true) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->greaterThanOrEqualTo($fecha);
    }

    /**
     * Tercera capa de la defensa del §10.4.
     *
     * El macro `->mayusculas()` cubre el formulario. Este mutator cubre
     * todo lo demás: seeders, imports de catálogo, comandos de consola y
     * cualquier código que escriba directo al modelo. El formulario no es
     * la única puerta.
     *
     * mb_strtoupper con UTF-8 explícito, no strtoupper: "peña" quedaría
     * "PEñA" y el catálogo se ve roto.
     *
     * @return Attribute<string, string>
     */
    protected function codigo(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtoupper(trim($value), 'UTF-8'),
        );
    }

    /**
     * @return HasMany<Servicio, $this>
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }

    /**
     * @return HasMany<Almacen, $this>
     */
    public function almacenes(): HasMany
    {
        return $this->hasMany(Almacen::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Etiqueta para selectores: "HLA — Hospital Los Ángeles".
     */
    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
