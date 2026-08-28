<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Decimal;
use Database\Factories\AbonoMedioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de un abono: cuánto entró por cuál medio.
 *
 * Dos filas —L 3,000 en tarjeta y L 2,000 en efectivo— son el «pago
 * mixto». El arqueo del turno suma SOLO las de efectivo.
 *
 * ⚠️ De la tarjeta no se guarda NADA más que el monto: el voucher se
 * queda en el papel del POS. Del depósito, solo el banco.
 *
 * @property int $id
 * @property int $abono_id
 * @property FormaDePago $forma
 * @property numeric-string $monto
 * @property string|null $banco
 */
class AbonoMedio extends Model
{
    /** @use HasFactory<AbonoMedioFactory> */
    use HasFactory;

    protected $table = 'abono_medios';

    /** @var list<string> */
    protected $fillable = [
        'abono_id',
        'forma',
        'monto',
        'banco',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forma' => FormaDePago::class,
        ];
    }

    /**
     * @return BelongsTo<Abono, $this>
     */
    public function abono(): BelongsTo
    {
        return $this->belongsTo(Abono::class);
    }

    public function monto(): Decimal
    {
        return Decimal::de($this->monto);
    }

    /**
     * «Efectivo», «Tarjeta», «Transferencia o depósito · Ficohsa».
     */
    public function descripcion(): string
    {
        $texto = $this->forma->etiqueta();

        if ($this->banco !== null && trim($this->banco) !== '') {
            $texto .= ' · '.$this->banco;
        }

        return $texto;
    }
}
