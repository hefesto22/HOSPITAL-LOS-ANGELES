<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Support\NormalizadorDeTexto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ítem del catálogo único (§8.4, ADR-0003).
 *
 * Este modelo NO sabe cuánto cuesta nada. El precio es
 * `precio(item, convenio, fecha_servicio, sede)` resuelto por vigencia
 * (§8.5) y vive en su propia tabla; el costo promedio vive en el kardex,
 * por sede y almacén. Un getter `precio` acá sería la puerta por la que
 * entra el precio-columna que el §9.H2 prohíbe.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $nombre_busqueda
 * @property TipoItem $tipo
 * @property RegimenIsv $regimen_isv
 * @property PoliticaCargo $politica_cargo
 * @property CategoriaLegalDeDescuento $categoria_legal_descuento
 * @property int|null $unidad_dispensacion_id
 * @property bool $fraccionable
 * @property int|null $unidad_fraccion_id
 * @property string|null $fracciones_por_unidad
 * @property int|null $horas_caducidad_post_apertura
 * @property bool $requiere_lote
 * @property bool $requiere_receta
 * @property bool $es_controlado
 * @property string|null $principio_activo
 * @property string|null $registro_arsa
 * @property string|null $presentacion_comercial
 * @property string|null $codigo_cie10
 * @property string|null $codigo_loinc
 * @property string|null $codigo_atc
 * @property string|null $version_codificacion
 * @property string|null $cuenta_contable
 * @property string|null $centro_de_costo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Item extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'items';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'tipo',
        'regimen_isv',
        'politica_cargo',
        'categoria_legal_descuento',
        'unidad_dispensacion_id',
        'fraccionable',
        'unidad_fraccion_id',
        'fracciones_por_unidad',
        'horas_caducidad_post_apertura',
        'requiere_lote',
        'requiere_receta',
        'es_controlado',
        'principio_activo',
        'registro_arsa',
        'presentacion_comercial',
        'codigo_cie10',
        'codigo_loinc',
        'codigo_atc',
        'version_codificacion',
        'cuenta_contable',
        'centro_de_costo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * Los códigos estándar quedan FUERA a propósito.
     *
     * LOINC distingue mayúsculas en algunos campos y CIE-10 usa letra
     * mayúscula con dígitos ("J18.9"), así que pasarlos por el
     * canonizador es inofensivo hoy y una bomba el día que se cargue un
     * catálogo real. No se tocan: se guardan tal como los publica quien
     * los mantiene.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return [
            'codigo',
            'nombre',
            'principio_activo',
            'presentacion_comercial',
        ];
    }

    /**
     * Coherencia derivada del tipo, aplicada en TODA escritura.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ACÁ Y NO EN EL FORMULARIO
     * ─────────────────────────────────────────────────────────────────
     *
     * La base tiene CHECKs que rechazan un honorario con lote o un
     * fraccionable sin unidad de fracción. Son el backstop y tienen que
     * quedarse. Pero si lo único que los evita es el formulario, hay dos
     * caminos que igual llegan a la base rechazando:
     *
     *   · en la pantalla, al cambiar el tipo de un medicamento a
     *     servicio, la pestaña de farmacia se oculta y sus campos no se
     *     envían — así que el valor VIEJO sigue en el modelo;
     *   · un import de catálogo del sistema anterior no pasa por ninguna
     *     pestaña.
     *
     * Los dos terminan en un error de SQL crudo en la cara del usuario.
     * Esto no es "arreglar el dato en silencio": las tres banderas son
     * DERIVADAS del tipo —un honorario no tiene lote, no es controlado y
     * no se fracciona— así que ponerlas en falso no pierde información
     * que alguien haya querido guardar.
     */
    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            if (! $item->tipo->mueveInventario()) {
                $item->requiere_lote = false;
                $item->es_controlado = false;
                $item->fraccionable = false;
            }

            /*
             * Un controlado exige receta siempre. Es requisito ante ARSA,
             * no preferencia: la base también lo obliga.
             */
            if ($item->es_controlado) {
                $item->requiere_receta = true;
            }

            if (! $item->fraccionable) {
                $item->unidad_fraccion_id = null;
                $item->fracciones_por_unidad = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                      => TipoItem::class,
            'regimen_isv'               => RegimenIsv::class,
            'politica_cargo'            => PoliticaCargo::class,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::class,
            'fraccionable'              => 'boolean',
            'requiere_lote'             => 'boolean',
            'requiere_receta'           => 'boolean',
            'es_controlado'             => 'boolean',
            'vigencia_desde'            => 'date',
            'vigencia_hasta'            => 'date',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidadDispensacion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_dispensacion_id');
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidadFraccion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_fraccion_id');
    }

    /**
     * @return HasMany<ItemPresentacion, $this>
     */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(ItemPresentacion::class);
    }

    /**
     * Todas las filas de tarifario del ítem: el precio de lista y los
     * negociados con cada pagador, vigentes y vencidos.
     *
     * Los vencidos también, y a propósito: son la explicación de las
     * facturas de ayer. Filtrar por fecha es trabajo de quien pregunta
     * por una fecha, no de la relación.
     *
     * @return HasMany<Tarifario, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(Tarifario::class);
    }

    /**
     * Los lotes de este ítem: mismo producto, distintos vencimientos.
     *
     * Veinte cajas que vencen en dos fechas son un ítem con dos lotes, no
     * dos ítems. Ver el encabezado de la migración de `lotes`.
     *
     * @return HasMany<Lote, $this>
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * Cuánto hay, por lote y por almacén.
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * La presentación que propone el formulario de compra.
     */
    public function presentacionPredeterminada(): ?ItemPresentacion
    {
        return $this->presentaciones()
            ->where('es_predeterminada', true)
            ->first();
    }

    // ── Vigencia ──────────────────────────────────────────────────────

    /**
     * ¿Se ofrecía en esta fecha?
     *
     * La pregunta se hace SIEMPRE contra la fecha del servicio, nunca
     * contra "hoy": un cargo de marzo se explica con el catálogo de
     * marzo. Por eso el parámetro es obligatorio.
     */
    public function vigenteEn(CarbonInterface $fecha): bool
    {
        if ($this->vigencia_desde->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->endOfDay()->greaterThanOrEqualTo($fecha);
    }

    /**
     * Ítems vigentes en una fecha dada.
     *
     * @param Builder<Item> $consulta
     *
     * @return Builder<Item>
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

    // ── Búsqueda ──────────────────────────────────────────────────────

    /**
     * Filtro tolerante por código, nombre o principio activo.
     *
     * `%>` es word_similarity: compara el término contra las PALABRAS del
     * texto, no contra el texto entero. Es lo que hace que "amoxi"
     * encuentre "AMOXICILINA 500 MG CÁPSULA" — con `%` a secas la
     * similitud global de una palabra corta contra una descripción larga
     * queda por debajo del umbral y no devuelve nada.
     *
     * El `OR ILIKE` sobre el código es a propósito: quien teclea
     * "MED-0012" espera coincidencia exacta, y los trigramas de un código
     * con guiones son ruido.
     *
     * @param Builder<Item> $consulta
     *
     * @return Builder<Item>
     */
    public function scopeBuscar(Builder $consulta, string $termino): Builder
    {
        $clave = NormalizadorDeTexto::clave($termino);

        if ($clave === '') {
            return $consulta;
        }

        return $consulta->where(function (Builder $sub) use ($clave): void {
            $sub->whereRaw('nombre_busqueda %> ?', [$clave])
                ->orWhereRaw('nombre_busqueda % ?', [$clave])
                ->orWhere('codigo', 'ilike', '%'.$clave.'%');
        });
    }

    /**
     * Búsqueda ordenada por parecido, para el selector del mostrador.
     *
     * @return EloquentCollection<int, static>
     */
    public static function buscar(string $termino, int $limite = 20): EloquentCollection
    {
        $clave = NormalizadorDeTexto::clave($termino);

        $consulta = static::query()->buscar($termino);

        if ($clave !== '') {
            $consulta->orderByRaw('similarity(nombre_busqueda, ?) desc', [$clave]);
        }

        /** @var EloquentCollection<int, static> $resultado */
        $resultado = $consulta->limit($limite)->get();

        return $resultado;
    }

    // ── Reglas de negocio ─────────────────────────────────────────────

    /**
     * ¿Descuenta existencia del kardex?
     *
     * Vive en el enum porque es propiedad del TIPO, no de este ítem: si
     * mañana los insumos dejaran de moverse, cambiaría para todos.
     */
    public function mueveInventario(): bool
    {
        return $this->tipo->mueveInventario();
    }

    /**
     * Horas de vida útil después de abierto el envase.
     *
     * Nulo en el ítem = usar el default de la instalación. Muchos
     * multidosis vencen a las 24-48 h de abiertos sin importar la fecha
     * impresa en el frasco.
     */
    public function horasDeVidaAbierto(): int
    {
        if (is_int($this->horas_caducidad_post_apertura)) {
            return $this->horas_caducidad_post_apertura;
        }

        return (int) config('sihla.inventario.horas_caducidad_post_apertura_por_defecto', 24);
    }

    /**
     * ¿El descuento de adulto mayor de este ítem exige receta?
     *
     * Art. 34 de la Ley del Adulto Mayor: para medicamentos, sí — receta
     * original firmada y sellada. El ítem también puede exigir receta por
     * otra razón (controlado), y son cosas distintas: una es requisito
     * para DISPENSAR, la otra para DESCONTAR.
     */
    public function elDescuentoExigeReceta(): bool
    {
        return $this->categoria_legal_descuento->exigeReceta();
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
