<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\ValueObjects\Decimal;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\PresupuestoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * El presupuesto al paciente — un estimado, no un cargo (ADR-0008).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 `disponible` NO ES UNA COLUMNA Y NO PUEDE SERLO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se calcula acá, cada vez, contra los totales materializados de las
 * cuentas del encuentro. Guardarlo sería el `UPDATE productos SET
 * existencia` del §9.G1: un número editable que en tres días deja de
 * corresponder con los hechos y nadie sabe cuándo se desvió.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EL CONSUMO SE SUMA EN PHP Y NO CON UN `SUM()`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque son una o dos filas, no dos millones: un encuentro tiene una
 * cuenta viva, y como mucho una segunda si cambió el pagador. Sumarlas
 * con `Decimal` mantiene la aritmética exacta de punta a punta y evita
 * que el driver devuelva un float por el camino (§ prohibición de
 * `float` para dinero).
 *
 * El cargo trasladado no se cuenta dos veces: `EstadoCargo::Trasladado`
 * deja de sumar en la cuenta vieja en cuanto suma en la nueva
 * (`EstadoCargo::cuentaEnElSaldo()`), así que sumar las dos cuentas es
 * correcto por construcción.
 *
 * @property int $id
 * @property int $sede_id
 * @property string $numero
 * @property int $expediente_id
 * @property int $persona_id
 * @property int|null $encuentro_id
 * @property int $convenio_id
 * @property int|null $plantilla_id
 * @property int|null $item_cobro_id
 * @property string $titulo
 * @property EstadoPresupuesto $estado
 * @property CarbonInterface|null $emitido_en
 * @property CarbonInterface|null $vence_el
 * @property numeric-string $total_bruto
 * @property numeric-string $total_descuento
 * @property numeric-string $total_exento
 * @property numeric-string $total_gravado
 * @property numeric-string $total_isv
 * @property numeric-string $total
 * @property int $lineas
 * @property int|null $presupuesto_anterior_id
 * @property string|null $motivo_revision
 * @property int|null $responsable_persona_id
 * @property CarbonInterface|null $firmado_en
 * @property string|null $notas
 * @property CarbonInterface|null $anulado_en
 * @property string|null $motivo_anulacion
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Presupuesto extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<PresupuestoFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'numero',
        'expediente_id',
        'persona_id',
        'encuentro_id',
        'convenio_id',
        'plantilla_id',
        'item_cobro_id',
        'titulo',
        'estado',
        'emitido_en',
        'vence_el',
        'presupuesto_anterior_id',
        'motivo_revision',
        'responsable_persona_id',
        'firmado_en',
        'notas',
        'anulado_en',
        'motivo_anulacion',

        /*
         * Los totales SÍ van en fillable, al revés que en `cuentas`.
         *
         * En la cuenta los escribe el motor de cargos bajo candado y
         * nadie más debe poder tocarlos. Acá los escribe el cotizador de
         * una sola vez, y dejarlos fuera los haría descartar EN SILENCIO
         * —Laravel no avisa— con un presupuesto que se guarda en cero y
         * una barra que mide contra nada.
         */
        'total_bruto',
        'total_descuento',
        'total_exento',
        'total_gravado',
        'total_isv',
        'total',
        'lineas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'     => EstadoPresupuesto::class,
            'emitido_en' => 'datetime',
            'vence_el'   => 'date',
            'firmado_en' => 'datetime',
            'anulado_en' => 'datetime',
            'lineas'     => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
     * @return HasMany<PresupuestoLinea, $this>
     */
    public function detalle(): HasMany
    {
        return $this->hasMany(PresupuestoLinea::class)->orderBy('orden');
    }

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<Encuentro, $this>
     */
    public function encuentro(): BelongsTo
    {
        return $this->belongsTo(Encuentro::class);
    }

    /**
     * @return BelongsTo<Convenio, $this>
     */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /**
     * @return BelongsTo<PlantillaPresupuesto, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaPresupuesto::class, 'plantilla_id');
    }

    /**
     * Con qué ítem del catálogo se cobra el paquete en la cuenta.
     *
     * @return BelongsTo<Item, $this>
     */
    public function itemDeCobro(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_cobro_id');
    }

    /**
     * @return BelongsTo<Presupuesto, $this>
     */
    public function anterior(): BelongsTo
    {
        return $this->belongsTo(Presupuesto::class, 'presupuesto_anterior_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'responsable_persona_id');
    }

    // ── El medidor ────────────────────────────────────────────────────

    /**
     * Lo que la atención lleva consumido, leído de los totales
     * materializados de las cuentas del encuentro.
     *
     * Sin encuentro amarrado devuelve cero: una cotización a alguien que
     * todavía no ingresó no consume nada, y decir lo contrario pintaría
     * de verde una barra que no está midiendo nada.
     */
    public function consumido(): Decimal
    {
        if ($this->encuentro_id === null) {
            return Decimal::cero();
        }

        $cuentas = Cuenta::query()
            ->where('encuentro_id', $this->encuentro_id)
            ->where('estado', '<>', EstadoCuenta::Anulada->value)
            ->get();

        $suma = Decimal::cero();

        foreach ($cuentas as $cuenta) {
            $suma = $suma->sumar($cuenta->total);
        }

        return $suma;
    }

    /**
     * Lo que queda. PUEDE SER NEGATIVO, y por eso se opera con `Decimal`
     * y no con `Monto`: `Monto` rechaza negativos en el constructor, y
     * un presupuesto excedido es justamente el caso que hay que poder
     * mostrar.
     */
    public function disponible(): Decimal
    {
        return Decimal::de($this->total)->restar($this->consumido());
    }

    /**
     * Qué fracción del presupuesto se lleva consumida. Con total en cero
     * devuelve cero en vez de dividir: un presupuesto sin líneas no está
     * «100 % consumido», está vacío.
     */
    public function fraccionConsumida(): Decimal
    {
        $total = Decimal::de($this->total);

        if ($total->esCero()) {
            return Decimal::cero();
        }

        return $this->consumido()->entre($total);
    }

    public function excedido(): bool
    {
        return $this->disponible()->esNegativo();
    }

    /**
     * ¿Ya pasó el umbral de aviso temprano? El umbral es configuración
     * (§1.1): política del hospital, no del programa.
     */
    public function enAlerta(): bool
    {
        $umbral = config('sihla.presupuesto.umbral_alerta');

        return $this->fraccionConsumida()->mayorQue(
            Decimal::de(is_numeric($umbral) ? (string) $umbral : '0.80')
        );
    }

    /**
     * Verde mientras haya margen, ámbar cuando conviene hablar con la
     * familia, rojo cuando ya se pasó. **Ninguno de los tres bloquea un
     * cargo** — avisan (ADR-0008).
     */
    public function semaforo(): string
    {
        if ($this->excedido()) {
            return 'danger';
        }

        return $this->enAlerta() ? 'warning' : 'success';
    }

    /**
     * Lo que el hospital ESPERA que cueste esta cirugía, según la
     * plantilla de la que salió. Nulo si no vino de plantilla o si la
     * plantilla no tiene tope.
     */
    public function topeDeReferencia(): ?Decimal
    {
        $tope = $this->plantilla?->tope_referencia;

        return $tope === null ? null : Decimal::de($tope);
    }

    /**
     * ⚠️ AVISA, NO IMPIDE. Un caso puede costar de verdad más que el
     * tope; lo que no puede es pasarse sin que nadie lo note.
     */
    public function excedeElTope(): bool
    {
        $tope = $this->topeDeReferencia();

        return $tope !== null && Decimal::de($this->total)->mayorQue($tope);
    }

    /**
     * Qué incluye el paquete y cuánto de eso ya se consumió (ADR-0009).
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LO CONSUMIDO ES DERIVADO, NO UNA COLUMNA
     * ─────────────────────────────────────────────────────────────────
     *
     * Sale de UNA consulta agrupada sobre `cargos`. Materializarlo en
     * `presupuesto_lineas` sería el `UPDATE productos SET existencia` del
     * §9.G1: un número editable que en tres días deja de corresponder con
     * los hechos y nadie sabe cuándo se desvió.
     *
     * Los anulados y trasladados no cuentan: lo que se revirtió no se
     * consumió.
     *
     * ⚠️ Los literales del `match` van EN EL TIPO, no como `string`: el
     * genérico de `Collection` no es covariante, así que prometer
     * `string` donde se devuelve `'completo'|'parcial'|'pendiente'` es
     * un error para PHPStan aunque cada literal sea un string. Aflojarlo
     * con un cast escondería lo que de verdad devuelve.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 SOLO SE ESPERA LO QUE SALE DE UN ESTANTE
     * ─────────────────────────────────────────────────────────────────
     *
     * Un honorario, la sala de operaciones o un hemograma no se
     * «entregan»: se prestan. Nadie va a farmacia a buscarlos, así que
     * dejarlos esperando un check que jamás va a llegar convierte la
     * lista en ruido y esconde lo único que de verdad hay que ir a traer.
     *
     * Esos entran como `incluido` desde el principio. Solo lo que
     * `mueveInventario()` —medicamentos e insumos— queda pendiente hasta
     * que farmacia lo despache de verdad.
     *
     * @return Collection<int, array{linea: PresupuestoLinea, consumida: Decimal, estado: 'incluido'|'completo'|'parcial'|'pendiente'}>
     */
    public function desglose(): Collection
    {
        /** @var Collection<int, Cargo> $cargos */
        $cargos = Cargo::query()
            ->where('presupuesto_id', $this->id)
            ->whereNotNull('presupuesto_linea_id')
            ->whereNotIn('estado', [
                EstadoCargo::Anulado->value,
                EstadoCargo::Anulacion->value,
                EstadoCargo::Trasladado->value,
            ])
            ->get(['presupuesto_linea_id', 'cantidad']);

        $porLinea = [];

        foreach ($cargos as $cargo) {
            $id = $cargo->presupuesto_linea_id;

            if ($id === null) {
                continue;
            }

            $porLinea[$id] = isset($porLinea[$id])
                ? $porLinea[$id]->sumar($cargo->cantidad)
                : Decimal::de($cargo->cantidad);
        }

        return $this->detalle()->with('item')->get()->map(
            function (PresupuestoLinea $linea) use ($porLinea): array {
                $consumida = $porLinea[$linea->id] ?? Decimal::cero();
                $pedida = Decimal::de($linea->cantidad);
                $saleDeFarmacia = $linea->item?->mueveInventario() ?? false;

                return [
                    'linea'     => $linea,
                    'consumida' => $consumida,
                    'estado'    => match (true) {
                        ! $saleDeFarmacia             => 'incluido',
                        $consumida->esCero()          => 'pendiente',
                        $consumida->menorQue($pedida) => 'parcial',
                        default                       => 'completo',
                    },
                ];
            }
        );
    }

    /**
     * Lo que YA SALIÓ DE FARMACIA dentro del paquete, valuado a los
     * precios del presupuesto.
     *
     * ─────────────────────────────────────────────────────────────────
     * PARA QUÉ EXISTE: EL DESCUENTO DEL HOSPITAL
     * ─────────────────────────────────────────────────────────────────
     *
     * La rebaja del mostrador es solo para lo que sale de farmacia. Pero
     * adentro del paquete los medicamentos NO tienen renglón propio —van
     * incluidos en el precio de la cirugía—, así que no hay de dónde
     * rebajarlos: la rebaja tiene que salir del renglón del paquete, y
     * esta es su base.
     *
     * 🔴 A PRECIO DEL PRESUPUESTO, Y SOLO POR LO ENTREGADO.
     *
     *   · **Precio del presupuesto**, porque es el que la familia vio. Si
     *     el papel decía L 10 la pastilla, el 10 % es L 1 aunque el
     *     tarifario haya cambiado el martes.
     *   · **Solo lo entregado**, porque descontar lo presupuestado y no
     *     despachado sería rebajarle al paciente un medicamento que
     *     nunca salió del estante.
     *
     * Lo que no mueve inventario —honorarios, quirófano, laboratorio— no
     * entra nunca: el descuento del hospital tampoco los alcanza cuando
     * se cobran sueltos.
     */
    public function farmaciaEntregada(): Decimal
    {
        $base = Decimal::cero();

        foreach ($this->desglose() as $fila) {
            if ($fila['estado'] === 'incluido') {
                continue;
            }

            $base = $base->sumar(
                Decimal::de($fila['linea']->precio_unitario)->por($fila['consumida'])
            );
        }

        return $base;
    }

    public function estaVencido(CarbonInterface $fecha): bool
    {
        return $this->vence_el !== null && $this->vence_el->lt($fecha->startOfDay());
    }

    /**
     * ⚠️ El caso del NN de las 3 am (§1.5): se cotizó bajo CONTADO y a
     * las 6 llegó la familia con la póliza. Los precios de este papel son
     * de otro pagador.
     *
     * Devuelve `true` cuando no hay con qué comparar todavía: sin cuenta
     * abierta no hay desajuste que avisar.
     */
    public function convenioCoincideConLaCuenta(): bool
    {
        $cuenta = $this->cuentaViva();

        return $cuenta === null || $cuenta->convenio_id === $this->convenio_id;
    }

    public function cuentaViva(): ?Cuenta
    {
        if ($this->encuentro_id === null) {
            return null;
        }

        return Cuenta::query()
            ->where('encuentro_id', $this->encuentro_id)
            ->where('estado', EstadoCuenta::Abierta->value)
            ->first();
    }
}
