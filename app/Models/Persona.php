<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\Genero;
use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\SexoBiologico;
use App\Support\NormalizadorDeTexto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Persona — la identidad, única para toda la organización (§8.2).
 *
 * ⚠️ NO usa el trait BelongsToSede, y es deliberado: el expediente es por
 * sede, la persona no. Ver el encabezado de la migración.
 *
 * `nombre_busqueda` la calcula PostgreSQL (columna generada). No está en
 * $fillable y no se debe asignar nunca: la base rechaza la escritura. Como
 * el INSERT no la devuelve, después de `create()` hay que hacer
 * `->refresh()` para leerla.
 *
 * @property int $id
 * @property string $uuid
 * @property string $primer_nombre
 * @property string|null $segundo_nombre
 * @property string|null $primer_apellido
 * @property string|null $segundo_apellido
 * @property string|null $apellido_casada
 * @property string|null $nombre_busqueda
 * @property SexoBiologico $sexo_biologico
 * @property Genero|null $genero
 * @property CarbonInterface|null $fecha_nacimiento
 * @property PrecisionFechaNacimiento $precision_fecha_nacimiento
 * @property CarbonInterface|null $fecha_defuncion
 * @property bool $es_nn
 * @property string|null $nota_identificacion
 * @property string|null $nacionalidad
 * @property string|null $departamento
 * @property string|null $municipio
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $telefono_alterno
 * @property string|null $email
 * @property int|null $merged_into
 * @property CarbonInterface|null $merged_at
 * @property int|null $merged_by
 * @property string|null $merged_motivo
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Persona extends Model
{
    use HasAuditFields;

    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'apellido_casada',
        'sexo_biologico',
        'genero',
        'fecha_nacimiento',
        'precision_fecha_nacimiento',
        'fecha_defuncion',
        'es_nn',
        'nota_identificacion',
        'nacionalidad',
        'departamento',
        'municipio',
        'direccion',
        'telefono',
        'telefono_alterno',
        'email',
    ];

    /**
     * El UUID va en su propia columna; la llave primaria sigue siendo el
     * `id` entero. Sobrescribir `uniqueIds()` es lo que evita que HasUuids
     * convierta la PK en string.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Las URLs del sistema llevan el UUID, no el id.
     *
     * `/pacientes/3f2a…` en vez de `/pacientes/1487`. Con el id entero
     * cualquiera que vea una URL sabe cuántos pacientes lleva el hospital
     * y puede probar el anterior y el siguiente. En un expediente clínico
     * eso es un problema de privacidad, no una molestia estética.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sexo_biologico'             => SexoBiologico::class,
            'genero'                     => Genero::class,
            'precision_fecha_nacimiento' => PrecisionFechaNacimiento::class,
            'fecha_nacimiento'           => 'date',
            'fecha_defuncion'            => 'date',
            'es_nn'                      => 'boolean',
            'merged_at'                  => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Nombre
    // ─────────────────────────────────────────────────────────────────

    public function nombreCompleto(): string
    {
        return implode(' ', array_filter([
            $this->primer_nombre,
            $this->segundo_nombre,
            $this->primer_apellido,
            $this->segundo_apellido,
        ]));
    }

    /**
     * "PÉREZ LÓPEZ, Juan Carlos" — para listados ordenados por apellido.
     */
    public function nombreParaListado(): string
    {
        $apellidos = implode(' ', array_filter([$this->primer_apellido, $this->segundo_apellido]));
        $nombres = implode(' ', array_filter([$this->primer_nombre, $this->segundo_nombre]));

        return $apellidos === '' ? $nombres : "{$apellidos}, {$nombres}";
    }

    /**
     * La misma clave que calculó PostgreSQL, pero desde PHP.
     *
     * Existe para poder comprobar en una prueba que los dos lados no se
     * separaron. No se usa para escribir: la columna es generada.
     */
    public function claveDeBusquedaCalculada(): string
    {
        return NormalizadorDeTexto::claveDeNombre([
            $this->primer_nombre,
            $this->segundo_nombre,
            $this->primer_apellido,
            $this->segundo_apellido,
            $this->apellido_casada,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Edad
    // ─────────────────────────────────────────────────────────────────

    /**
     * Edad cumplida EN UNA FECHA DADA.
     *
     * La fecha es obligatoria, igual que en RangoEdad y en Sede: un
     * default a "hoy" es la puerta por la que entra el bug de recalcular
     * la edad al reimprimir una factura vieja y cambiarle el descuento.
     */
    public function edadEn(CarbonInterface $fecha): ?int
    {
        if ($this->fecha_nacimiento === null) {
            return null;
        }

        return (int) $this->fecha_nacimiento->diffInYears($fecha);
    }

    /**
     * Rango de edad legal en la fecha del servicio.
     *
     * Devuelve null SOLO cuando no hay fecha de nacimiento (el NN que
     * todavía no se identifica). Con una fecha estimada SÍ devuelve el
     * rango: negarle a un adulto mayor un descuento que la ley obliga es
     * una infracción sancionable, mientras que concederlo sobre una
     * estimación es un costo menor y reversible. Quien decide si actúa
     * sobre una estimación es el módulo que llama — para eso está
     * `fechaNacimientoEsExacta()`.
     */
    public function rangoDeEdadEn(CarbonInterface $fechaServicio): ?RangoEdad
    {
        if ($this->fecha_nacimiento === null) {
            return null;
        }

        return RangoEdad::paraPaciente($this->fecha_nacimiento, $fechaServicio);
    }

    public function fechaNacimientoEsExacta(): bool
    {
        return $this->precision_fecha_nacimiento === PrecisionFechaNacimiento::Exacta;
    }

    public function estaFallecida(): bool
    {
        return $this->fecha_defuncion !== null;
    }

    // ─────────────────────────────────────────────────────────────────
    // Fusión de duplicados (§9.D4)
    // ─────────────────────────────────────────────────────────────────

    public function estaFusionada(): bool
    {
        return $this->merged_into !== null;
    }

    /**
     * La persona SOBREVIVIENTE al final de la cadena de fusiones.
     *
     * A se fusiona en B y después B se fusiona en C: quien llegue por A
     * tiene que terminar en C. El recorrido lleva tope de saltos y
     * registro de visitados porque un ciclo acá cuelga el request — y una
     * base de datos vieja, migrada o corregida a mano puede tenerlo aunque
     * los CHECK de hoy lo impidan a futuro.
     */
    public function raiz(int $saltosMaximos = 10): self
    {
        $actual = $this;
        $vistos = [$this->getKey() => true];

        for ($salto = 0; $salto < $saltosMaximos; $salto++) {
            if ($actual->merged_into === null) {
                return $actual;
            }

            $siguiente = static::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->whereKey($actual->merged_into)
                ->first();

            if (! $siguiente instanceof self || isset($vistos[$siguiente->getKey()])) {
                return $actual;
            }

            $vistos[$siguiente->getKey()] = true;
            $actual = $siguiente;
        }

        return $actual;
    }

    // ─────────────────────────────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────────────────────────────

    /**
     * Solo las personas que no fueron fusionadas en otra.
     *
     * @param Builder<Persona> $consulta
     *
     * @return Builder<Persona>
     */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->whereNull('merged_into');
    }

    /**
     * Búsqueda tolerante a acentos y a errores de digitación.
     *
     * Usa los dos operadores de pg_trgm porque resuelven casos distintos:
     *
     *   `%>`  compara el término contra la MEJOR PALABRA del nombre. Es lo
     *         que hace que buscar "peña" encuentre a "José Antonio Peña
     *         Cruz". Con `%` solo, un término corto contra un nombre largo
     *         da similitud baja y no aparece — el error clásico de quien
     *         monta trigramas por primera vez.
     *
     *   `%`   compara el término COMPLETO contra el nombre completo. Es lo
     *         que absorbe los errores de digitación cuando admisión
     *         escribe el nombre entero: "jose antonyo pena cruz".
     *
     * Los dos están soportados por el índice GIN parcial, así que el OR se
     * resuelve con un bitmap sin recorrer la tabla.
     *
     * El `whereNull('merged_into')` no es solo un filtro: es lo que hace
     * que la consulta coincida con el PREDICADO del índice parcial. Sin
     * él, PostgreSQL no puede usar el índice.
     *
     * ⚠️ ESTE SCOPE NO ORDENA, Y ES DELIBERADO.
     *
     * El orden por relevancia es `ORDER BY similarity(nombre_busqueda, ?)`,
     * que menciona una columna. Si eso viviera dentro del scope, un
     * `->count()` sobre el scope produciría
     * `SELECT count(*) ... ORDER BY similarity(nombre_busqueda, ?)`, y
     * PostgreSQL lo rechaza: con un agregado y sin GROUP BY, lo que se
     * ordena tiene que ser el agregado. Un scope tiene que sobrevivir a
     * que lo cuenten. El orden va en `buscar()`.
     *
     * @param Builder<Persona> $consulta
     *
     * @return Builder<Persona>
     */
    public function scopeBuscarPorNombre(Builder $consulta, string $termino): Builder
    {
        $clave = NormalizadorDeTexto::clave($termino);

        if ($clave === '') {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta
            ->whereNull('merged_into')
            ->where(function (Builder $interna) use ($clave): void {
                $interna->whereRaw('nombre_busqueda %> ?', [$clave])
                    ->orWhereRaw('nombre_busqueda % ?', [$clave]);
            });
    }

    /**
     * La búsqueda completa: filtra, ordena por parecido y acota.
     *
     * Es la que usa admisión. El límite existe porque nadie revisa más de
     * veinte candidatos: si el que busca no aparece ahí, el término está
     * mal y hay que afinarlo, no paginar cinco mil nombres parecidos.
     *
     * Devuelve `Collection<int, static>` y no `Collection<int, Persona>`
     * porque el parametro TModel de la coleccion de Eloquent es INVARIANTE:
     * `static::query()->get()` produce `Collection<int, static>`, y para
     * PHPStan esa no es una `Collection<int, Persona>` aunque static resuelva
     * a Persona. Declararlo con `static` es la forma honesta; forzar el tipo
     * con un @var seria mentirle al analizador para tapar el sintoma.
     *
     * @return EloquentCollection<int, static>
     */
    public static function buscar(string $termino, int $limite = 20): EloquentCollection
    {
        $clave = NormalizadorDeTexto::clave($termino);

        $consulta = static::query()->buscarPorNombre($termino);

        if ($clave !== '') {
            $consulta->orderByRaw('similarity(nombre_busqueda, ?) desc', [$clave]);
        }

        return $consulta->limit($limite)->get();
    }

    // ─────────────────────────────────────────────────────────────────
    // Relaciones
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return HasMany<PersonaIdentificador, $this>
     */
    public function identificadores(): HasMany
    {
        return $this->hasMany(PersonaIdentificador::class);
    }

    /**
     * @return HasMany<PersonaVersion, $this>
     */
    public function versiones(): HasMany
    {
        return $this->hasMany(PersonaVersion::class);
    }

    /**
     * La persona sobreviviente en la que ESTA fue fusionada.
     *
     * @return BelongsTo<Persona, $this>
     */
    public function fusionadaEn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into');
    }

    /**
     * Los duplicados que se fusionaron EN ESTA.
     *
     * @return HasMany<Persona, $this>
     */
    public function duplicados(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fusionadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
