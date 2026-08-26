<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\RenglonDeCuenta;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\CuentaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * La cuenta viva del paciente (§8.6.3).
 *
 * Los totales de esta fila son materializados y se escriben en la misma
 * transacción que cada cargo. `recalcular()` existe para el test que los
 * verifica contra los cargos uno por uno — si algún día no coinciden,
 * eso es un defecto y hay que verlo, no taparlo.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $encuentro_id
 * @property string $numero
 * @property int $convenio_id
 * @property string|null $numero_poliza
 * @property string|null $numero_autorizacion
 * @property int|null $responsable_persona_id
 * @property EstadoCuenta $estado
 * @property CarbonInterface $abierta_en
 * @property CarbonInterface|null $congelada_en
 * @property CarbonInterface|null $cerrada_en
 * @property int|null $cerrada_por
 * @property CarbonInterface|null $anulada_en
 * @property string|null $motivo_anulacion
 * @property string|null $motivo_apertura
 * @property int|null $cuenta_anterior_id
 * @property numeric-string $total_bruto
 * @property numeric-string $total_descuento
 * @property numeric-string $total_exento
 * @property numeric-string $total_gravado
 * @property numeric-string $total_isv
 * @property numeric-string $total
 * @property numeric-string $total_paciente
 * @property numeric-string $total_aseguradora
 * @property int $lineas
 * @property numeric-string|null $descuento_hospital
 * @property string|null $motivo_descuento_hospital
 * @property int|null $descuento_hospital_por
 * @property CarbonInterface|null $descuento_hospital_en
 */
class Cuenta extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<CuentaFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'encuentro_id',
        'numero',
        'convenio_id',
        'numero_poliza',
        'numero_autorizacion',
        'responsable_persona_id',
        'estado',
        'abierta_en',
        'motivo_apertura',
        'cuenta_anterior_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'       => EstadoCuenta::class,
            'abierta_en'   => 'datetime',
            'congelada_en' => 'datetime',
            'cerrada_en'   => 'datetime',
            'anulada_en'   => 'datetime',
            'cerrada_por'  => 'integer',
            'lineas'       => 'integer',

            'descuento_hospital_en'  => 'datetime',
            'descuento_hospital_por' => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

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
     * @return BelongsTo<Persona, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'responsable_persona_id');
    }

    /**
     * @return HasMany<Cargo, $this>
     */
    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class);
    }

    /**
     * La cuenta leída como documento: un renglón por producto y por
     * persona que lo entregó.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ AGRUPADA Y NO CARGO POR CARGO
     * ─────────────────────────────────────────────────────────────────
     *
     * Enfermería entrega cuando toca, no una sola vez. Diez mililitros a
     * las ocho y cinco a las once son dos cargos —y así tienen que quedar,
     * cada uno con su movimiento de kardex y su lote— pero en la pantalla
     * son un solo renglón de quince, porque los dio la misma persona.
     *
     * Cuando entra el turno siguiente y da veinte, ahí sí abre renglón
     * nuevo: **quién le dio qué a este paciente** es la pregunta que se
     * hace en el cambio de turno, y un renglón de treinta y cinco la
     * borra.
     *
     * ⚠️ Se ordena por el PRIMER cargo de cada grupo, no por el último:
     * así el renglón del turno A se queda donde nació aunque el turno A
     * agregue otra dosis después de que el turno B ya cargó lo suyo. Una
     * cuenta que se reordena sola mientras alguien la revisa es una cuenta
     * que nadie termina de revisar.
     *
     * 🔴 Se muestran las líneas VIVAS. Rehacer una línea para aplicarle el
     * descuento deja tres filas que suman cero entre ellas; esto es el
     * documento que el paciente va a recibir, no el libro. El rastro
     * completo vive en «Cargos» y en la bitácora.
     *
     * @return Collection<int, RenglonDeCuenta>
     */
    public function renglonesVivos(int $tope = 200): Collection
    {
        return $this->cargos()
            ->with('createdBy:id,name')
            ->whereNotIn('estado', [
                EstadoCargo::Anulado->value,
                EstadoCargo::Anulacion->value,
                EstadoCargo::Trasladado->value,
            ])
            /*
             * Lo incluido en el paquete no se dibuja como renglón: ya se
             * ve en el desglose, con su check. Mostrarlo dos veces le
             * haría creer a la familia que se le está cobrando aparte
             * (ADR-0009).
             */
            ->where('politica_cargo', PoliticaCargo::Cobrable->value)
            ->orderBy('id')
            ->limit($tope)
            ->get()
            /*
             * La llave lleva el descuento POR UNIDAD y no el total: el
             * total es proporcional a la cantidad, así que dos entregas
             * del mismo producto con la misma tasa darían descuentos
             * distintos y no se agruparían nunca.
             */
            ->groupBy(fn (Cargo $cargo): string => implode('|', [
                $cargo->texto,
                (string) ($cargo->created_by ?? 0),
                $cargo->precio_unitario,
                /*
                 * La cobertura entra por la misma razón que el precio: si
                 * el convenio cubría 80 % cuando salieron los primeros 10
                 * ml y 60 % cuando salieron los otros 5, sumarlos daría un
                 * renglón cuyo reparto entre paciente y aseguradora no se
                 * puede leer de ninguna parte. El importe seguiría bien;
                 * lo que se perdería es poder explicarle al paciente por
                 * qué le toca lo que le toca.
                 */
                $cargo->cobertura_fraccion,
                Decimal::de($cargo->cantidad)->esCero()
                    ? '0'
                    : Decimal::de($cargo->descuento_legal)
                        ->sumar($cargo->descuento_comercial)
                        ->entre($cargo->cantidad)
                        ->redondeado(4),
            ]))
            ->map(fn (Collection $grupo): RenglonDeCuenta => RenglonDeCuenta::de($grupo))
            ->values();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LA BITÁCORA: TODO LO QUE ENTRÓ Y TODO LO QUE SE QUITÓ
     * ─────────────────────────────────────────────────────────────────
     *
     * `renglonesVivos()` es el DOCUMENTO —lo que el paciente va a recibir
     * impreso— y por eso esconde las anuladas y sus reversas: tres filas
     * que suman cero vuelven ilegible justo lo que hay que revisar.
     *
     * Esto es lo contrario, y hacía falta. En el cambio de turno la
     * pregunta no es «cuánto va la cuenta», es **qué pasó acá**: quién
     * cargó qué, a qué hora, qué se quitó, quién lo quitó y por qué. Con
     * solo las vivas, una línea que el turno anterior puso y alguien
     * quitó es una línea que nunca existió — y eso es exactamente lo que
     * nadie puede explicar después.
     *
     * No se construye nada: los cargos YA son el libro. Son append-only
     * (§9.0.3, ADR-0004), cada uno trae quién y cuándo, y cada anulación
     * asienta su reversa con `revierte_a_id` y su motivo. Lo único que
     * faltaba era mirarlos en orden.
     *
     * ⚠️ Orden por `registrado_en` —cuándo se tecleó— y no por
     * `ocurrido_en`. La bitácora cuenta lo que pasó CON EL SISTEMA; un
     * cargo tardío entra donde alguien lo entró, no donde dice que pasó.
     * El desempate por `id` mantiene estable el orden de los que caen en
     * el mismo instante.
     *
     * ⚠️ Devuelve una colección de Eloquent y no la de Support que usa
     * `renglonesVivos()`: acá no se agrupa nada, son las filas tal cual.
     * Declararla como la otra sería mentirle al analizador sobre lo que
     * sale de un `->get()`.
     *
     * @return ColeccionDeModelos<int, Cargo>
     */
    public function bitacora(int $tope = 300): ColeccionDeModelos
    {
        /** @var ColeccionDeModelos<int, Cargo> $movimientos */
        $movimientos = $this->cargos()
            ->with(['createdBy:id,name', 'item:id,codigo,nombre'])
            ->orderBy('registrado_en')
            ->orderBy('id')
            ->limit($tope)
            ->get();

        return $movimientos;
    }

    /**
     * ¿Hay más de una persona cargando en esta cuenta?
     *
     * Es lo que decide si el renglón dice quién lo entregó. Con una sola
     * persona el nombre se repite en cada línea y no informa nada; con dos
     * o más es el dato por el que se agrupa. Un rótulo que aparece solo
     * cuando dice algo se lee; uno que está siempre se vuelve decorado.
     */
    public function laCargaMasDeUno(): bool
    {
        return $this->cargos()
            ->whereNotIn('estado', [
                EstadoCargo::Anulado->value,
                EstadoCargo::Anulacion->value,
                EstadoCargo::Trasladado->value,
            ])
            ->distinct()
            ->count('created_by') > 1;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function cuentaAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cuenta_anterior_id');
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * @param Builder<static> $consulta
     *
     * @return Builder<static>
     */
    public function scopeVivas(Builder $consulta): Builder
    {
        return $consulta->whereIn(
            $consulta->qualifyColumn('estado'),
            EstadoCuenta::valoresVivos(),
        );
    }

    // ── Montos ────────────────────────────────────────────────────────

    public function saldo(): Monto
    {
        return Monto::de($this->total);
    }

    public function saldoDelPaciente(): Monto
    {
        return Monto::de($this->total_paciente);
    }

    public function saldoDeLaAseguradora(): Monto
    {
        return Monto::de($this->total_aseguradora);
    }

    /**
     * Cuánto le queda al pagador antes de topar. `null` = sin tope.
     *
     * Se lee bajo el candado de la cuenta, dentro de la transacción del
     * cargo: es lo que permite que cada línea nazca ya dividida y que
     * ninguna tenga que corregirse al cerrar (§9.0.3).
     */
    public function disponibleDelTope(): ?Monto
    {
        $convenio = $this->convenio;

        if ($convenio->tope_por_evento === null) {
            return null;
        }

        $tope = Monto::de((string) $convenio->tope_por_evento);
        $usado = $this->saldoDeLaAseguradora();

        if ($usado->mayorQue($tope) || $usado->igualA($tope)) {
            return Monto::cero();
        }

        return $tope->restar($usado);
    }

    // ── Preguntas ─────────────────────────────────────────────────────

    public function admiteCargos(): bool
    {
        return $this->estado->admiteCargos();
    }

    public function estaViva(): bool
    {
        return $this->estado->estaViva();
    }

    /**
     * Los totales recalculados desde los cargos, uno por uno.
     *
     * NO se usa en el camino normal: existe para el test que compara la
     * materialización contra la realidad, y para el día que haya que
     * probar que una cuenta cuadra. Es la misma regla que el kardex —
     * saldo derivado verificable— aplicada al dinero (§8.7-1).
     *
     * @return array<string, numeric-string|int>
     */
    public function recalcular(): array
    {
        /*
         * 🔴 EL DINERO SUMA LAS REVERSAS; EL CONTEO NO.
         *
         * Un cargo anulado se queda en la tabla con sus importes intactos
         * y al lado nace su reversa con los mismos números en negativo:
         * los dos SE TIENEN que sumar para que el total dé bien, porque
         * se cancelan entre sí. Ese es el diseño y está bien.
         *
         * Pero contarlos como líneas es otra cosa. La tarjeta decía «8
         * ítems» sobre una cuenta con dos renglones, porque estaba
         * contando el original anulado, su reversa y el que lo reemplazó.
         * El paciente lleva DOS cosas, no ocho, y ese número es lo primero
         * que alguien mira para saber si la cuenta cuadra con la bolsa.
         *
         * `FILTER` es de Postgres y hace justo esto: sumar sobre todas
         * las filas y contar solo sobre algunas, en una sola pasada.
         */
        $vivos = sprintf(
            "COUNT(*) FILTER (WHERE estado NOT IN ('%s', '%s')) AS lineas",
            EstadoCargo::Anulado->value,
            EstadoCargo::Anulacion->value,
        );

        $suma = $this->cargos()
            ->where('estado', '<>', EstadoCargo::Trasladado->value)
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 SOLO SUMA LO COBRABLE (ADR-0009)
             * ─────────────────────────────────────────────────────────
             *
             * `IncluidoEnTarifa` sale de bodega, descuenta existencia y
             * congela su costo —todo eso sigue pasando— pero NO se le
             * cobra al paciente: ya está dentro del paquete
             * presupuestado. `GastoDelServicio` tampoco: se imputa al
             * centro de costo del área.
             *
             * Sin este filtro, el medicamento presupuestado se cobraba
             * DOS veces: una adentro del paquete y otra como renglón
             * propio. La política existía desde el ADR-0003 pero nada la
             * miraba al sumar.
             */
            ->where('politica_cargo', PoliticaCargo::Cobrable->value)
            ->selectRaw(
                'COALESCE(SUM(bruto), 0) AS bruto,
                 COALESCE(SUM(descuento_legal + descuento_comercial), 0) AS descuento,
                 COALESCE(SUM(base_exenta), 0) AS exento,
                 COALESCE(SUM(base_gravada), 0) AS gravado,
                 COALESCE(SUM(isv), 0) AS isv,
                 COALESCE(SUM(total), 0) AS total,
                 COALESCE(SUM(porcion_paciente), 0) AS paciente,
                 COALESCE(SUM(porcion_aseguradora), 0) AS aseguradora,
                 '.$vivos
            )
            ->first();

        if ($suma === null) {
            return [
                'total_bruto'    => '0.00', 'total_descuento' => '0.00', 'total_exento' => '0.00',
                'total_gravado'  => '0.00', 'total_isv' => '0.00', 'total' => '0.00',
                'total_paciente' => '0.00', 'total_aseguradora' => '0.00', 'lineas' => 0,
            ];
        }

        return [
            'total_bruto'       => Monto::de((string) $suma->getAttribute('bruto'))->valor(),
            'total_descuento'   => Monto::de((string) $suma->getAttribute('descuento'))->valor(),
            'total_exento'      => Monto::de((string) $suma->getAttribute('exento'))->valor(),
            'total_gravado'     => Monto::de((string) $suma->getAttribute('gravado'))->valor(),
            'total_isv'         => Monto::de((string) $suma->getAttribute('isv'))->valor(),
            'total'             => Monto::de((string) $suma->getAttribute('total'))->valor(),
            'total_paciente'    => Monto::de((string) $suma->getAttribute('paciente'))->valor(),
            'total_aseguradora' => Monto::de((string) $suma->getAttribute('aseguradora'))->valor(),
            'lineas'            => (int) $suma->getAttribute('lineas'),
        ];
    }

    public function etiqueta(): string
    {
        return $this->numero;
    }
}
