<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoServicio;
use App\Models\Concerns\BelongsToSede;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Services\AsignadorDeCodigoDeServicio;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ServicioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Servicio o área de la sede (§8.1).
 *
 * Es donde se atiende al paciente: define el encuentro, las camas y el
 * centro de costo al que se imputa lo que se consume y no se factura.
 *
 * NO es un almacén. Un servicio puede tener almacén propio (carro de paro),
 * varios, o ninguno y consumir del dispensario.
 *
 * @property int $id
 * @property int $sede_id
 * @property string $codigo
 * @property string $nombre
 * @property TipoServicio $tipo
 * @property string|null $centro_costo
 * @property CarbonInterface|null $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Servicio extends Model
{
    use BelongsToSede;
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ServicioFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'codigo',
        'nombre',
        'tipo',
        'centro_costo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL CÓDIGO SE PONE ACÁ Y NO EN LA PANTALLA
     * ─────────────────────────────────────────────────────────────────
     *
     * Un área se crea desde tres lugares: su propia pantalla, el modal
     * que aparece al crear un almacén de servicio, y los seeders. Poner
     * la asignación en la página de create dejaría los otros dos caminos
     * pidiendo un código a mano — y el del modal es justamente el de
     * alguien que estaba haciendo otra cosa.
     *
     * `filled()` y no `empty()`: un código explícito —el de un seeder, el
     * de una migración de datos— manda siempre. Esto solo rellena el
     * hueco.
     */
    protected static function booted(): void
    {
        static::creating(function (Servicio $servicio): void {
            if (filled($servicio->codigo)) {
                return;
            }

            $servicio->codigo = app(AsignadorDeCodigoDeServicio::class)->siguiente(
                (string) $servicio->nombre,
                $servicio->sede_id,
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['codigo', 'nombre', 'centro_costo'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'           => TipoServicio::class,
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * Almacenes propios de este servicio. Puede no tener ninguno.
     *
     * @return HasMany<Almacen, $this>
     */
    public function almacenes(): HasMany
    {
        return $this->hasMany(Almacen::class);
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
