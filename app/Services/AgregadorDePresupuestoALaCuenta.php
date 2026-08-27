<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Presupuesto;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Pone el paquete presupuestado en la cuenta del paciente (ADR-0009).
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN RENGLÓN: «APENDICECTOMIA · L 40,000»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Eso es lo único que la familia ve como precio. El desglose de los
 * dieciocho renglones vive en el presupuesto y se marca solo a medida
 * que se consume.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 «EN TIEMPO REAL» SIGNIFICA REVERSA + NUEVO, NO UN UPDATE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cuando el presupuesto cambia —se agregó un renglón, se ajustó una
 * cantidad— el monto de la cuenta tiene que seguirlo. Pero **un cargo no
 * se edita**: un trigger de la base rechaza cualquier UPDATE que toque
 * un monto (§9.0.3, ADR-0004).
 *
 * Así que resincronizar es anular el cargo anterior y asentar uno nuevo.
 * La cuenta muestra el número correcto, y de paso queda escrito que el
 * paquete pasó de 40,000 a 46,500 y cuándo — que es exactamente lo que
 * alguien va a preguntar en el egreso.
 *
 * ⚠️ Es idempotente: si el monto no cambió, no toca nada. Sin esa
 * guarda, abrir la pantalla dejaría un par de cargos por cada visita.
 */
final class AgregadorDePresupuestoALaCuenta
{
    private const CODIGO_DEL_PAQUETE = 'PKG-000';

    public function __construct(
        private readonly RegistradorDeCargo $cargos,
        private readonly AnuladorDeCargo $anulador,
        private readonly PoliticaDeDescuentoComercial $politicaDelHospital,
    ) {}

    /**
     * Agrega el paquete, o lo pone al día si el presupuesto cambió.
     *
     * Devuelve el cargo vigente del paquete, o `null` si el presupuesto
     * no tiene nada que cobrar todavía.
     */
    public function sincronizar(Presupuesto $presupuesto): ?Cargo
    {
        $cuenta = $this->cuentaAbiertaDe($presupuesto);
        $item = $this->itemDeCobro($presupuesto);
        $total = Decimal::de($presupuesto->total);
        $descuento = $this->descuentoDeFarmacia($presupuesto, $cuenta, $total);

        return DB::transaction(function () use ($presupuesto, $cuenta, $item, $total, $descuento): ?Cargo {
            $vigente = $this->cargoVigente($presupuesto);

            if ($total->esCero()) {
                if ($vigente instanceof Cargo) {
                    $this->anulador->anular($vigente, 'El presupuesto quedó sin renglones que cobrar.');
                }

                return null;
            }

            /*
             * Idempotencia por monto Y por descuento: si ninguno cambió,
             * no se asienta nada. Es lo que permite llamar a esto cada
             * vez que se toca un renglón —o se entrega un medicamento—
             * sin llenar la bitácora de ruido.
             */
            if ($vigente instanceof Cargo && $this->estaAlDia($vigente, $total, $descuento)) {
                return $vigente;
            }

            if ($vigente instanceof Cargo) {
                $this->anulador->anular($vigente, $this->porQueSeRehace($vigente, $presupuesto, $total, $descuento));
            }

            $cargos = $this->cargos->registrar($cuenta, new LineaDeCargo(
                item: $item,
                cantidad: Decimal::de('1'),

                /*
                 * ⚠️ `cargo_claves.clave` es de tipo UUID: un texto
                 * cualquiera lo rechaza PostgreSQL, no Laravel.
                 *
                 * Se usa un UUID v5, que es DETERMINISTA: el mismo
                 * presupuesto con el mismo monto siempre produce la misma
                 * clave, y por eso reasentar dos veces el mismo total
                 * rebota como repetido. Con un `Str::uuid()` al azar cada
                 * llamada sería un hecho nuevo y la idempotencia no
                 * existiría.
                 *
                 * El monto va adentro a propósito: dos asientos del mismo
                 * presupuesto con montos distintos SON hechos distintos.
                 *
                 * 🔴 Y VA EL ID DEL CARGO QUE SE REEMPLAZA.
                 *
                 * Sin él, la clave describe un ESTADO y no un hecho, y el
                 * estado se repite: se entregan 8 pastillas —el descuento
                 * sube—, se deshace la entrega —el descuento vuelve a
                 * donde estaba— y la clave sería idéntica a una ya
                 * reclamada. El motor la reconocería como reintento y
                 * devolvería el cargo VIEJO, que a esa altura ya está
                 * anulado: la cuenta se quedaría sin el renglón del
                 * paquete y el paciente sin sus L 40,000.
                 *
                 * Con el id adentro cada reasiento es único, y un
                 * reintento del MISMO reasiento —la transacción se cayó y
                 * se vuelve a intentar— sigue dando la misma clave, que es
                 * justo lo que la idempotencia tiene que atajar.
                 *
                 * ⚠️ `->` y no `?->`: el `??` ya tiene semántica de
                 * `isset` y atrapa el nulo solo.
                 */
                claveIdempotencia: (string) Uuid::uuid5(
                    Uuid::NAMESPACE_OID,
                    "presupuesto:{$presupuesto->id}:".$total->redondeado(2)
                        .':'.$descuento->redondeado(2)
                        .':'.($vigente->id ?? 0),
                ),

                /*
                 * 🔴 EL DESCUENTO DE FARMACIA VIAJA EN LEMPIRAS, NO EN
                 * PORCENTAJE.
                 *
                 * El 10 % es de los MEDICAMENTOS, no de la cirugía. Ese
                 * mismo 10 % aplicado sobre los L 40,000 del paquete
                 * regalaría cuatro mil lempiras donde correspondían
                 * ciento cincuenta. Lo que se manda es el monto ya
                 * calculado sobre lo que salió de farmacia.
                 */
                descuentoComercial: $descuento->esCero() ? null : Monto::de($descuento->redondeado(2)),
                motivoDescuento: $descuento->esCero() ? null : $this->motivoDelDescuento($cuenta),
                autorizadoPor: $descuento->esCero() ? null : $cuenta->descuento_hospital_por,
                ocurridoEn: now(),
                precioAcordado: Monto::de($total->redondeado(2)),
                referenciaAcordada: $presupuesto->numero,
                presupuestoId: $presupuesto->id,
                textoDelCargo: $presupuesto->titulo,
            ));

            $cargo = $cargos->first();

            if (! $cargo instanceof Cargo) {
                throw new RuntimeException('El motor no devolvió el cargo del paquete.');
            }

            if ($presupuesto->estado === EstadoPresupuesto::Borrador) {
                $presupuesto->update([
                    'estado'     => EstadoPresupuesto::Agregado,
                    'emitido_en' => now(),
                    'vence_el'   => now()->addDays($this->diasDeVigencia($presupuesto))->toDateString(),
                ]);
            }

            return $cargo;
        });
    }

    /**
     * El cargo del paquete que está vivo: el que tiene el presupuesto y
     * NO tiene línea (los que tienen línea son consumos previstos).
     */
    public function cargoVigente(Presupuesto $presupuesto): ?Cargo
    {
        return Cargo::query()
            ->where('presupuesto_id', $presupuesto->id)
            ->whereNull('presupuesto_linea_id')
            ->whereIn('estado', [EstadoCargo::Pendiente->value, EstadoCargo::Facturado->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ⚠️ Sin cuenta abierta no hay dónde poner el paquete, y el mensaje
     * tiene que decir qué hacer: abrirle la cuenta al paciente primero.
     */
    private function cuentaAbiertaDe(Presupuesto $presupuesto): Cuenta
    {
        if ($presupuesto->encuentro_id === null) {
            throw new RuntimeException(
                'Este presupuesto no está amarrado a un ingreso. Primero hay que abrirle la cuenta al paciente y elegir el ingreso acá.'
            );
        }

        $cuenta = Cuenta::query()
            ->where('encuentro_id', $presupuesto->encuentro_id)
            ->where('estado', EstadoCuenta::Abierta->value)
            ->first();

        if (! $cuenta instanceof Cuenta) {
            throw new RuntimeException(
                'El paciente no tiene una cuenta abierta. Abrila desde «Cuentas abiertas» y volvé a intentarlo.'
            );
        }

        return $cuenta;
    }

    /**
     * El ítem con el que se asienta el cargo del paquete.
     *
     * ─────────────────────────────────────────────────────────────────
     * NADIE LO ELIGE Y NADIE LO VE
     * ─────────────────────────────────────────────────────────────────
     *
     * `cargos.item_id` es obligatorio, pero el paquete no ES un ítem del
     * catálogo: es lo que se acordó por una cirugía completa, y cada caso
     * cotiza distinto. Preguntarle a la cajera «¿con qué ítem se cobra?»
     * era fricción inventada por un detalle de la base.
     *
     * Así que existe UN ítem técnico, y lo que la familia lee en la
     * cuenta es el TÍTULO DEL PRESUPUESTO —«APENDICECTOMIA»—, que viaja
     * en `textoDelCargo` y queda congelado en el cargo.
     *
     * Nace exento a propósito: el paquete es servicio médico (Art. 15 de
     * la Ley del ISV) y lo gravado nunca entra en él (§8.6.1).
     *
     * Si el presupuesto SÍ declaró un ítem propio, ese manda: deja la
     * puerta abierta a cobrar el paquete con `HOS-028 CIRUGIA DE
     * APENDICE` cuando el hospital lo prefiera.
     */
    private function itemDeCobro(Presupuesto $presupuesto): Item
    {
        $item = $presupuesto->itemDeCobro;

        if ($item instanceof Item) {
            return $item;
        }

        return Item::firstOrCreate(
            ['codigo' => self::CODIGO_DEL_PAQUETE],
            [
                'nombre'                    => 'PAQUETE PRESUPUESTADO',
                'descripcion'               => 'Ítem técnico: el renglón de la cuenta lleva el título del presupuesto y su monto acordado (ADR-0009).',
                'tipo'                      => TipoItem::Paquete,
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => CategoriaLegalDeDescuento::IntervencionQuirurgica,
                'se_almacena'               => false,
                'vigencia_desde'            => now()->toDateString(),
            ],
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL DESCUENTO DE FARMACIA TAMBIÉN ALCANZA AL PAQUETE
     * ─────────────────────────────────────────────────────────────────
     *
     * El hospital le da 10 % en medicamentos a esta cuenta. Afuera del
     * paquete eso se aplica solo: cada cargo de farmacia nace rebajado.
     * Adentro no había forma —los medicamentos van incluidos, sin
     * renglón propio— y el paciente con paquete terminaba sin el
     * beneficio que sí recibía el paciente sin paquete, por el mismo
     * medicamento y el mismo día.
     *
     * Acá se cierra: si el presupuesto tenía pastillas a L 10 y se
     * entregaron 150, el renglón de la apendicectomía baja L 150.
     *
     * ⚠️ Se recalcula desde CERO en cada sincronización, contra lo que
     * hay entregado en ese momento. Por eso alcanza también a lo que ya
     * se había despachado antes de que alguien pusiera el descuento: no
     * se acumula, se vuelve a calcular.
     *
     * ─────────────────────────────────────────────────────────────────
     * TOPADO POR LA MISMA POLÍTICA QUE EL MOSTRADOR
     * ─────────────────────────────────────────────────────────────────
     *
     * `CalculadoraDeCargo` verifica dos techos en cada cargo —el de la
     * ley y el de la dirección— y RECHAZA el que se pase. Si el monto
     * llegara sin topar, un paciente de cuarta edad —cuyo tope de
     * política es 0 %, porque el 40 % de ley ya es el techo— haría
     * fallar el asiento del paquete, y entonces una regla de facturación
     * estaría bloqueando una entrega de farmacia. Eso no pasa acá
     * (ADR-0008).
     *
     * Topar da el mismo resultado que la política quería: a quien no
     * puede recibir rebaja comercial no se le da, y nadie se entera por
     * un error rojo en la cara del paciente.
     */
    private function descuentoDeFarmacia(Presupuesto $presupuesto, Cuenta $cuenta, Decimal $bruto): Decimal
    {
        $fraccion = $cuenta->descuento_hospital;

        if ($fraccion === null || $bruto->esCero()) {
            return Decimal::cero();
        }

        $acumulado = Decimal::de(
            $presupuesto->farmaciaEntregada()->por($fraccion)->redondeado(2)
        );

        $tope = $this->politicaDelHospital->topePara(
            $cuenta->encuentro->persona->rangoDeEdadEn(now())
        );

        $maximo = Decimal::de($bruto->por($tope)->redondeado(2));

        return $acumulado->mayorQue($maximo) ? $maximo : $acumulado;
    }

    /**
     * ¿El renglón del paquete ya dice lo que tiene que decir?
     *
     * 🔴 SE COMPARA CONTRA `bruto`, NO CONTRA `total`.
     *
     * `total` es lo que queda DESPUÉS de los descuentos. Un paciente de
     * tercera edad recibe el 25 % de ley por intervención quirúrgica, así
     * que su paquete de L 40,000 tiene un total de L 30,000 — y comparar
     * ese total contra los 40,000 del presupuesto daba siempre distinto:
     * cada vez que alguien abría la pantalla, el paquete se anulaba y se
     * volvía a asentar. Una cuenta que se rehace sola es una cuenta que
     * nadie puede auditar.
     *
     * `bruto` es el monto acordado antes de rebajas: ese es el que tiene
     * que coincidir con el presupuesto. El descuento se compara aparte.
     */
    private function estaAlDia(Cargo $vigente, Decimal $total, Decimal $descuento): bool
    {
        return Decimal::de($vigente->bruto)->igualA($total->redondeado(2))
            && Decimal::de($vigente->descuento_comercial)->igualA($descuento->redondeado(2));
    }

    /**
     * Por qué se rehace, en la bitácora. Son dos hechos distintos y el
     * que lea la cuenta en el egreso necesita saber cuál fue.
     */
    private function porQueSeRehace(Cargo $vigente, Presupuesto $presupuesto, Decimal $total, Decimal $descuento): string
    {
        if (! Decimal::de($vigente->bruto)->igualA($total->redondeado(2))) {
            return "El presupuesto {$presupuesto->numero} cambió de monto y se vuelve a asentar.";
        }

        return "Cambió el descuento de farmacia del paquete {$presupuesto->numero}: "
            ."de L {$vigente->descuento_comercial} a L {$descuento->redondeado(2)}.";
    }

    /**
     * El motivo que queda escrito en el cargo. Lleva el de la cuenta si
     * lo hay —«Cliente frecuente»— más de dónde salió el número, porque
     * un descuento en el renglón de una cirugía es lo primero que alguien
     * va a preguntar de dónde vino.
     *
     * Se recorta a los 200 caracteres de la columna.
     */
    private function motivoDelDescuento(Cuenta $cuenta): string
    {
        $suyo = $cuenta->motivo_descuento_hospital;
        $texto = ($suyo === null || trim($suyo) === '' ? 'Descuento del hospital' : trim($suyo))
            .' · sobre lo entregado de farmacia dentro del paquete';

        return mb_substr($texto, 0, 200);
    }

    private function diasDeVigencia(Presupuesto $presupuesto): int
    {
        $dias = $presupuesto->plantilla?->dias_vigencia;

        if (is_int($dias) && $dias > 0) {
            return $dias;
        }

        $porDefecto = config('sihla.presupuesto.dias_vigencia_por_defecto');

        return is_int($porDefecto) && $porDefecto > 0 ? $porDefecto : 15;
    }
}
