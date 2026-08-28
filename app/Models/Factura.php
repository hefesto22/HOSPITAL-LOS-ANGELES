<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Domain\Enums\TipoIdentificador;
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
 * @property string|null $cliente_documento
 * @property TipoIdentificador|null $cliente_documento_tipo
 * @property string|null $cliente_direccion
 * @property string|null $cliente_telefono
 * @property string|null $cliente_codigo
 * @property string|null $cliente_orden_exenta
 * @property string|null $cliente_constancia_exonerado
 * @property string|null $cliente_registro_sag
 * @property CarbonInterface $emitida_en
 * @property CarbonInterface $fecha_operacion
 * @property CarbonInterface|null $vence_el
 * @property string|null $terminos
 * @property string|null $facturador
 * @property numeric-string $bruto
 * @property numeric-string $descuento_legal
 * @property numeric-string $descuento_comercial
 * @property numeric-string $exonerado
 * @property numeric-string $exento
 * @property numeric-string $gravado
 * @property numeric-string $gravado_15
 * @property numeric-string $gravado_18
 * @property numeric-string $isv
 * @property numeric-string $isv_15
 * @property numeric-string $isv_18
 * @property numeric-string $total
 * @property int $lineas
 * @property string|null $nota
 * @property string|null $comentarios
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
        'cliente_nombre', 'cliente_documento', 'cliente_documento_tipo', 'cliente_direccion', 'cliente_telefono',
        'cliente_codigo', 'cliente_orden_exenta', 'cliente_constancia_exonerado', 'cliente_registro_sag',
        'emitida_en', 'fecha_operacion', 'vence_el', 'terminos', 'facturador',
        'bruto', 'descuento_legal', 'descuento_comercial',
        'exonerado', 'exento', 'gravado', 'gravado_15', 'gravado_18', 'isv', 'isv_15', 'isv_18', 'total',
        'lineas', 'nota', 'comentarios', 'anulada_en', 'anulada_por', 'motivo_anulacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                   => TipoDocumentoDeVenta::class,
            'cliente_documento_tipo' => TipoIdentificador::class,
            'estado'                 => EstadoFactura::class,
            'fecha_limite_emision'   => 'date',
            'emitida_en'             => 'datetime',
            'fecha_operacion'        => 'date',
            'vence_el'               => 'date',
            'anulada_en'             => 'datetime',
            'correlativo'            => 'integer',
            'lineas'                 => 'integer',
            'anulada_por'            => 'integer',
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
        return $this->cliente_documento === null || trim($this->cliente_documento) === '';
    }

    /**
     * «RTN», «Identidad» o «Pasaporte» — lo que va rotulado en el papel.
     *
     * No todo cliente tiene RTN, y arriba del umbral igual hay que
     * identificarlo: imprimir «RTN» sobre un número de identidad sería
     * una mentira que dura hasta la primera revisión.
     */
    public function rotuloDelDocumento(): string
    {
        return match ($this->cliente_documento_tipo) {
            TipoIdentificador::Rtn       => 'RTN',
            TipoIdentificador::Dni       => 'Identidad',
            TipoIdentificador::Pasaporte => 'Pasaporte',
            default                      => 'RTN',
        };
    }

    public function etiqueta(): string
    {
        return $this->numero;
    }
}
