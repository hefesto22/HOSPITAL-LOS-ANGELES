<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoAbono;
use App\Domain\ValueObjects\Decimal;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\AbonoFactory;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plata que entra a la cuenta antes de que exista la factura.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO SE EDITA, SE ANULA — Y SOLO CON EL TURNO ABIERTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El recibo salió impreso y la familia lo tiene en la mano. Un trigger
 * de la base rechaza cualquier UPDATE que toque el monto, la cuenta, el
 * turno o la fecha de operación.
 *
 * Y anular exige que el turno siga ABIERTO: una vez cerrado, el efectivo
 * ya se contó y se entregó. Sacar plata de un arqueo cerrado es una
 * DEVOLUCIÓN —otro hecho, con su propio movimiento— y va en el bloque 7.
 *
 * @property int $id
 * @property int $sede_id
 * @property string $numero
 * @property int $cuenta_id
 * @property int $turno_id
 * @property EstadoAbono $estado
 * @property numeric-string $total
 * @property CarbonInterface $recibido_en
 * @property CarbonInterface $fecha_operacion
 * @property int $recibido_por
 * @property string|null $entregado_por
 * @property string|null $nota
 * @property CarbonInterface|null $anulado_en
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Abono extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<AbonoFactory> */
    use HasFactory;

    protected $table = 'abonos';

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'numero',
        'cuenta_id',
        'turno_id',
        'estado',
        'total',
        'recibido_en',
        'fecha_operacion',
        'recibido_por',
        'entregado_por',
        'nota',
        'anulado_en',
        'anulado_por',
        'motivo_anulacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'          => EstadoAbono::class,
            'recibido_en'     => 'datetime',
            'fecha_operacion' => 'date',
            'recibido_por'    => 'integer',
            'anulado_en'      => 'datetime',
            'anulado_por'     => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * @return BelongsTo<TurnoDeCaja, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoDeCaja::class, 'turno_id');
    }

    /**
     * @return HasMany<AbonoMedio, $this>
     */
    public function medios(): HasMany
    {
        return $this->hasMany(AbonoMedio::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    // ── Lectura ───────────────────────────────────────────────────────

    public function monto(): Decimal
    {
        return Decimal::de($this->total);
    }

    /**
     * ¿Todavía se puede deshacer?
     *
     * Con el turno cerrado la respuesta es no, y el mensaje tiene que
     * decir qué hacer en su lugar: una devolución.
     */
    public function sePuedeAnular(): bool
    {
        return $this->estado === EstadoAbono::Aplicado
            && $this->turno instanceof TurnoDeCaja
            && $this->turno->estaAbierto();
    }

    /**
     * «Efectivo L 2,000.00 · Transferencia o depósito · Ficohsa L 3,000.00»
     * — lo que va en el renglón de la lista y en el recibo.
     */
    public function resumenDeMedios(): string
    {
        /** @var ColeccionDeModelos<int, AbonoMedio> $medios */
        $medios = $this->relationLoaded('medios') ? $this->medios : $this->medios()->get();

        $partes = [];

        foreach ($medios as $medio) {
            $partes[] = $medio->descripcion().' L '.number_format((float) $medio->monto, 2);
        }

        return implode(' · ', $partes);
    }

    public function etiqueta(): string
    {
        return $this->numero;
    }
}
