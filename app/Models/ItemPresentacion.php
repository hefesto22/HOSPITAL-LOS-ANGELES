<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ItemPresentacionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Presentación de compra de un ítem.
 *
 * "CAJA X 100 AMPOLLAS" es una fila de acá: unidad CAJA, contenido 100
 * ampollas. El kardex del ítem sigue llevándose en ampollas.
 *
 * @property int $id
 * @property int $item_id
 * @property int $unidad_id
 * @property string $nombre
 * @property string $unidades_por_presentacion
 * @property string|null $codigo_barras
 * @property bool $es_predeterminada
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class ItemPresentacion extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ItemPresentacionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'item_presentaciones';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'unidad_id',
        'nombre',
        'unidades_por_presentacion',
        'codigo_barras',
        'es_predeterminada',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * El código de barras NO se canoniza. Es una cadena que tiene que
     * coincidir carácter por carácter con lo que lee el escáner; algunos
     * GS1 llevan minúsculas y tocarlas rompe la lectura.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['nombre'];
    }

    /**
     * Una sola presentación habitual por ítem.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ SE DESMARCA LA ANTERIOR EN VEZ DE RECHAZAR
     * ─────────────────────────────────────────────────────────────────
     *
     * La base tiene un índice único parcial que impide dos marcadas a la
     * vez, y tiene que quedarse: con dos, la que gana depende del ORDER
     * BY. Pero sin esto, marcar una segunda termina en un error de SQL
     * crudo en la cara de quien carga el catálogo, cuando lo que quiso
     * decir es evidente — «ahora compramos en caja de 50».
     *
     * Va en el modelo y no en el formulario por lo de siempre: la
     * pantalla no es la única puerta. Un import del catálogo del sistema
     * anterior escribe directo.
     *
     * ⚠️ El desmarcado ocurre ANTES del guardado, así que si el guardado
     * falla después, el ítem queda sin presentación habitual. Es un
     * estado que el sistema tolera —el formulario de compra simplemente
     * no propone ninguna— y que el siguiente guardado corrige. La
     * alternativa era el error de SQL, que es peor.
     */
    protected static function booted(): void
    {
        static::saving(function (ItemPresentacion $presentacion): void {
            if (! $presentacion->es_predeterminada) {
                return;
            }

            /*
             * `update()` masivo a propósito: no dispara eventos de
             * modelo, así que no se llama a sí mismo.
             */
            static::query()
                ->where('item_id', $presentacion->item_id)
                ->where('es_predeterminada', true)
                ->when(
                    $presentacion->exists,
                    fn (Builder $consulta): Builder => $consulta->whereKeyNot($presentacion->getKey()),
                )
                ->update(['es_predeterminada' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_predeterminada' => 'boolean',
            'vigencia_desde'    => 'date',
            'vigencia_hasta'    => 'date',
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
     * @return BelongsTo<Unidad, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    /**
     * Convierte una cantidad comprada en esta presentación a unidades de
     * dispensación, que es la única unidad en la que se mueve el kardex.
     *
     * ⚠️ Devuelve string y opera con bcmath, no con float. §8.6.2 lo
     * prohíbe: 3 cajas × 0.1 ml en punto flotante no da 0.3, y ese error
     * se acumula movimiento tras movimiento hasta que el inventario deja
     * de cuadrar con contabilidad.
     *
     * @param numeric-string $cantidad
     *
     * @return numeric-string
     */
    public function aUnidadesDeDispensacion(string $cantidad): string
    {
        return bcmul($cantidad, $this->contenido(), 4);
    }

    /**
     * Y al revés: cuántas presentaciones representa una cantidad en
     * unidades de dispensación. Sirve para mostrar "1.5 cajas" en el
     * reporte de compras, nunca para escribir en el kardex.
     *
     * @param numeric-string $cantidad
     *
     * @return numeric-string
     */
    public function desdeUnidadesDeDispensacion(string $cantidad): string
    {
        return bcdiv($cantidad, $this->contenido(), 4);
    }

    /**
     * El contenido de la presentación, garantizado numérico.
     *
     * La columna es `NUMERIC(14,4) NOT NULL` con un CHECK de mayor que
     * cero, así que en la práctica siempre lo es. La verificación existe
     * igual por dos razones:
     *
     *  1. `numeric-string` es una promesa que el analizador no puede
     *     comprobar solo sobre un atributo de Eloquent — sin esto, la
     *     alternativa era declararlo por docblock, que es afirmarlo sin
     *     respaldo.
     *  2. Si alguien cambia el tipo de la columna, esto avisa. bcmath con
     *     un operando no numérico no explota: lo trata como cero, y una
     *     conversión que devuelve cero en silencio es un faltante de
     *     inventario que aparece meses después en el conteo físico.
     *
     * @return numeric-string
     */
    private function contenido(): string
    {
        $valor = $this->unidades_por_presentacion;

        if (! is_numeric($valor)) {
            throw new RuntimeException(
                "La presentación {$this->id} tiene un contenido no numérico: «{$valor}»."
            );
        }

        return $valor;
    }

    /**
     * @param Builder<ItemPresentacion> $consulta
     *
     * @return Builder<ItemPresentacion>
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

    public function etiqueta(): string
    {
        return $this->nombre;
    }
}
