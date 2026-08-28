<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoDocumentoDeVenta;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\RangoCaiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * El rango autorizado por el SAR, con su CAI y su fecha límite.
 *
 * 🔴 QUEDARSE SIN CAI ES UN HOSPITAL QUE NO PUEDE DAR DE ALTA. Por eso
 * las dos alertas —porcentaje consumido y días restantes— son
 * independientes: un rango de 5,000 facturas puede vencer con 4,000 sin
 * usar, y uno de 500 se agota en marzo aunque venza en diciembre.
 *
 * @property int $id
 * @property int $sede_id
 * @property TipoDocumentoDeVenta $tipo
 * @property string $cai
 * @property string $establecimiento
 * @property string $punto_emision
 * @property string $tipo_codigo
 * @property int $desde
 * @property int $hasta
 * @property int $siguiente
 * @property CarbonInterface $fecha_limite_emision
 * @property bool $activo
 * @property string|null $resolucion
 * @property string|null $nota
 */
class RangoCai extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<RangoCaiFactory> */
    use HasFactory;

    protected $table = 'rangos_cai';

    /** @var list<string> */
    protected $fillable = [
        'sede_id', 'tipo', 'cai', 'establecimiento', 'punto_emision', 'tipo_codigo',
        'desde', 'hasta', 'siguiente', 'fecha_limite_emision', 'activo', 'resolucion', 'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                 => TipoDocumentoDeVenta::class,
            'fecha_limite_emision' => 'date',
            'activo'               => 'boolean',
            'desde'                => 'integer',
            'hasta'                => 'integer',
            'siguiente'            => 'integer',
        ];
    }

    /**
     * El número que le tocaría a la próxima factura, ya formateado.
     *
     * ⚠️ Solo para mostrar. El número de verdad lo arma el emisor DENTRO
     * de la transacción, con la fila bloqueada: entre que esta pantalla
     * lo dibuja y alguien aprieta Emitir, otra caja pudo tomarlo.
     */
    public function proximoNumero(): string
    {
        return $this->numeroDe($this->siguiente);
    }

    public function numeroDe(int $correlativo): string
    {
        return implode('-', [
            $this->establecimiento,
            $this->punto_emision,
            $this->tipo_codigo,
            str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT),
        ]);
    }

    public function seAgoto(): bool
    {
        return $this->siguiente > $this->hasta;
    }

    public function vencioAl(CarbonInterface $fecha): bool
    {
        return $this->fecha_limite_emision->lt($fecha->startOfDay());
    }

    /**
     * Cuántos números quedan sin usar.
     */
    public function disponibles(): int
    {
        return max(0, $this->hasta - $this->siguiente + 1);
    }

    /**
     * Qué fracción del rango ya se consumió, de 0 a 1.
     */
    public function fraccionConsumida(): float
    {
        $total = $this->hasta - $this->desde + 1;

        if ($total <= 0) {
            return 1.0;
        }

        return min(1.0, max(0.0, ($this->siguiente - $this->desde) / $total));
    }

    public function etiqueta(): string
    {
        return $this->tipo->etiqueta().' · '.$this->punto_emision.' · '.$this->cai;
    }
}
