<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ConvenioCondicionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo pactado con un convenio, vigente entre dos fechas.
 *
 * `factor_sobre_lista` es la fracción de la lista que el pagador paga:
 * `0.8500` es «lista menos 15 %», `1.1000` es «10 % por encima».
 *
 * @property int $id
 * @property int $convenio_id
 * @property string $factor_sobre_lista
 * @property string $motivo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class ConvenioCondicion extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ConvenioCondicionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'convenio_condiciones';

    /** @var list<string> */
    protected $fillable = [
        'convenio_id',
        'factor_sobre_lista',
        'motivo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Convenio, $this>
     */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function factor(): Decimal
    {
        return Decimal::de($this->factor_sobre_lista);
    }

    /**
     * Lo que este pagador paga por un ítem que en lista vale tanto.
     *
     * ⚠️ El factor cae sobre la lista **ya redondeada a dos decimales**,
     * no sobre el valor exacto de la columna. Es la misma regla del §4.5
     * que sigue el descuento de ley: se cobra sobre el número que el
     * paciente vio, no sobre uno interno con cuatro decimales que nadie
     * puede reproducir a mano.
     */
    public function aplicarA(Monto $lista): Monto
    {
        return Monto::de(
            Decimal::de($lista->valor())->por($this->factor()),
            $lista->moneda,
        );
    }

    /**
     * «Paga 85 % de la lista (−15 %)» para pantalla.
     *
     * Se muestran las dos formas a propósito: la base guarda lo que
     * paga, pero quien negocia piensa en cuánto descuenta.
     */
    public function resumen(): string
    {
        $paga = $this->factor()->comoPorcentaje();
        $uno = Decimal::de('1');
        $diferencia = $uno->restar($this->factor());

        if ($diferencia->esCero()) {
            return 'Paga la lista completa (100 %)';
        }

        return $diferencia->esNegativo()
            ? "Paga {$paga} de la lista (+".$diferencia->por('-1')->comoPorcentaje().')'
            : "Paga {$paga} de la lista (−{$diferencia->comoPorcentaje()})";
    }

    /**
     * ⚠️ La fecha es obligatoria y no tiene default. Un factor resuelto
     * contra "hoy" reimprime la factura de marzo con el porcentaje que se
     * negoció en la renovación de septiembre.
     *
     * @param Builder<ConvenioCondicion> $consulta
     *
     * @return Builder<ConvenioCondicion>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->toDateString();

        return $consulta
            ->whereDate('vigencia_desde', '<=', $dia)
            ->where(function (Builder $sub) use ($dia): void {
                $sub->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $dia);
            });
    }
}
