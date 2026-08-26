<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\Decimal;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\DescuentoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Un descuento con nombre propio, vigente entre dos fechas.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LA IDENTIDAD ES EL NOMBRE, NO EL `id`
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Tercera edad» al 25 % hasta junio y al 30 % desde julio son dos filas
 * del mismo descuento. La restricción de exclusión de la tabla garantiza
 * que solo una de ellas esté vigente en cualquier día dado, así que el
 * nombre alcanza para identificarlo y la fecha para elegir la fila.
 *
 * Por eso `asignadosA()` busca por NOMBRE y no por `id`: el pivote
 * guarda el id que estaba vigente el día que alguien marcó la casilla,
 * y si el resolutor leyera ese id, cambiar el porcentaje dejaría a todos
 * los ítems marcados con el viejo — con la casilla marcada en pantalla y
 * sin un solo error. Se descubriría cuando reclamara un paciente.
 *
 * Consecuencia: **el nombre no se edita**. El módulo es append-only.
 *
 * @property int $id
 * @property string $nombre
 * @property string $porcentaje fracción: 0.2500 es 25 %
 * @property AplicacionDeDescuento $aplica_a
 * @property bool $exige_receta
 * @property string|null $nota
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Descuento extends Model
{
    use HasAuditFields;

    /** @use HasFactory<DescuentoFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'descuentos';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'porcentaje',
        'aplica_a',
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
            'aplica_a'       => AplicacionDeDescuento::class,
            'exige_receta'   => 'boolean',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'descuento_item')->withTimestamps();
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
     * «25 %» para pantalla, sin decimales cuando no hacen falta.
     */
    public function comoPorcentaje(): string
    {
        return rtrim(rtrim($this->fraccion()->por('100')->redondeado(2), '0'), '.').' %';
    }

    /**
     * «Tercera edad — 25 %» para el selector del ítem.
     */
    public function etiquetaCompleta(): string
    {
        return $this->nombre.' — '.$this->comoPorcentaje();
    }

    /**
     * Las filas vigentes en una fecha dada.
     *
     * ⚠️ La fecha es obligatoria y no tiene default. Un descuento que se
     * resuelve contra «hoy» reimprime la factura de marzo con el
     * porcentaje de septiembre — y esa factura ya se cobró.
     *
     * @param Builder<Descuento> $consulta
     *
     * @return Builder<Descuento>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->toDateString();

        return $consulta
            ->whereDate('descuentos.vigencia_desde', '<=', $dia)
            ->where(function (Builder $sub) use ($dia): void {
                $sub->whereNull('descuentos.vigencia_hasta')
                    ->orWhereDate('descuentos.vigencia_hasta', '>=', $dia);
            });
    }

    /**
     * Los descuentos marcados en un ítem — resueltos POR NOMBRE.
     *
     * 🔴 La subconsulta lleva un JOIN, así que todas las columnas van
     * calificadas. Sin calificar, `nombre` e `id` son ambiguos y
     * Postgres decide por su cuenta cuál es cuál.
     *
     * @param Builder<Descuento> $consulta
     *
     * @return Builder<Descuento>
     */
    public function scopeAsignadosA(Builder $consulta, Item $item): Builder
    {
        return $consulta->whereIn(
            'descuentos.nombre',
            static function (QueryBuilder $sub) use ($item): void {
                $sub->select('marcados.nombre')
                    ->from('descuentos as marcados')
                    ->join('descuento_item', 'descuento_item.descuento_id', '=', 'marcados.id')
                    ->where('descuento_item.item_id', '=', $item->getKey());
            },
        );
    }

    /**
     * Los que dispara un rango de edad, subiendo la escalera: a un
     * paciente de la cuarta edad sin fila propia le toca la de la
     * tercera, nunca cero.
     *
     * @param Builder<Descuento> $consulta
     *
     * @return Builder<Descuento>
     */
    public function scopeQueAplicanA(Builder $consulta, RangoEdad $rango): Builder
    {
        return $consulta->whereIn('descuentos.aplica_a', array_map(
            static fn (AplicacionDeDescuento $aplicacion): string => $aplicacion->value,
            AplicacionDeDescuento::deLaEscalera($rango),
        ));
    }

    /**
     * Los que se disparan solos, de cualquier edad. Es el peor caso del
     * que sale el precio de lista (§4.5).
     *
     * @param Builder<Descuento> $consulta
     *
     * @return Builder<Descuento>
     */
    public function scopeAutomaticos(Builder $consulta): Builder
    {
        return $consulta->whereIn('descuentos.aplica_a', array_map(
            static fn (AplicacionDeDescuento $aplicacion): string => $aplicacion->value,
            AplicacionDeDescuento::automaticos(),
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

    /**
     * A cuántos ítems se les marcó este descuento — contando por nombre,
     * que es como lo resuelve el motor de cargos. Contar por `id` diría
     * cero justo después de cambiar el porcentaje.
     */
    public function cuantosItemsLoTienen(): int
    {
        return Item::query()
            ->whereHas('descuentos', function (Builder $sub): void {
                $sub->where('descuentos.nombre', '=', $this->nombre);
            })
            ->count();
    }
}
