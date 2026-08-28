<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\RegimenIsv;
use App\Domain\ValueObjects\Decimal;
use Database\Factories\FacturaLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón impreso, congelado el día de la emisión.
 *
 * @property int $id
 * @property int $factura_id
 * @property int $orden
 * @property int|null $cargo_id
 * @property string|null $codigo
 * @property string $descripcion
 * @property numeric-string $cantidad
 * @property numeric-string $precio_unitario
 * @property numeric-string $bruto
 * @property numeric-string $descuento_legal
 * @property numeric-string $descuento_comercial
 * @property RegimenIsv $regimen_isv
 * @property numeric-string $tasa_isv
 * @property numeric-string $exento
 * @property numeric-string $gravado
 * @property numeric-string $isv
 * @property numeric-string $total
 */
class FacturaLinea extends Model
{
    /** @use HasFactory<FacturaLineaFactory> */
    use HasFactory;

    protected $table = 'factura_lineas';

    /** @var list<string> */
    protected $fillable = [
        'factura_id', 'orden', 'cargo_id', 'codigo', 'descripcion', 'cantidad', 'precio_unitario',
        'bruto', 'descuento_legal', 'descuento_comercial',
        'regimen_isv', 'tasa_isv', 'exento', 'gravado', 'isv', 'total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regimen_isv' => RegimenIsv::class,
            'orden'       => 'integer',
            'cargo_id'    => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    public function descuento(): Decimal
    {
        return Decimal::de($this->descuento_legal)->sumar($this->descuento_comercial);
    }
}
