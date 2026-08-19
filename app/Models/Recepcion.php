<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\RecepcionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que entró al estante.
 *
 * Que esta fila exista significa que el kardex YA se movió y el costo
 * promedio YA se recalculó: `RegistradorDeRecepcion` hace las tres cosas
 * en la misma transacción. No hay borrador y no hay confirmación.
 *
 * La revisión posterior —`revisada_en`, `revisada_por`— no bloquea nada.
 * Es la constancia de que otra persona miró los números, y lo que le da
 * sentido es el reporte de las que faltan revisar.
 *
 * @property int $id
 * @property int $almacen_id
 * @property int|null $proveedor_id
 * @property string|null $referencia
 * @property CarbonInterface $fecha_recepcion
 * @property CarbonInterface|null $revisada_en
 * @property int|null $revisada_por
 * @property string|null $notas
 */
class Recepcion extends Model
{
    use HasAuditFields;

    /** @use HasFactory<RecepcionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'recepciones';

    /** @var list<string> */
    protected $fillable = [
        'almacen_id',
        'proveedor_id',
        'referencia',
        'fecha_recepcion',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'date',
            'revisada_en'     => 'datetime',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return HasMany<RecepcionLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(RecepcionLinea::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function estaRevisada(): bool
    {
        return $this->revisada_en !== null;
    }

    /**
     * Cuánto costó todo lo que trajo el camión.
     *
     * Se suma línea por línea con bcmath en vez de pedirle un `SUM()` a
     * la base: son unas pocas filas y el §8.6.2 vale también para leer.
     */
    public function costoTotal(): Decimal
    {
        return $this->lineas
            ->reduce(
                fn (Decimal $suma, RecepcionLinea $linea): Decimal => $suma->sumar($linea->costoDeLaLinea()),
                Decimal::cero(),
            );
    }

    /**
     * Cuántas unidades de dispensación entraron en total.
     */
    public function unidadesTotales(): Decimal
    {
        return $this->lineas
            ->reduce(
                fn (Decimal $suma, RecepcionLinea $linea): Decimal => $suma->sumar($linea->cantidadEnUnidades()),
                Decimal::cero(),
            );
    }

    public function etiqueta(): string
    {
        return $this->referencia ?? "Recepción #{$this->id}";
    }

    /**
     * Las que nadie miró todavía. Es el reporte que sostiene el control.
     *
     * @param Builder<Recepcion> $consulta
     *
     * @return Builder<Recepcion>
     */
    public function scopeSinRevisar(Builder $consulta): Builder
    {
        return $consulta->whereNull($consulta->qualifyColumn('revisada_en'));
    }
}
