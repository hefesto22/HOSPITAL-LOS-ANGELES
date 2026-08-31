<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\SedeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Sede — establecimiento del hospital.
 *
 * Es la raíz de la jerarquía del §8.1 y el eje del alcance de datos
 * (ADR-0002). Toda tabla transaccional cuelga de acá vía `sede_id`.
 *
 * ⚠️ Esta clase NO usa el trait BelongsToSede: sería recursivo, y además
 * un usuario tiene que poder ver la lista de sedes para elegir una.
 *
 * Los @property de abajo no son decoración: Larastan no deduce el tipo de
 * un atributo desde el método casts(), así que sin ellos ve `string` donde
 * hay un Carbon y marca error al llamar greaterThan(). Declararlos es la
 * forma correcta de enseñarle el modelo — y de paso el IDE autocompleta.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string $razon_social
 * @property string|null $rtn
 * @property string|null $codigo_establecimiento
 * @property string|null $registro_sesal
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $logo_path
 * @property CarbonInterface|null $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Sede extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<SedeFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'razon_social',
        'rtn',
        'codigo_establecimiento',
        'registro_sesal',
        'direccion',
        'telefono',
        'email',
        'logo_path',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * La ruta ABSOLUTA del logo en disco, o nulo si no tiene.
     *
     * ⚠️ Ruta de archivo y no URL. La factura se imprime desde un iframe
     * y el navegador tiene que tener la imagen ANTES de abrir el diálogo
     * de impresión: una URL que todavía se está descargando sale como un
     * hueco blanco en el papel. Con la ruta se incrusta el binario en el
     * HTML y ya está ahí cuando se imprime.
     */
    public function rutaDelLogo(): ?string
    {
        if ($this->logo_path === null || trim($this->logo_path) === '') {
            return null;
        }

        $ruta = Storage::disk('public')->path($this->logo_path);

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * El logo listo para incrustar en el HTML de la factura.
     *
     * Devuelve nulo cuando no hay logo o cuando el archivo desapareció
     * del disco: la factura sale con el nombre en texto, que es como
     * salía antes de que esto existiera. Una factura no se cae por una
     * imagen.
     */
    public function logoIncrustado(): ?string
    {
        $ruta = $this->rutaDelLogo();

        if ($ruta === null) {
            return null;
        }

        $binario = @file_get_contents($ruta);

        if ($binario === false) {
            return null;
        }

        $tipo = str_ends_with(mb_strtolower($ruta), '.svg')
            ? 'image/svg+xml'
            : 'image/webp';

        return 'data:'.$tipo.';base64,'.base64_encode($binario);
    }

    /**
     * Reemplaza al mutator `codigo()` que vivia aca.
     *
     * Eran dos mecanismos para la misma regla; ahora es uno solo y cubre
     * ademas nombre, razon social y direccion. Fuera de la lista quedan
     * email, telefono, rtn y codigo_establecimiento: son numeros o
     * identificadores de un tercero, no texto nuestro.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return [
            'codigo',
            'nombre',
            'razon_social',
            'registro_sesal',
            'direccion',
        ];
    }

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
     * Sedes vigentes en una fecha dada.
     *
     * La fecha es OBLIGATORIA a propósito, igual que en RangoEdad: un
     * default a "hoy" es cómodo hasta que alguien reimprime un documento
     * de hace dos años y la sede que lo emitió ya no aparece.
     *
     * @param Builder<Sede> $consulta
     *
     * @return Builder<Sede>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        return $consulta
            ->whereDate('vigencia_desde', '<=', $fecha)
            ->where(function (Builder $q) use ($fecha): void {
                $q->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $fecha);
            });
    }

    public function estaVigenteEn(CarbonInterface $fecha): bool
    {
        if ($this->vigencia_desde?->greaterThan($fecha) === true) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->greaterThanOrEqualTo($fecha);
    }

    /**
     * @return HasMany<Servicio, $this>
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }

    /**
     * @return HasMany<Almacen, $this>
     */
    public function almacenes(): HasMany
    {
        return $this->hasMany(Almacen::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Etiqueta para selectores: "HLA — Hospital Los Ángeles".
     */
    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
