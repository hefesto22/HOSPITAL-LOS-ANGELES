<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\TipoDocumentoFiscal;
use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una factura o un recibo del proveedor — el papel, no la mercadería.
 *
 * ⚠️ Esto NO mueve inventario. Ni una unidad. Lo que entra al estante se
 * registra en `Recepcion`, que es otra tabla y otra pantalla.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property TipoDocumentoFiscal $tipo_documento
 * @property string|null $numero_documento
 * @property CarbonInterface $fecha_compra
 * @property CategoriaDeGasto $categoria_gasto
 * @property string $gravado_quince
 * @property string $isv
 * @property string $exento
 * @property string $total
 * @property string|null $notas
 */
class Compra extends Model
{
    use HasAuditFields;

    /** @use HasFactory<CompraFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'compras';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'tipo_documento',
        'numero_documento',
        'fecha_compra',
        'categoria_gasto',
        'gravado_quince',
        'isv',
        'exento',
        'total',
        'notas',
    ];

    /**
     * Un recibo nunca acredita ISV, y la base lo rechaza con un CHECK.
     *
     * Esto lo limpia ANTES de llegar ahí: si alguien cambia el tipo de
     * factura a recibo en la pantalla, los tres montos del desglose se
     * ocultan pero su valor VIEJO sigue en el modelo, y el guardado
     * terminaría en un error de SQL crudo en la cara del usuario.
     *
     * No es «arreglar el dato en silencio»: en un recibo esas tres
     * casillas no significan nada, así que ponerlas en cero no pierde
     * información que alguien haya querido guardar.
     */
    protected static function booted(): void
    {
        static::saving(function (Compra $compra): void {
            if ($compra->tipo_documento->acreditaIsv()) {
                return;
            }

            $compra->gravado_quince = '0';
            $compra->isv = '0';
            $compra->exento = '0';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_documento'  => TipoDocumentoFiscal::class,
            'categoria_gasto' => CategoriaDeGasto::class,
            'fecha_compra'    => 'date',
        ];
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function totalDecimal(): Decimal
    {
        return Decimal::de($this->total);
    }

    /**
     * El impuesto que de verdad se puede descontar del ISV de las ventas.
     *
     * Cero en un recibo, y no porque la casilla esté vacía: es que un
     * recibo no acredita. Preguntarlo por acá y no leer la columna suelta
     * es lo que evita que un reporte sume el ISV de los recibos.
     */
    public function isvAcreditable(): Decimal
    {
        return $this->tipo_documento->acreditaIsv()
            ? Decimal::de($this->isv)
            : Decimal::cero();
    }

    public function etiqueta(): string
    {
        $numero = $this->numero_documento ?? 'sin número';

        return "{$this->tipo_documento->etiqueta()} {$numero}";
    }

    /**
     * Las que entran al Libro de Compras del SAR.
     *
     * @param Builder<Compra> $consulta
     *
     * @return Builder<Compra>
     */
    public function scopeQueAcreditanIsv(Builder $consulta): Builder
    {
        return $consulta->where(
            $consulta->qualifyColumn('tipo_documento'),
            TipoDocumentoFiscal::Factura->value,
        );
    }

    /**
     * @param Builder<Compra> $consulta
     *
     * @return Builder<Compra>
     */
    public function scopeDelPeriodo(Builder $consulta, CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return $consulta->whereBetween(
            $consulta->qualifyColumn('fecha_compra'),
            [$desde->toDateString(), $hasta->toDateString()],
        );
    }
}
