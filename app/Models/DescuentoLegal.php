<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\DescuentoLegalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un porcentaje de descuento legal, vigente entre dos fechas.
 *
 * No lleva `sede_id`: la ley es la misma en las dos sedes.
 *
 * @property int $id
 * @property CategoriaLegalDeDescuento $categoria_legal
 * @property RangoEdad $rango_edad
 * @property string $porcentaje fracción: 0.2500 es 25 %
 * @property string $fundamento
 * @property bool $exige_receta
 * @property string|null $nota
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class DescuentoLegal extends Model
{
    use HasAuditFields;

    /** @use HasFactory<DescuentoLegalFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'descuentos_legales';

    /** @var list<string> */
    protected $fillable = [
        'categoria_legal',
        'rango_edad',
        'porcentaje',
        'fundamento',
        'exige_receta',
        'nota',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /*
     * `vigencia` es una columna GENERADA por Postgres a partir de las dos
     * fechas. Eloquent no debe intentar escribirla nunca.
     *
     * @var list<string>
     */
    protected $guarded = ['vigencia'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categoria_legal' => CategoriaLegalDeDescuento::class,
            'rango_edad'      => RangoEdad::class,
            'exige_receta'    => 'boolean',
            'vigencia_desde'  => 'date',
            'vigencia_hasta'  => 'date',
        ];
    }

    /**
     * El porcentaje como `Decimal`, listo para operar sin volver a
     * parsear ni pasar por float.
     */
    public function fraccion(): Decimal
    {
        return Decimal::de($this->porcentaje);
    }

    /**
     * Las filas vigentes en una fecha dada.
     *
     * ⚠️ La fecha es obligatoria y no tiene default. Un descuento que se
     * resuelve contra "hoy" reimprime la factura de 2027 con el
     * porcentaje de 2029 — y esa factura ya se cobró.
     *
     * @param Builder<DescuentoLegal> $consulta
     *
     * @return Builder<DescuentoLegal>
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
     * @param Builder<DescuentoLegal> $consulta
     * @param list<RangoEdad> $rangos
     *
     * @return Builder<DescuentoLegal>
     */
    public function scopeDeLaEscalera(Builder $consulta, CategoriaLegalDeDescuento $categoria, array $rangos): Builder
    {
        return $consulta
            ->where('categoria_legal', $categoria->value)
            ->whereIn('rango_edad', array_map(
                static fn (RangoEdad $rango): string => $rango->value,
                $rangos,
            ));
    }

    public function vigenteEn(CarbonInterface $fecha): bool
    {
        if ($this->vigencia_desde->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->endOfDay()->greaterThanOrEqualTo($fecha);
    }
}
