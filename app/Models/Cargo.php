<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\RegimenIsv;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Concerns\BelongsToSede;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\CargoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la cuenta, congelada (§8.5-5).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ LLAVE PRIMARIA COMPUESTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La tabla está particionada por `fecha_operacion`, así que su llave
 * primaria es `(id, fecha_operacion)` — PostgreSQL lo exige. Eloquent
 * sigue tratando `id` como la llave del modelo, que es único de todos
 * modos porque sale de una secuencia global.
 *
 * Lo que sí se ajusta es `setKeysForSaveQuery`: sin eso, cada `save()`
 * haría `WHERE id = ?` y PostgreSQL tendría que mirar las once
 * particiones. Con la fecha adentro, entra derecho a la que toca.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO SE EDITA (§9.0.3)
 * ─────────────────────────────────────────────────────────────────────
 *
 * `$fillable` deja fuera todo el snapshot a propósito: el motor de
 * cargos escribe con `forceCreate` y nadie más tiene por qué armar uno.
 * Y por si acaso, un trigger de la base rechaza cualquier UPDATE que
 * toque un monto y cualquier DELETE.
 *
 * @property int $id
 * @property CarbonInterface $fecha_operacion
 * @property int $sede_id
 * @property int $cuenta_id
 * @property int $encuentro_id
 * @property int $item_id
 * @property int|null $servicio_id
 * @property int|null $medico_id
 * @property int|null $almacen_id
 * @property int|null $lote_id
 * @property int|null $movimiento_id
 * @property int|null $unidad_id
 * @property CarbonInterface $ocurrido_en
 * @property CarbonInterface $registrado_en
 * @property numeric-string $cantidad
 * @property string $texto
 * @property int $convenio_id
 * @property int|null $tarifario_id
 * @property int|null $condicion_id
 * @property OrigenDelPrecio $origen_precio
 * @property numeric-string $precio_unitario
 * @property numeric-string|null $factor_convenio
 * @property RangoEdad|null $categoria_legal
 * @property numeric-string $descuento_legal_fraccion
 * @property string $base_descuento_legal
 * @property numeric-string $descuento_legal
 * @property numeric-string $descuento_comercial
 * @property string|null $motivo_descuento
 * @property int|null $autorizado_por
 * @property RegimenIsv $regimen_isv
 * @property numeric-string $tasa_isv
 * @property numeric-string $bruto
 * @property numeric-string $subtotal
 * @property numeric-string $base_exenta
 * @property numeric-string $base_gravada
 * @property numeric-string $isv
 * @property numeric-string $total
 * @property numeric-string $cobertura_fraccion
 * @property bool $elegible
 * @property numeric-string $porcion_paciente
 * @property numeric-string $porcion_aseguradora
 * @property numeric-string|null $costo_unitario
 * @property numeric-string|null $costo_total
 * @property PoliticaCargo $politica_cargo
 * @property EstadoCargo $estado
 * @property int|null $factura_id
 * @property int|null $revierte_a_id
 * @property string|null $motivo_anulacion
 * @property bool $es_tardio
 * @property string $clave_idempotencia
 * @property int|null $presupuesto_id
 * @property int|null $presupuesto_linea_id
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Cargo extends Model
{
    use BelongsToSede;
    use HasAuditFields;

    /** @use HasFactory<CargoFactory> */
    use HasFactory;

    /**
     * Cuántos minutos dura la ventana en la que quitar una línea todavía
     * es corregir un error de tecleo.
     *
     * No es una preferencia de la instalación: es cuánto tarda alguien en
     * darse cuenta de que escribió mal la línea que acaba de escribir.
     * Media hora es generosa para eso y corta para cualquier otra cosa.
     * El porqué completo está en `pideMotivoParaQuitar()`.
     */
    public const MINUTOS_DE_CORRECCION = 30;

    /**
     * Vacío a propósito. El único que escribe cargos es
     * `RegistradorDeCargo`, y lo hace con el snapshot completo.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_operacion' => 'date',
            'ocurrido_en'     => 'datetime',
            'registrado_en'   => 'datetime',
            'origen_precio'   => OrigenDelPrecio::class,
            'categoria_legal' => RangoEdad::class,
            'regimen_isv'     => RegimenIsv::class,
            'politica_cargo'  => PoliticaCargo::class,
            'estado'          => EstadoCargo::class,
            'elegible'        => 'boolean',
            'es_tardio'       => 'boolean',
            'autorizado_por'  => 'integer',
            'factura_id'      => 'integer',
            'revierte_a_id'   => 'integer',
        ];
    }

    /**
     * Que el UPDATE entre derecho a su partición.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        /*
         * Se llama al padre por su efecto y NO se reasigna: el padre está
         * tipado como `Builder<Model>`, así que reasignar perdería el
         * `static` del genérico y el método dejaría de devolver lo que
         * declara. El objeto es el mismo de todos modos.
         */
        parent::setKeysForSaveQuery($query);

        $fecha = $this->getOriginal('fecha_operacion');

        if ($fecha !== null) {
            $query->where(
                'fecha_operacion',
                $fecha instanceof CarbonInterface ? $fecha->toDateString() : (string) $fecha,
            );
        }

        return $query;
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
     * El paquete presupuestado al que pertenece este cargo, si pertenece
     * a alguno (ADR-0009).
     *
     * @return BelongsTo<Presupuesto, $this>
     */
    public function presupuesto(): BelongsTo
    {
        return $this->belongsTo(Presupuesto::class);
    }

    /**
     * El renglón del presupuesto que este cargo consume. Nulo cuando el
     * cargo ES el paquete, o cuando no tiene nada que ver con uno.
     *
     * @return BelongsTo<PresupuestoLinea, $this>
     */
    public function presupuestoLinea(): BelongsTo
    {
        return $this->belongsTo(PresupuestoLinea::class, 'presupuesto_linea_id');
    }

    /**
     * @return BelongsTo<Encuentro, $this>
     */
    public function encuentro(): BelongsTo
    {
        return $this->belongsTo(Encuentro::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * @return BelongsTo<MovimientoKardex, $this>
     */
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoKardex::class, 'movimiento_id');
    }

    /**
     * @return BelongsTo<Convenio, $this>
     */
    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /**
     * @return BelongsTo<Tarifario, $this>
     */
    public function tarifario(): BelongsTo
    {
        return $this->belongsTo(Tarifario::class);
    }

    /**
     * @return BelongsTo<Servicio, $this>
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }

    /**
     * De qué médico es este honorario. Nulo en todo lo que no lo es —y
     * también en los honorarios cobrados antes de que existiera el
     * registro de médicos: el histórico no se inventa.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * @param Builder<static> $consulta
     *
     * @return Builder<static>
     */
    public function scopeQueSuman(Builder $consulta): Builder
    {
        return $consulta->where(
            $consulta->qualifyColumn('estado'),
            '<>',
            EstadoCargo::Trasladado->value,
        );
    }

    // ── Montos ────────────────────────────────────────────────────────

    public function montoTotal(): Monto
    {
        return $this->esNegativo()
            ? Monto::de(Decimal::de($this->total)->por('-1')->redondeado(2))
            : Monto::de($this->total);
    }

    public function cantidadDecimal(): Decimal
    {
        return Decimal::de($this->cantidad);
    }

    /**
     * El signo lo llevan los montos: la reversa nace en negativo para que
     * el par sume cero sin tocar la fila original.
     */
    public function esNegativo(): bool
    {
        return Decimal::de($this->total)->esNegativo();
    }

    /**
     * Lo que se muestra en la línea de la cuenta: siempre en positivo,
     * con el signo puesto por el texto y el color y no por el número.
     */
    public function totalParaMostrar(): string
    {
        $valor = $this->montoTotal()->formateado();

        return $this->esNegativo() ? '− '.$valor : $valor;
    }

    public function admiteAnulacionDirecta(): bool
    {
        return $this->estado->admiteAnulacionDirecta();
    }

    // ── Quitar una línea ──────────────────────────────────────────────

    /**
     * ¿CONSTA que este cargo lo asentó quien lo está mirando?
     *
     * Es la pregunta del cambio de turno, y la misma por la que la cuenta
     * se agrupa por quien carga (`RenglonDeCuenta`): lo que dio el turno A
     * y lo que dio el turno B son dos renglones a propósito.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 SE PREGUNTA EN POSITIVO, Y NO ES UN DETALLE DE ESTILO
     * ─────────────────────────────────────────────────────────────────
     *
     * La primera versión preguntaba al revés —«¿lo puso otro?»— y con eso
     * los dos nulos caían del lado equivocado: sin sesión, o sin autor en
     * la fila, respondía «no, otro no fue» y la línea se quitaba sin
     * explicar nada. No es un caso de laboratorio: un cargo sembrado por
     * un import del catálogo viejo no tiene autor, y una sesión vencida
     * deja `auth()->id()` en nulo.
     *
     * Preguntado en positivo, no saber es simplemente no constar — que es
     * la verdad— y la duda cae del lado seguro sin ninguna guarda extra.
     */
    public function loPusoEsteUsuario(?int $usuario): bool
    {
        return $usuario !== null
            && $this->created_by !== null
            && $this->created_by === $usuario;
    }

    /**
     * ¿Se asentó dentro de la ventana de corrección?
     *
     * Se mira `registrado_en` —cuándo entró al sistema— y no
     * `ocurrido_en`, que es cuándo pasó en la sala. Un cargo tardío se
     * asienta hoy por algo de ayer: la ventana para corregir el tecleo
     * arranca cuando se tecleó.
     */
    public function esDeRecien(): bool
    {
        return $this->registrado_en->gt(now()->subMinutes(self::MINUTOS_DE_CORRECCION));
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 CUÁNDO LA ✕ TIENE QUE PREGUNTAR POR QUÉ
     * ─────────────────────────────────────────────────────────────────
     *
     * La ✕ se dejó sin motivo a propósito, y ese argumento sigue en pie:
     * quitar una línea que se tecleó hace diez segundos es corregir un
     * error de tipeo, y pedir una justificación escrita para eso no
     * produce auditoría — produce «aaaaaaaaaa».
     *
     * Pero vale mientras se cumplan las DOS cosas: que la línea sea tuya
     * y que sea de recién. Fuera de ahí ya no se está corrigiendo un
     * tecleo:
     *
     *   · NO CONSTA QUE SEA TUYA. La puso otro turno, o la fila no tiene
     *     autor, o no se sabe quién está mirando. Quien la quita no sabe
     *     por qué se puso, y quien la puso no va a saber por qué
     *     desapareció. Ese hueco es el que después nadie puede explicar.
     *   · ES VIEJA. El medicamento pudo salir del carro y el paciente
     *     pudo recibirlo. «Se quitó» y «no se le dio» dejan de ser lo
     *     mismo.
     *
     * El caso que abrió esto: el paciente ve el monto, dice que prefiere
     * pedir traslado, y hay que quitarle dos dosis que cargó el turno
     * anterior. Eso no es un tipeo — es una decisión del paciente, y
     * tiene que quedar escrita con esas palabras.
     *
     * ⚠️ Vive en el modelo y no en la pantalla. La pantalla esconde el
     * botón, pero `quitarCargo()` es un método público de Livewire: se
     * puede llamar desde el cliente sin que exista ningún botón.
     */
    public function pideMotivoParaQuitar(?int $usuario): bool
    {
        return ! $this->loPusoEsteUsuario($usuario) || ! $this->esDeRecien();
    }
}
