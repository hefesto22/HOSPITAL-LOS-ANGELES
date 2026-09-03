<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\RecepcionLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «100 cajas de acetaminofén de 100 tabletas a L 1.000 la caja.»
 *
 * Tres números que no son el mismo y que la pantalla muestra juntos a
 * propósito:
 *
 *   · **100 cajas** — lo que dice la factura;
 *   · **10.000 tabletas** — lo que entra al kardex (columna generada);
 *   · **L 10,00 por tableta** — lo que alimenta el costo promedio.
 *
 * `unidades_por_presentacion` y `costo_unitario` quedan CONGELADOS acá.
 * El día que el laboratorio cambie el envase y alguien corrija el
 * catálogo, esta línea sigue explicando el movimiento que ya está
 * asentado.
 *
 * @property int $id
 * @property int $recepcion_id
 * @property int $item_id
 * @property int|null $item_presentacion_id
 * @property int|null $lote_id
 * @property string $cantidad_presentacion
 * @property string $unidades_por_presentacion
 * @property string $cantidad_dispensacion
 * @property string $costo_por_presentacion
 * @property string $costo_unitario
 * @property string|null $numero_lote
 * @property CarbonInterface|null $fecha_vencimiento
 * @property string|null $notas
 */
class RecepcionLinea extends Model
{
    use HasAuditFields;

    /** @use HasFactory<RecepcionLineaFactory> */
    use HasFactory;

    protected $table = 'recepcion_lineas';

    /** @var list<string> */
    protected $fillable = [
        'recepcion_id',
        'item_id',
        'item_presentacion_id',
        'lote_id',
        'cantidad_presentacion',
        'unidades_por_presentacion',
        'costo_por_presentacion',
        'costo_unitario',
        'numero_lote',
        'fecha_vencimiento',
        'notas',
    ];

    /*
     * `cantidad_dispensacion` queda fuera de `$fillable`: la genera
     * PostgreSQL y rechaza cualquier intento de escribirla. Si apareciera
     * acá, un `create()` con ese campo terminaría en un error de SQL
     * crudo en vez de ser simplemente imposible.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Recepcion, $this>
     */
    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(Recepcion::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemPresentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ItemPresentacion::class, 'item_presentacion_id');
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    // ── Cantidades y costos ───────────────────────────────────────────

    /**
     * Lo que entró al kardex, en unidad de dispensación.
     */
    public function cantidadEnUnidades(): Decimal
    {
        return Decimal::de($this->cantidad_dispensacion);
    }

    public function cantidadEnPresentacion(): Decimal
    {
        return Decimal::de($this->cantidad_presentacion);
    }

    public function contenidoDeLaPresentacion(): Decimal
    {
        return Decimal::de($this->unidades_por_presentacion);
    }

    /**
     * Lo que costó una unidad, impuesto incluido.
     */
    public function costoUnitario(): Decimal
    {
        return Decimal::de($this->costo_unitario);
    }

    /**
     * Lo que costó la línea entera: cajas × precio por caja.
     */
    public function costoDeLaLinea(): Decimal
    {
        return Decimal::de($this->costo_por_presentacion)->por($this->cantidad_presentacion);
    }

    /**
     * «100 × CAJA X 100 = 10.000 · L 10,00 c/u»
     *
     * Las tres cifras juntas: es lo que hace que quien revisa note que
     * 100 cajas de 100 no pueden ser 9.800 tabletas.
     */
    public function resumen(): string
    {
        $presentacion = $this->presentacion?->envase() ?? 'unidad de dispensación';

        $cajas = self::sinCerosSobrantes($this->cantidad_presentacion);
        $unidades = self::sinCerosSobrantes($this->cantidad_dispensacion);
        $costo = $this->costoUnitario()->redondeado(2);

        return "{$cajas} × {$presentacion} = {$unidades} · L {$costo} c/u";
    }

    /**
     * «100.0000» → «100», «0.5000» → «0.5».
     *
     * En dos pasadas y no en una: con `rtrim($valor, '0.')` el valor
     * «100.0000» perdería también los ceros del entero y quedaría en «1».
     */
    private static function sinCerosSobrantes(string $valor): string
    {
        if (! str_contains($valor, '.')) {
            return $valor;
        }

        return rtrim(rtrim($valor, '0'), '.');
    }
}
