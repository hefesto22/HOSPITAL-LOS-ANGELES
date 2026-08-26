<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ConteoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un conteo físico: el instrumento de medición, no el ajuste.
 *
 * Que esta fila exista NO significa que se movió nada. Contar y ajustar
 * son dos hechos distintos y tienen dos documentos distintos: cerrar el
 * conteo genera un `Ajuste`, y es ese el que mueve el kardex.
 *
 * `abierto` es el único estado que admite escritura, y no es una
 * convención del modelo: un trigger de PostgreSQL rechaza cualquier
 * cambio sobre un conteo cerrado o anulado, y otro rechaza cualquier
 * escritura en sus líneas.
 *
 * @property int $id
 * @property int $almacen_id
 * @property EstadoConteo $estado
 * @property AlcanceDeConteo $alcance
 * @property string|null $descripcion
 * @property string $tolerancia_recuento
 * @property CarbonInterface $abierto_en
 * @property CarbonInterface|null $cerrado_en
 * @property int|null $cerrado_por
 * @property CarbonInterface|null $anulado_en
 * @property string|null $motivo_anulacion
 * @property string|null $notas_del_cierre
 * @property string|null $notas
 * @property int|null $created_by
 */
class Conteo extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ConteoFactory> */
    use HasFactory;

    protected $table = 'conteos';

    /** @var list<string> */
    protected $fillable = [
        'almacen_id',
        'estado',
        'alcance',
        'descripcion',
        'tolerancia_recuento',
        'abierto_en',
        'notas',
        'notas_del_cierre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'     => EstadoConteo::class,
            'alcance'    => AlcanceDeConteo::class,
            'abierto_en' => 'datetime',
            'cerrado_en' => 'datetime',
            'anulado_en' => 'datetime',
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
     * @return HasMany<ConteoLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(ConteoLinea::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /**
     * El ajuste que generó al cerrar, si generó alguno.
     *
     * Puede no haber ninguno: un conteo donde todo cuadró cierra sin
     * mover nada, y eso es una buena noticia, no un caso raro.
     *
     * @return HasOne<Ajuste, $this>
     */
    public function ajuste(): HasOne
    {
        return $this->hasOne(Ajuste::class);
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function estaAbierto(): bool
    {
        return $this->estado === EstadoConteo::Abierto;
    }

    public function estaCerrado(): bool
    {
        return $this->estado === EstadoConteo::Cerrado;
    }

    public function toleranciaDecimal(): Decimal
    {
        return Decimal::de($this->tolerancia_recuento);
    }

    /**
     * Sin tocar `almacen`: leer una relación desde un método del modelo
     * es lazy loading disfrazado, y `Model::preventLazyLoading()` está
     * activo en desarrollo y en tests a propósito (§13.2). Donde hace
     * falta el nombre del almacén, la consulta ya lo trae.
     */
    public function etiqueta(): string
    {
        return "Conteo #{$this->id}";
    }

    /**
     * Cuántas líneas faltan por contar.
     *
     * Es la pregunta de la pantalla mientras se cuenta y la del cierre
     * cuando el conteo es total. Va con `whereNull` y no con un accessor
     * sobre la colección: en un conteo de trescientas líneas, cargarlas
     * todas para contarlas es exactamente el N+1 invisible del §13.2.
     */
    public function cuantasFaltan(): int
    {
        return $this->lineas()->whereNull('cantidad_contada')->count();
    }

    public function cuantasExigenRecuento(): int
    {
        return $this->lineas()->where('exige_recuento', true)->count();
    }

    public function cuantasNoCuadraron(): int
    {
        return $this->lineas()->where('diferencia', '<>', 0)->count();
    }

    /**
     * ¿A esta persona hay que ocultarle lo que el sistema espera?
     *
     * ─────────────────────────────────────────────────────────────────
     * EL RECUENTO A CIEGAS (§9.G4)
     * ─────────────────────────────────────────────────────────────────
     *
     * Quien está contando NO puede ver el saldo del sistema ni la
     * diferencia: si los ve, escribe el número que el sistema espera y el
     * conteo deja de medir el estante para pasar a confirmar el sistema
     * —que es exactamente lo contrario de para lo que existe—.
     *
     * «Quien está contando» es quien abrió el conteo **o** quien ya contó
     * alguna línea, no solo el primero: en un conteo de dos personas, la
     * segunda también está contando.
     *
     * Para todos los demás —el que va a cerrar, dirección, auditoría— los
     * números están a la vista desde el primer momento: revisar las
     * diferencias antes de asentarlas es su trabajo, y el control de
     * cuatro ojos garantiza que no sea la misma persona.
     */
    public function esCiegoPara(?int $usuarioId): bool
    {
        if (! $this->estaAbierto() || $usuarioId === null) {
            return false;
        }

        if ($this->created_by === $usuarioId) {
            return true;
        }

        return $this->lineas()->where('contado_por', $usuarioId)->exists();
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /**
     * @param Builder<Conteo> $consulta
     *
     * @return Builder<Conteo>
     */
    public function scopeAbiertos(Builder $consulta): Builder
    {
        return $consulta->where(
            $consulta->qualifyColumn('estado'),
            EstadoConteo::Abierto->value,
        );
    }

    /**
     * @param Builder<Conteo> $consulta
     *
     * @return Builder<Conteo>
     */
    public function scopeDelAlmacen(Builder $consulta, int $almacenId): Builder
    {
        return $consulta->where($consulta->qualifyColumn('almacen_id'), $almacenId);
    }
}
