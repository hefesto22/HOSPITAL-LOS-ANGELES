<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que se contó de un producto en un estante.
 *
 * ⚠️ `cantidad_contada` en NULO significa **«nadie la contó todavía»**,
 * que no es lo mismo que «hay cero». La diferencia entre esas dos cosas
 * es la razón por la que un conteo total no cierra con líneas pendientes:
 * dar por cero lo que nadie miró borra el estante de un producto entero.
 *
 * `diferencia` la calcula PostgreSQL como columna generada. No se escribe
 * nunca desde PHP y por eso no está en `$fillable`: una resta hecha a
 * mano en algún lugar del código es una resta que algún día va a estar
 * mal en ese lugar y bien en los otros.
 *
 * @property int $id
 * @property int $conteo_id
 * @property int $item_id
 * @property int|null $lote_id
 * @property string|null $cantidad_sistema
 * @property string|null $cantidad_contada
 * @property string|null $diferencia
 * @property CarbonInterface|null $contado_en
 * @property int|null $contado_por
 * @property string|null $primer_conteo
 * @property CarbonInterface|null $primer_conteo_en
 * @property int|null $primer_conteo_por
 * @property int $veces_contado
 * @property bool $exige_recuento
 * @property string|null $ultimo_envio
 * @property string|null $notas
 */
class ConteoLinea extends Model
{
    protected $table = 'conteo_lineas';

    /** @var list<string> */
    protected $fillable = [
        'conteo_id',
        'item_id',
        'lote_id',
        'cantidad_sistema',
        'cantidad_contada',
        'contado_en',
        'contado_por',
        'primer_conteo',
        'primer_conteo_en',
        'primer_conteo_por',
        'veces_contado',
        'exige_recuento',
        'ultimo_envio',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contado_en'       => 'datetime',
            'primer_conteo_en' => 'datetime',
            'veces_contado'    => 'integer',
            'exige_recuento'   => 'boolean',

            /*
             * Los ids se castean a propósito. Un `bigint` que vuelve del
             * driver como texto hace que `$linea->contado_por === $quien`
             * sea falso siempre, y ese `===` es la mitad de un control de
             * identidad. Es la misma razón por la que existe
             * `UsuarioAutenticado::id()`.
             */
            'contado_por'       => 'integer',
            'primer_conteo_por' => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Conteo, $this>
     */
    public function conteo(): BelongsTo
    {
        return $this->belongsTo(Conteo::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contado_por');
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function estaContada(): bool
    {
        return $this->cantidad_contada !== null;
    }

    public function diferenciaDecimal(): Decimal
    {
        return Decimal::de($this->diferencia ?? '0');
    }

    public function cantidadContadaDecimal(): Decimal
    {
        return Decimal::de($this->cantidad_contada ?? '0');
    }

    public function cantidadSistemaDecimal(): Decimal
    {
        return Decimal::de($this->cantidad_sistema ?? '0');
    }

    public function cuadro(): bool
    {
        return $this->estaContada() && $this->diferenciaDecimal()->esCero();
    }

    public function sobro(): bool
    {
        return $this->estaContada() && ! $this->diferenciaDecimal()->esNegativo()
            && ! $this->diferenciaDecimal()->esCero();
    }

    public function falto(): bool
    {
        return $this->estaContada() && $this->diferenciaDecimal()->esNegativo();
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /**
     * @param Builder<ConteoLinea> $consulta
     *
     * @return Builder<ConteoLinea>
     */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->whereNull($consulta->qualifyColumn('cantidad_contada'));
    }

    /**
     * Las que no cuadraron. Es lo único que el cierre asienta.
     *
     * @param Builder<ConteoLinea> $consulta
     *
     * @return Builder<ConteoLinea>
     */
    public function scopeConDiferencia(Builder $consulta): Builder
    {
        return $consulta
            ->whereNotNull($consulta->qualifyColumn('cantidad_contada'))
            ->where($consulta->qualifyColumn('diferencia'), '<>', 0);
    }

    /**
     * @param Builder<ConteoLinea> $consulta
     *
     * @return Builder<ConteoLinea>
     */
    public function scopeExigenRecuento(Builder $consulta): Builder
    {
        return $consulta->where($consulta->qualifyColumn('exige_recuento'), true);
    }
}
