<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto de los datos demográficos de una persona en un momento dado.
 *
 * ⚠️ APPEND-ONLY (ADR-0004). Esta tabla no se actualiza ni se borra, y no
 * es una convención: hay un trigger en PostgreSQL que rechaza UPDATE y
 * DELETE. Un `$version->save()` sobre una fila ya guardada lanza
 * QueryException, y eso es lo correcto.
 *
 * Consecuencia práctica: este modelo NO lleva `updated_at` ni SoftDeletes.
 * Una columna que nunca puede cambiar es una invitación a que alguien
 * intente cambiarla.
 *
 * @property int $id
 * @property int $persona_id
 * @property int $version
 * @property array<string, mixed> $datos
 * @property array<string, mixed>|null $cambios
 * @property string|null $motivo
 * @property int|null $registrado_por
 * @property CarbonInterface $registrado_en
 */
class PersonaVersion extends Model
{
    protected $table = 'persona_versiones';

    /**
     * `registrado_en` se escribe explícitamente al insertar. Los
     * timestamps automáticos de Eloquent incluyen `updated_at`, que en una
     * tabla append-only no tiene sentido.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'persona_id',
        'version',
        'datos',
        'cambios',
        'motivo',
        'registrado_por',
        'registrado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos'         => 'array',
            'cambios'       => 'array',
            'version'       => 'integer',
            'registrado_en' => 'datetime',
        ];
    }

    /**
     * Los campos demográficos que se fotografían.
     *
     * Está acá y no disperso en el servicio porque el día que se agregue
     * una columna a `personas` hay que decidir a conciencia si entra en el
     * historial. Lo que no esté en esta lista, no se puede reconstruir.
     *
     * @return array<int, string>
     */
    public static function camposVersionados(): array
    {
        return [
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
            'nacionalidad',
            'departamento',
            'municipio',
            'direccion',
            'telefono',
            'telefono_alterno',
            'email',
            'merged_into',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
