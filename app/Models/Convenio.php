<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ConvenioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quién paga la cuenta.
 *
 * No lleva `sede_id`: un convenio se firma con el hospital, no con una
 * sede. El precio sí puede variar por sede, y eso vive en el tarifario.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property TipoConvenio $tipo
 * @property BaseDelDescuentoLegal $base_descuento_legal
 * @property string $fundamento_descuento
 * @property string|null $rtn
 * @property string|null $contacto
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $requiere_autorizacion
 * @property int|null $dias_credito
 * @property string|null $notas
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Convenio extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ConvenioFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * El pagador que siempre existe.
     *
     * Es constante y no configuración porque el sistema depende de que
     * exista: sin él, una cuenta sin seguro no tendría a quién cobrarle
     * y el precio de lista no tendría dueño.
     */
    public const CODIGO_CONTADO = 'CONTADO';

    protected $table = 'convenios';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'base_descuento_legal',
        'fundamento_descuento',
        'rtn',
        'contacto',
        'telefono',
        'correo',
        'requiere_autorizacion',
        'dias_credito',
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
            'tipo'                  => TipoConvenio::class,
            'base_descuento_legal'  => BaseDelDescuentoLegal::class,
            'requiere_autorizacion' => 'boolean',
            'dias_credito'          => 'integer',
            'vigencia_desde'        => 'date',
            'vigencia_hasta'        => 'date',
        ];
    }

    /**
     * El código y el nombre van canónicos. El contacto NO: es el nombre
     * de una persona escrito por quien la conoce, y en mayúsculas se lee
     * como un grito.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['codigo', 'nombre'];
    }

    public function esAlContado(): bool
    {
        return $this->tipo === TipoConvenio::Contado;
    }

    /**
     * ¿A este pagador se le aplica el descuento del Art. 30?
     *
     * La respuesta no la decide el sistema: la leyó y la escribió alguien
     * al dar de alta el convenio. Ver `BaseDelDescuentoLegal`.
     */
    public function aplicaDescuentoLegal(): bool
    {
        return $this->base_descuento_legal->aplica();
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
     * ⚠️ La fecha es obligatoria y no tiene default. Un convenio resuelto
     * contra "hoy" reimprime la factura de un ingreso de marzo con el
     * pagador que se dio de alta en septiembre.
     *
     * @param Builder<Convenio> $consulta
     *
     * @return Builder<Convenio>
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
     * @param Builder<Convenio> $consulta
     *
     * @return Builder<Convenio>
     */
    public function scopeAlContado(Builder $consulta): Builder
    {
        return $consulta->where('codigo', self::CODIGO_CONTADO);
    }
}
