<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\ValueObjects\Decimal;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que el hospital no tenía y alguien le prestó.
 *
 * El kardex dice QUÉ HAY; este documento dice A QUIÉN SE LE DEBE. La
 * cantidad va siempre en la unidad del kardex para que comparar contra el
 * saldo del almacén sea una resta y no una conversión.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $item_id
 * @property int|null $item_presentacion_id
 * @property int $almacen_id
 * @property int|null $lote_id
 * @property numeric-string $cantidad
 * @property numeric-string $cantidad_saldada
 * @property QuienPresta $presta_tipo
 * @property int|null $proveedor_id
 * @property string $presta_nombre
 * @property string|null $presta_telefono
 * @property FormaDeSaldo $forma_de_saldo
 * @property numeric-string|null $monto_acordado
 * @property EstadoPrestamo $estado
 * @property int|null $cuenta_id
 * @property string|null $motivo
 * @property CarbonInterface $ocurrido_en
 * @property CarbonInterface $registrado_en
 * @property CarbonInterface $fecha_operacion
 * @property CarbonInterface|null $saldado_en
 * @property int|null $saldado_por
 * @property string|null $referencia_del_saldo
 * @property-read Item $item
 * @property-read ItemPresentacion|null $itemPresentacion
 * @property-read Almacen $almacen
 * @property-read Lote|null $lote
 * @property-read Proveedor|null $proveedor
 * @property-read Cuenta|null $cuenta
 */
class Prestamo extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<PrestamoFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'prestamos';

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'item_id',
        'item_presentacion_id',
        'almacen_id',
        'lote_id',
        'cantidad',
        'cantidad_saldada',
        'presta_tipo',
        'proveedor_id',
        'presta_nombre',
        'presta_telefono',
        'forma_de_saldo',
        'monto_acordado',
        'estado',
        'cuenta_id',
        'motivo',
        'ocurrido_en',
        'registrado_en',
        'fecha_operacion',
        'saldado_en',
        'saldado_por',
        'referencia_del_saldo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presta_tipo'     => QuienPresta::class,
            'forma_de_saldo'  => FormaDeSaldo::class,
            'estado'          => EstadoPrestamo::class,
            'ocurrido_en'     => 'datetime',
            'registrado_en'   => 'datetime',
            'fecha_operacion' => 'date',
            'saldado_en'      => 'datetime',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<ItemPresentacion, $this> */
    public function itemPresentacion(): BelongsTo
    {
        return $this->belongsTo(ItemPresentacion::class);
    }

    /** @return BelongsTo<Almacen, $this> */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /** @return BelongsTo<Lote, $this> */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** @return BelongsTo<Cuenta, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /** @return BelongsTo<User, $this> */
    public function saldadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saldado_por');
    }

    // ── Lo que se debe ────────────────────────────────────────────────

    /**
     * Lo que falta devolver o pagar, en unidades del kardex.
     */
    public function saldoPendiente(): Decimal
    {
        return Decimal::de($this->cantidad)->restar(Decimal::de($this->cantidad_saldada));
    }

    /**
     * ¿Le queda debiendo el hospital?
     *
     * Un préstamo cerrado no debe nada aunque el tipo genere deuda, y lo
     * que trajo la familia del paciente no debe nada aunque esté abierto.
     * Las dos condiciones tienen que darse.
     */
    public function seDebe(): bool
    {
        return $this->estado->sigueAbierto() && $this->presta_tipo->generaDeuda();
    }

    /**
     * Quién prestó, listo para leer en una lista.
     */
    public function deQuien(): string
    {
        return $this->presta_nombre;
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /**
     * Los que todavía no se cerraron, se deban o no.
     *
     * @param Builder<Prestamo> $consulta
     *
     * @return Builder<Prestamo>
     */
    public function scopeAbiertos(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', [
            EstadoPrestamo::Pendiente->value,
            EstadoPrestamo::Parcial->value,
        ]);
    }

    /**
     * Lo que el hospital DEBE de verdad.
     *
     * ⚠️ Excluye lo que trajo el médico o la familia del paciente. Eso se
     * registra para que el kardex cuadre y el medicamento quede trazado,
     * pero no es una deuda y no puede aparecer en la lista de lo que hay
     * que devolver: una lista con ruido deja de mirarse.
     *
     * @param Builder<Prestamo> $consulta
     *
     * @return Builder<Prestamo>
     */
    public function scopeQueSeDeben(Builder $consulta): Builder
    {
        return $consulta
            ->abiertos()
            ->whereIn('presta_tipo', array_map(
                static fn (QuienPresta $tipo): string => $tipo->value,
                array_values(array_filter(
                    QuienPresta::cases(),
                    static fn (QuienPresta $tipo): bool => $tipo->generaDeuda(),
                )),
            ));
    }

    /**
     * Lo que se le debe a alguien de ESTE ítem.
     *
     * Es la consulta del aviso al recibir mercadería: cuando entra el
     * producto, es el único momento en que alguien lo tiene enfrente y
     * puede saldar la deuda.
     *
     * @param Builder<Prestamo> $consulta
     *
     * @return Builder<Prestamo>
     */
    public function scopeDelItem(Builder $consulta, int $itemId): Builder
    {
        return $consulta->where('item_id', $itemId);
    }
}
