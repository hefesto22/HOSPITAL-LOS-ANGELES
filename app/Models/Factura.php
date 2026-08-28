<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\FacturaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El documento fiscal emitido. Todo lo suyo está congelado.
 *
 * 🔴 NINGÚN GETTER DE ESTA CLASE LEE POR RELACIÓN PARA IMPRIMIR. El
 * nombre del cliente, el CAI y los montos salen de las columnas de esta
 * fila, no del paciente ni del rango ni de la cuenta: una factura de
 * hace ocho meses tiene que reimprimirse idéntica.
 *
 * @property int $id
 * @property int $sede_id
 * @property TipoDocumentoDeVenta $tipo
 * @property EstadoFactura $estado
 * @property string $numero
 * @property int $correlativo
 * @property int $rango_cai_id
 * @property string $cai
 * @property CarbonInterface $fecha_limite_emision
 * @property int $cuenta_id
 * @property int|null $encuentro_id
 * @property int|null $persona_id
 * @property int|null $convenio_id
 * @property string $cliente_nombre
 * @property string|null $cliente_rtn
 * @property string|null $cliente_direccion
 * @property CarbonInterface $emitida_en
 * @property CarbonInterface $fecha_operacion
 * @property numeric-string $bruto
 * @property numeric-string $descuento_legal
 * @property numeric-string $descuento_comercial
 * @property numeric-string $exento
 * @property numeric-string $gravado
 * @property numeric-string $isv
 * @property numeric-string $total
 * @property int $lineas
 * @property string|null $nota
 * @property CarbonInterface|null $anulada_en
 * @property int|null $anulada_por
 * @property string|null $motivo_anulacion
 */
class Factura extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<FacturaFactory> */
    use HasFactory;

    protected $table = 'facturas';

    /** @var list<string> */
    protected $fillable = [
        'sede_id', 'tipo', 'estado', 'numero', 'correlativo', 'rango_cai_id',
        'cai', 'fecha_limite_emision', 'cuenta_id', 'encuentro_id', 'persona_id', 'convenio_id',
        'cliente_nombre', 'cliente_rtn', 'cliente_direccion',
        'emitida_en', 'fecha_operacion',
        'bruto', 'descuento_legal', 'descuento_comercial', 'exento', 'gravado', 'isv', 'total',
        'lineas', 'nota', 'anulada_en', 'anulada_por', 'motivo_anulacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                 => TipoDocumentoDeVenta::class,
            'estado'               => EstadoFactura::class,
            'fecha_limite_emision' => 'date',
            'emitida_en'           => 'datetime',
            'fecha_operacion'      => 'date',
            'anulada_en'           => 'datetime',
            'correlativo'          => 'integer',
            'lineas'               => 'integer',
            'anulada_por'          => 'integer',
        ];
    }

    /**
     * @return HasMany<FacturaLinea, $this>
     */
    public function detalle(): HasMany
    {
        return $this->hasMany(FacturaLinea::class)->orderBy('orden');
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * @return BelongsTo<RangoCai, $this>
     */
    public function rangoCai(): BelongsTo
    {
        return $this->belongsTo(RangoCai::class, 'rango_cai_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function importe(): Monto
    {
        return Monto::de($this->total);
    }

    public function estaViva(): bool
    {
        return $this->estado->estaViva();
    }

    /**
     * ¿Se le vendió a «CONSUMIDOR FINAL»?
     *
     * Es lo primero que se mira en una revisión: arriba del umbral, esto
     * no puede ser cierto.
     */
    public function esConsumidorFinal(): bool
    {
        return $this->cliente_rtn === null || trim($this->cliente_rtn) === '';
    }

    public function etiqueta(): string
    {
        return $this->numero;
    }
}
