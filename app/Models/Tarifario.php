<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\TarifarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un precio, para un ítem, para un pagador, en un rango de fechas.
 *
 * `convenio_id` nulo es el precio de lista y `sede_id` nulo vale para
 * todas las sedes. La restricción de exclusión de la tabla garantiza que
 * en cada combinación gane una sola fila por día.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $item_presentacion_id
 * @property int|null $convenio_id
 * @property int|null $sede_id
 * @property string $precio fracción con cuatro decimales, antes del ISV
 * @property string $motivo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Tarifario extends Model
{
    use HasAuditFields;

    /** @use HasFactory<TarifarioFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'tarifarios';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'item_presentacion_id',
        'convenio_id',
        'sede_id',
        'precio',
        'elegible',
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
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Convenio, $this>
     */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * El precio como `Monto`, redondeado a los dos decimales que se
     * cobran. La columna lleva cuatro porque un unitario de fracción
     * —media ampolla, un mililitro— los necesita.
     */
    public function monto(): Monto
    {
        return Monto::de($this->precio);
    }

    public function esPrecioDeLista(): bool
    {
        return $this->convenio_id === null;
    }

    public function valeParaTodaSede(): bool
    {
        return $this->sede_id === null;
    }

    /**
     * ⚠️ La fecha es obligatoria y no tiene default. Un precio resuelto
     * contra "hoy" reimprime la factura de marzo con la tarifa de
     * septiembre — y esa factura ya se cobró.
     *
     * @param Builder<Tarifario> $consulta
     *
     * @return Builder<Tarifario>
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

    /**
     * El envase al que le corresponde este precio, si es de uno solo.
     *
     * Nulo = el precio del producto entero, el que se usa cuando no hay
     * uno específico para el frasco que se está dispensando.
     *
     * @return BelongsTo<ItemPresentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ItemPresentacion::class, 'item_presentacion_id');
    }

    /**
     * Las filas que podrían aplicar, con la que gana primero.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ EL CONVENIO PESA MÁS QUE LA SEDE
     * ─────────────────────────────────────────────────────────────────
     *
     * Si existe un precio firmado con la aseguradora para todas las
     * sedes, y además un precio de lista propio de esta sede, gana el del
     * convenio: **lo que se firmó con el pagador manda sobre la política
     * interna de la sede.** Cobrar otra cosa es incumplir el contrato.
     *
     * Por eso el orden es primero por convenio y después por sede, y no
     * al revés.
     *
     * @param Builder<Tarifario> $consulta
     *
     * @return Builder<Tarifario>
     */
    public function scopeResolviendoPara(
        Builder $consulta,
        ?int $convenioId,
        ?int $sedeId,
        ?int $presentacionId = null,
    ): Builder {
        return $consulta
            ->where(function (Builder $sub) use ($convenioId): void {
                $sub->whereNull('convenio_id');

                if ($convenioId !== null) {
                    $sub->orWhere('convenio_id', $convenioId);
                }
            })
            /*
             * El envase entra igual que el convenio y la sede: nulo
             * siempre vale —es el precio del producto entero— y el
             * específico gana cuando existe.
             */
            ->where(function (Builder $sub) use ($presentacionId): void {
                $sub->whereNull('item_presentacion_id');

                if ($presentacionId !== null) {
                    $sub->orWhere('item_presentacion_id', $presentacionId);
                }
            })
            ->where(function (Builder $sub) use ($sedeId): void {
                $sub->whereNull('sede_id');

                if ($sedeId !== null) {
                    $sub->orWhere('sede_id', $sedeId);
                }
            })
            /*
             * El orden ES la jerarquía: primero lo que se FIRMÓ con el
             * pagador, después QUÉ envase se está vendiendo, y al final
             * dónde. El contrato manda sobre el producto y el producto
             * manda sobre la política de la sede.
             */
            ->orderByRaw('convenio_id is null')
            ->orderByRaw('item_presentacion_id is null')
            ->orderByRaw('sede_id is null');
    }
}
