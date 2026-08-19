<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A quién se le compra.
 *
 * Es una entidad aparte de `Persona` a propósito: el MPI es el índice de
 * PACIENTES, y meter droguerías ahí rompería el detector de duplicados,
 * la búsqueda de admisión y el expediente de una sola vez.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $contacto
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $notas
 * @property bool $activo
 */
class Proveedor extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'proveedores';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'rtn',
        'contacto',
        'telefono',
        'correo',
        'notas',
        'activo',
    ];

    /**
     * El contacto NO se canoniza: es el nombre de una persona, y un
     * nombre en mayúsculas se lee como un grito.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['codigo', 'nombre'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Sus facturas y recibos — el registro fiscal del gasto.
     *
     * @return HasMany<Compra, $this>
     */
    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    /**
     * Lo que trajo al estante.
     *
     * Son dos relaciones y no una a propósito: se le compra papelería
     * que nunca entra al kardex, y llega mercadería que todavía no tiene
     * factura. Unirlas obligaría a inventar el dato que falta.
     *
     * @return HasMany<Recepcion, $this>
     */
    public function recepciones(): HasMany
    {
        return $this->hasMany(Recepcion::class);
    }

    /**
     * Los que todavía se pueden elegir al cargar una compra.
     *
     * Un proveedor con el que se dejó de trabajar se desactiva, no se
     * borra: sus compras siguen apuntando a él y una recepción cuyo
     * proveedor desapareció es un kardex que no se puede explicar.
     *
     * @param Builder<Proveedor> $consulta
     *
     * @return Builder<Proveedor>
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where($consulta->qualifyColumn('activo'), true);
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
