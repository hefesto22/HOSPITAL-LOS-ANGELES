<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoAlmacen;
use App\Models\Concerns\BelongsToSede;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\AlmacenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Todo lo que hay guardado acá, lote por lote.
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }

    /**
     * ¿Este estante todavía recibe y entrega?
     *
     * Un almacén se cierra poniéndole fecha de fin, no borrándolo: el
     * kardex es append-only y sus movimientos tienen que seguir siendo
     * consultables. Cerrado significa que ya no entra ni sale nada nuevo,
     * no que nunca existió.
     */
    public function estaVigente(?CarbonInterface $fecha = null): bool
    {
        $fecha ??= now();

        if ($this->vigencia_desde !== null && $this->vigencia_desde->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->endOfDay()->greaterThanOrEqualTo($fecha);
    }

    /**
     * Los estantes donde puede quedar algo que se pidió prestado.
     *
     * 🔴 NUNCA DEVUELVE VACÍO. Si un hospital no tiene ninguno de los
     * tipos que reciben préstamo —todo cargado como farmacia interna, por
     * ejemplo— el desplegable saldría en blanco y la persona con el
     * paciente enfrente no podría registrar nada. Ahí vuelven todos:
     * anotar el préstamo en el estante equivocado es peor que la regla,
     * pero es infinitamente mejor que no poder anotarlo. El hueco se
     * arregla cargando bien los tipos de almacén.
     *
     * @see TipoAlmacen::recibePrestamo() para cuáles y por qué
     *
     * @param Builder<Almacen> $consulta
     *
     * @return Builder<Almacen>
     */
    public function scopeQueRecibenPrestamo(Builder $consulta): Builder
    {
        $tipos = array_values(array_map(
            static fn (TipoAlmacen $tipo): string => $tipo->value,
            array_filter(
                TipoAlmacen::cases(),
                static fn (TipoAlmacen $tipo): bool => $tipo->recibePrestamo(),
            ),
        ));

        return $consulta->whereIn($consulta->qualifyColumn('tipo'), $tipos);
    }

    /**
     * Solo los estantes que hoy se pueden usar.
     *
     * @param Builder<Almacen> $consulta
     *
     * @return Builder<Almacen>
     */
    public function scopeVigentes(Builder $consulta, ?CarbonInterface $fecha = null): Builder
    {
        $fecha ??= now();

        return $consulta
            ->where(fn (Builder $sub): Builder => $sub
                ->whereNull($consulta->qualifyColumn('vigencia_desde'))
                ->orWhereDate($consulta->qualifyColumn('vigencia_desde'), '<=', $fecha->toDateString()))
            ->where(fn (Builder $sub): Builder => $sub
                ->whereNull($consulta->qualifyColumn('vigencia_hasta'))
                ->orWhereDate($consulta->qualifyColumn('vigencia_hasta'), '>=', $fecha->toDateString()));
    }
}
