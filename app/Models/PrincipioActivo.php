<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NormalizadorDeTexto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que de verdad cura, separado de la forma en que viene.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EXISTE PARA QUE LA GAVETA TENGA ETIQUETA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se escanea `PA-0001` y salen los cuatro acetaminofenes —tableta,
 * jarabe, supositorio, inyectable— sin que nadie recuerde cómo se
 * escribe. Con el texto libre de antes eso no se podía: «ACETAMINOFEN» y
 * «Acetaminofén» eran dos cosas y ninguna agrupaba a la otra.
 *
 * ⚠️ El código de barras ES el código, igual que en los ítems. No hay
 * columna de barras y no hace falta: `PA-` no choca con ningún prefijo
 * del catálogo, así que el buscador distingue solo si lo escaneado es un
 * producto o un principio activo.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $tambien_llamado
 * @property string $nombre_busqueda
 * @property string|null $codigo_atc
 * @property string|null $notas
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class PrincipioActivo extends Model
{
    use HasAuditFields;
    use SoftDeletes;

    protected $table = 'principios_activos';

    /** El prefijo que lo distingue de cualquier ítem al escanear. */
    public const PREFIJO = 'PA-';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'tambien_llamado',
        'codigo_atc',
        'notas',
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
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_principio_activo')
            ->withPivot('concentracion')
            ->withTimestamps();
    }

    /**
     * @param Builder<PrincipioActivo> $consulta
     *
     * @return Builder<PrincipioActivo>
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
     * Búsqueda tolerante: sin tildes, por nombre, por sinónimo o por
     * código. «paracetamol» tiene que encontrar el acetaminofén, porque
     * el médico prescribe en el nombre que aprendió.
     *
     * @return EloquentCollection<int, PrincipioActivo>
     */
    public static function buscar(string $termino, int $limite = 20): EloquentCollection
    {
        $clave = NormalizadorDeTexto::clave($termino);

        $consulta = static::query()->vigentesEn(now());

        if ($clave !== '') {
            $consulta
                ->where(function (Builder $sub) use ($clave): void {
                    $sub->whereRaw('nombre_busqueda %> ?', [$clave])
                        ->orWhereRaw('nombre_busqueda % ?', [$clave]);
                })
                ->orderByRaw('similarity(nombre_busqueda, ?) desc', [$clave]);
        }

        /** @var EloquentCollection<int, PrincipioActivo> $resultado */
        $resultado = $consulta->orderBy('nombre')->limit($limite)->get();

        return $resultado;
    }

    /**
     * El primer `PA-####` libre.
     *
     * ─────────────────────────────────────────────────────────────────
     * VIVE EN EL MODELO PORQUE YA HAY DOS PUERTAS
     * ─────────────────────────────────────────────────────────────────
     *
     * Estaba en la página del listado, y ahí servía mientras esa fuera
     * la única forma de dar de alta un principio. Ahora también se crea
     * sin salir de la ficha del producto, y una regla que vive en una
     * pantalla no la cumple la otra.
     *
     * ⚠️ Se busca el primero LIBRE en vez de contar filas: con borrado
     * suave y con códigos puestos a mano, «cuántos hay + 1» choca contra
     * el índice único apenas alguien retira uno o teclea el suyo.
     *
     * ⚠️ Y mira las borradas. El código va impreso en la etiqueta de la
     * gaveta: reasignarlo haría que esa etiqueta señale otra molécula.
     */
    public static function siguienteCodigo(): string
    {
        $numero = 1;

        while (static::withTrashed()
            ->where('codigo', sprintf(self::PREFIJO.'%04d', $numero))
            ->exists()) {
            $numero++;
        }

        return sprintf(self::PREFIJO.'%04d', $numero);
    }

    /**
     * Los productos del catálogo que hoy llevan este principio activo.
     *
     * ─────────────────────────────────────────────────────────────────
     * ES LA RESPUESTA A LA ETIQUETA DE LA GAVETA
     * ─────────────────────────────────────────────────────────────────
     *
     * Se escanea `PA-0001` en el mostrador y esto es lo que sale: los
     * cuatro acetaminofenes, en las cuatro formas. El escaneo NO elige
     * uno —el jarabe y la tableta no son lo mismo y confundirlos es
     * dispensar otra cosa—; ofrece los que hay para que elija quien
     * dispensó.
     *
     * ⚠️ Solo los VIGENTES. Un producto retirado sigue explicando las
     * facturas viejas y sigue apareciendo para el conteo físico, pero no
     * puede volver a cobrarse — y esta lista es para cobrar.
     *
     * ⚠️ La consulta arranca en `Item` y no en la relación de acá.
     * Encadenarle `vigentesEn()` a un `BelongsToMany` funciona en runtime
     * pero el analizador no lo puede verificar, y el §9.B6 prohíbe
     * taparlo engordando el phpstan.neon.
     *
     * @return EloquentCollection<int, Item>
     */
    public function productosVigentes(): EloquentCollection
    {
        $id = (int) $this->getKey();

        /** @var EloquentCollection<int, Item> $productos */
        $productos = Item::query()
            ->vigentesEn(now())
            ->whereHas('principiosActivos', function (Builder $consulta) use ($id): void {
                /** @var Builder<PrincipioActivo> $consulta */
                $consulta->whereKey($id);
            })
            ->orderBy('nombre')
            ->get();

        return $productos;
    }

    /**
     * ¿Lo que se escaneó es un principio activo y no un producto?
     *
     * Basta el prefijo: es lo que hace que el mismo campo de escaneo
     * sirva para las dos cosas sin preguntarle nada a quien escanea.
     */
    public static function pareceUnCodigoSuyo(string $escaneado): bool
    {
        return str_starts_with(mb_strtoupper(trim($escaneado)), self::PREFIJO);
    }

    public function etiqueta(): string
    {
        return $this->nombre.($this->tambien_llamado === null ? '' : ' ('.$this->tambien_llamado.')');
    }
}
