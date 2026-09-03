<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoAbono;
use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Decimal;
use App\Models\Concerns\BelongsToSede;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\TurnoDeCajaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * El turno de caja de una persona: desde que abre la gaveta hasta que la
 * cuenta.
 *
 * 🔴 TODO LO QUE SE CALCULA ACÁ ES DERIVADO. El efectivo esperado sale de
 * sumar los medios de los abonos vivos del turno, nunca de una columna
 * que alguien va incrementando (§9.G1). La única excepción es
 * `efectivo_esperado`, que se CONGELA al cerrar: después del cierre, un
 * abono anulado mañana no puede cambiar el arqueo de anoche.
 *
 * @property int $id
 * @property int $sede_id
 * @property string $numero
 * @property string|null $nombre
 * @property int $usuario_id
 * @property EstadoTurnoDeCaja $estado
 * @property numeric-string $fondo_inicial
 * @property CarbonInterface $abierto_en
 * @property CarbonInterface $fecha_operacion
 * @property CarbonInterface|null $cerrado_en
 * @property int|null $cerrado_por
 * @property numeric-string|null $efectivo_esperado
 * @property numeric-string|null $efectivo_contado
 * @property numeric-string|null $diferencia
 * @property string|null $notas_cierre
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class TurnoDeCaja extends Model
{
    use BelongsToSede;
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<TurnoDeCajaFactory> */
    use HasFactory;

    protected $table = 'turnos_de_caja';

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'numero',
        'nombre',
        'usuario_id',
        'estado',
        'fondo_inicial',
        'abierto_en',
        'fecha_operacion',
        'cerrado_en',
        'cerrado_por',
        'efectivo_esperado',
        'efectivo_contado',
        'diferencia',
        'notas_cierre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'          => EstadoTurnoDeCaja::class,
            'abierto_en'      => 'datetime',
            'fecha_operacion' => 'date',
            'cerrado_en'      => 'datetime',
            'cerrado_por'     => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * El nombre del turno —«TURNO A»— es un rótulo del hospital como
     * cualquier otro y sale impreso en el corte de caja, al lado de
     * datos que ya van en mayúsculas.
     */
    /**
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['nombre'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /**
     * @return HasMany<Abono, $this>
     */
    public function abonos(): HasMany
    {
        return $this->hasMany(Abono::class, 'turno_id');
    }

    // ── Lo que el arqueo necesita saber ───────────────────────────────

    public function estaAbierto(): bool
    {
        return $this->estado->estaAbierto();
    }

    /**
     * Cuánto entró por cada forma de pago en este turno, contando solo
     * los abonos vivos.
     *
     * Una sola consulta agrupada: el cierre muestra las tres líneas y el
     * total sin traerse los recibos uno por uno.
     *
     * @return array<string, Decimal>
     */
    public function porFormaDePago(): array
    {
        /** @var array<array-key, mixed> $sumas */
        $sumas = DB::table('abono_medios')
            ->join('abonos', 'abonos.id', '=', 'abono_medios.abono_id')
            ->where('abonos.turno_id', $this->id)
            ->where('abonos.estado', EstadoAbono::Aplicado->value)
            ->groupBy('abono_medios.forma')
            ->selectRaw('abono_medios.forma AS forma, COALESCE(SUM(abono_medios.monto), 0) AS total')
            ->pluck('total', 'forma')
            ->all();

        $porForma = [];

        foreach (FormaDePago::cases() as $forma) {
            $porForma[$forma->value] = Decimal::cero();
        }

        foreach ($sumas as $forma => $total) {
            /*
             * `is_numeric` y no `is_scalar`: bcmath rechaza un booleano
             * con un error críptico, y el driver puede devolver el
             * numeric de Postgres como string o como float.
             */
            if (array_key_exists((string) $forma, $porForma) && is_numeric($total)) {
                $porForma[(string) $forma] = Decimal::de((string) $total);
            }
        }

        return $porForma;
    }

    /**
     * El efectivo que TENDRÍA que haber en la gaveta ahora mismo: el
     * fondo con el que abrió más lo que recibió en billetes.
     *
     * ⚠️ Solo efectivo. Lo de tarjeta lo liquida el POS y lo de
     * transferencia el banco; contarlos acá daría un sobrante que no
     * existe todas las noches.
     */
    public function efectivoEsperado(): Decimal
    {
        if ($this->efectivo_esperado !== null) {
            return Decimal::de($this->efectivo_esperado);
        }

        return Decimal::de($this->fondo_inicial)
            ->sumar($this->porFormaDePago()[FormaDePago::Efectivo->value] ?? Decimal::cero());
    }

    /**
     * Todo lo recibido en el turno, sin importar la forma. Es el número
     * que la cajera reporta; el arqueo es otro.
     */
    public function totalRecibido(): Decimal
    {
        $total = Decimal::cero();

        foreach ($this->porFormaDePago() as $monto) {
            $total = $total->sumar($monto);
        }

        return $total;
    }

    public function etiqueta(): string
    {
        return $this->nombre === null || trim($this->nombre) === ''
            ? $this->numero
            : $this->numero.' · '.$this->nombre;
    }
}
