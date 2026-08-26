<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NormalizadorDeTexto;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un código de diagnóstico.
 *
 * ⚠️ `version` es parte de la identidad, no un dato al costado. Honduras
 * está en preparación para CIE-11 sin fecha: cuando llegue, se CARGAN
 * datos y los diagnósticos viejos siguen apuntando a su versión. Un
 * expediente de 2026 releído con el catálogo de 2031 no prueba lo que
 * decía en 2026 (ADR-0004).
 *
 * @property int $id
 * @property string $version
 * @property string $codigo
 * @property string $descripcion
 * @property string $descripcion_busqueda
 * @property string|null $capitulo
 * @property bool $es_notificable
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Cie10 extends Model
{
    protected $table = 'cie10';

    /** @var list<string> */
    protected $fillable = [
        'version',
        'codigo',
        'descripcion',
        'capitulo',
        'es_notificable',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_notificable' => 'boolean',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    /**
     * @return HasMany<Diagnostico, $this>
     */
    public function diagnosticos(): HasMany
    {
        return $this->hasMany(Diagnostico::class, 'cie10_id');
    }

    /**
     * @param Builder<Cie10> $consulta
     *
     * @return Builder<Cie10>
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
     * Búsqueda tolerante, igual que la del catálogo de ítems.
     *
     * Quien escribe «neumonia» sin tilde tiene que encontrar «Neumonía», y
     * quien teclea «J18» tiene que llegar por código. Si no aparece a la
     * primera, el médico escribe cualquier cosa que sí aparezca — y ahí se
     * arruina la estadística que este catálogo existe para permitir.
     *
     * @return EloquentCollection<int, Cie10>
     */
    public static function buscar(string $termino, int $limite = 20): EloquentCollection
    {
        $clave = NormalizadorDeTexto::clave($termino);

        $consulta = static::query()->vigentesEn(now());

        if ($clave !== '') {
            $consulta
                ->where(function (Builder $sub) use ($clave, $termino): void {
                    /*
                     * `%>` es word_similarity: compara contra las PALABRAS
                     * del texto y no contra el texto entero, que es lo que
                     * hace que «neumo» encuentre «Neumonía no especificada».
                     * El código va por ILIKE y anclado al principio: quien
                     * teclea «J18» quiere los J18, no todo lo que contenga
                     * esos tres caracteres.
                     */
                    $sub->whereRaw('descripcion_busqueda %> ?', [$clave])
                        ->orWhereRaw('descripcion_busqueda % ?', [$clave])
                        ->orWhere('codigo', 'ilike', trim($termino).'%');
                })
                ->orderByRaw('similarity(descripcion_busqueda, ?) desc', [$clave]);
        }

        /** @var EloquentCollection<int, Cie10> $resultado */
        $resultado = $consulta->orderBy('codigo')->limit($limite)->get();

        return $resultado;
    }

    public function etiqueta(): string
    {
        return $this->codigo.' — '.$this->descripcion;
    }
}
