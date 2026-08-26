<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoDeAjuste;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonInterface;
use Database\Factories\AjusteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un ajuste asentado: el kardex YA se movió.
 *
 * ⚠️ Append-only, y no por convención: un trigger de PostgreSQL rechaza
 * cualquier `UPDATE` y cualquier `DELETE`. Un ajuste que se pudiera
 * editar dejaría el documento diciendo «se rompieron 2» y el kardex
 * diciendo −40, sin nada que delate cuál de los dos miente.
 *
 * Un ajuste equivocado se corrige con OTRO ajuste, de tipo corrección y
 * con su explicación. Es la misma regla que rige el kardex, la factura y
 * la nota clínica firmada (§9.0.3).
 *
 * @property int $id
 * @property int $almacen_id
 * @property int|null $conteo_id
 * @property TipoDeAjuste $tipo
 * @property string|null $referencia
 * @property string|null $clave_idempotencia
 * @property CarbonInterface $fecha_operacion
 * @property CarbonInterface $ocurrido_en
 * @property string $motivo
 * @property string $valor_absoluto
 * @property int|null $autorizado_por
 * @property CarbonInterface|null $autorizado_en
 * @property string|null $notas
 * @property int|null $created_by
 */
class Ajuste extends Model
{
    /** @use HasFactory<AjusteFactory> */
    use HasFactory;

    protected $table = 'ajustes';

    /** @var list<string> */
    protected $fillable = [
        'almacen_id',
        'conteo_id',
        'tipo',
        'referencia',
        'clave_idempotencia',
        'fecha_operacion',
        'ocurrido_en',
        'motivo',
        'valor_absoluto',
        'autorizado_por',
        'autorizado_en',
        'notas',

        /*
         * ⚠️ `created_by` VA en `$fillable`, y no es un descuido al revés.
         *
         * `Ajuste` NO usa `HasAuditFields` —ese trait también escribe
         * `updated_by` y `deleted_by`, y acá esas columnas no existen
         * porque nada se actualiza ni se borra—, así que el id de quien
         * asienta se pasa a mano en el `create()`. Sin esta línea,
         * `Model::fill()` lo descartaría EN SILENCIO y todos los ajustes
         * quedarían sin autor: la única cosa que el propio docblock de
         * este modelo declara como imprescindible.
         */
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'            => TipoDeAjuste::class,
            'fecha_operacion' => 'date',
            'ocurrido_en'     => 'datetime',
            'autorizado_en'   => 'datetime',
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
     * @return BelongsTo<Conteo, $this>
     */
    public function conteo(): BelongsTo
    {
        return $this->belongsTo(Conteo::class);
    }

    /**
     * @return HasMany<AjusteLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(AjusteLinea::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function estaAutorizado(): bool
    {
        return $this->autorizado_por !== null;
    }

    public function vinoDeUnConteo(): bool
    {
        return $this->conteo_id !== null;
    }

    /**
     * Cuánta plata movió, en valor absoluto y al costo del momento.
     */
    public function valor(): Monto
    {
        return Monto::de(Decimal::de($this->valor_absoluto));
    }

    public function etiqueta(): string
    {
        return $this->referencia ?? "Ajuste #{$this->id}";
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /**
     * @param Builder<Ajuste> $consulta
     *
     * @return Builder<Ajuste>
     */
    public function scopeDelTipo(Builder $consulta, TipoDeAjuste $tipo): Builder
    {
        return $consulta->where($consulta->qualifyColumn('tipo'), $tipo->value);
    }

    /**
     * Los que pasaron el tope y alguien tuvo que autorizar. Es el reporte
     * que dirección mira, y la razón de que el tope exista.
     *
     * @param Builder<Ajuste> $consulta
     *
     * @return Builder<Ajuste>
     */
    public function scopeAutorizados(Builder $consulta): Builder
    {
        return $consulta->whereNotNull($consulta->qualifyColumn('autorizado_por'));
    }

    /**
     * @param Builder<Ajuste> $consulta
     *
     * @return Builder<Ajuste>
     */
    public function scopeEntre(
        Builder $consulta,
        CarbonInterface $desde,
        CarbonInterface $hasta,
    ): Builder {
        return $consulta->whereBetween(
            $consulta->qualifyColumn('fecha_operacion'),
            [$desde->toDateString(), $hasta->toDateString()],
        );
    }
}
