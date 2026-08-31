<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\CargoException;
use App\Domain\Exceptions\CuentaException;
use App\Domain\Exceptions\EncuentroException;
use App\Domain\Exceptions\PrecioNoDefinidoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\DescuentoAplicable;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PrecioResuelto;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Persona;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * La única puerta para meterle algo a la cuenta de un paciente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ HACE, TODO DENTRO DE UNA TRANSACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 *   1. Verifica que el hecho todavía se pueda registrar.
 *   2. Reclama la clave de idempotencia (`cargo_claves`).
 *   3. Bloquea la cuenta — es lo que hace que el tope por evento del
 *      seguro se lea y se consuma sin carreras.
 *   4. Resuelve el precio a la FECHA DEL SERVICIO (§8.5-3).
 *   5. Resuelve el descuento de ley por la edad a esa fecha.
 *   6. Descuenta existencia por FEFO, si el ítem mueve inventario.
 *   7. Asienta el cargo con el snapshot completo.
 *   8. Actualiza los totales materializados de la cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 UN CARGO POR LOTE, Y NO ES UN CAPRICHO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si diez tabletas salen de dos lotes distintos, se asientan DOS cargos.
 * §9.F9 exige poder responder en segundos «a qué pacientes se les
 * administró el lote X» ante un retiro de mercado; con un solo cargo
 * apuntando a un solo lote, la mitad de esas tabletas quedaría sin
 * trazabilidad y el hospital no podría notificar.
 *
 * Además, cada lote sale con su costo, y mezclar costos en una línea
 * arruina el margen del caso.
 *
 * ─────────────────────────────────────────────────────────────────────
 * IDEMPOTENCIA REAL, NO UN BOTÓN DESHABILITADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El botón deshabilitado es cortesía; el cinturón es la restricción
 * única (§8.6.2-3). Se reclama la clave con `insertOrIgnore` —nunca
 * try/catch, porque en PostgreSQL un INSERT fallido aborta la
 * transacción entera— y si ya estaba, se devuelven los cargos que ese
 * mismo hecho produjo la primera vez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ POR QUÉ ESTE SERVICIO SOBREVIVE AL BLOQUE 6
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cuando llegue la dispensación real de farmacia, va a llamar acá con su
 * propio movimiento en vez de crear un camino paralelo. El índice único
 * `cargos_un_cargo_por_movimiento` garantiza que ningún movimiento de
 * kardex pueda cobrarse dos veces.
 */
final class RegistradorDeCargo
{
    public function __construct(
        private readonly ResolutorDePrecio $precios,
        private readonly ResolutorDeDescuentoLegal $descuentos,
        private readonly CalculadoraDeCargo $calculadora,
        private readonly CalculadoraDeCobertura $coberturas,
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
        private readonly ConsultorDeExistencias $existencias,
    ) {}

    /**
     * @return Collection<int, Cargo>
     *
     * @throws CargoException
     * @throws CuentaException
     * @throws EncuentroException
     */
    public function registrar(Cuenta $cuenta, LineaDeCargo $linea): Collection
    {
        $cuenta->loadMissing(['encuentro', 'convenio']);

        $encuentro = $cuenta->encuentro;

        if (! $encuentro->admiteCargos()) {
            throw EncuentroException::noAdmiteCargos($encuentro->numero, $encuentro->estado->etiqueta());
        }

        if (! $cuenta->admiteCargos()) {
            throw CuentaException::noAdmiteCargos($cuenta->numero, $cuenta->estado->etiqueta());
        }

        $ocurridoEn = $linea->ocurridoEn ?? now();

        if ($linea->item->mueveInventario() && ! $linea->almacen instanceof Almacen) {
            throw CargoException::necesitaAlmacen($linea->item->nombre);
        }

        /** @var Collection<int, Cargo> $cargos */
        $cargos = DB::transaction(function () use ($cuenta, $linea, $ocurridoEn): Collection {
            $yaEstaba = $this->reclamarLaClave($linea->claveIdempotencia, $ocurridoEn);

            if ($yaEstaba) {
                return $this->cargosDelOrigen($linea->claveIdempotencia);
            }

            /*
             * La cuenta se bloquea ANTES de tocar existencias, y siempre
             * en ese orden: cuenta → existencia. Un orden distinto entre
             * dos procesos es cómo nace un interbloqueo, y el que se cae
             * es el que estaba cobrando (lección del bloque 5d-1).
             */
            $bloqueada = Cuenta::query()
                ->whereKey($cuenta->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 🔴 RE-CHECK DENTRO DE LA TRANSACCIÓN, y con las relaciones
             * RELEÍDAS.
             *
             * Lo verificado arriba se leyó hace milisegundos y sin
             * candado. Entre esa lectura y este punto, caja pudo cerrar
             * la cuenta o cambiar el pagador: el `lockForUpdate` espera a
             * que ese COMMIT termine y después devuelve la fila NUEVA.
             *
             * Sin volver a mirar, se asentaría un cargo en una cuenta ya
             * facturada —que nadie va a cobrar— o se congelaría el precio
             * con el convenio anterior mientras la cuenta ya tiene otro.
             * Reusar las relaciones del objeto viejo produce exactamente
             * ese segundo caso, así que se recargan.
             */
            $bloqueada->load(['encuentro', 'convenio']);

            if (! $bloqueada->encuentro->admiteCargos()) {
                throw EncuentroException::noAdmiteCargos(
                    $bloqueada->encuentro->numero,
                    $bloqueada->encuentro->estado->etiqueta(),
                );
            }

            if (! $bloqueada->admiteCargos()) {
                throw CuentaException::noAdmiteCargos($bloqueada->numero, $bloqueada->estado->etiqueta());
            }

            $repartos = $this->repartirPorLote($linea);

            /** @var Collection<int, Cargo> $cargos */
            $cargos = new Collection;

            $indice = 0;

            foreach ($repartos as $reparto) {
                $cargos->push($this->asentar(
                    cuenta: $bloqueada,
                    linea: $linea,
                    lote: $reparto['lote'],
                    cantidad: $reparto['cantidad'],
                    indice: $indice,
                    /*
                     * 🔴 El descuento autorizado EN LEMPIRAS se aplica UNA
                     * vez, en la primera fila. Repartir diez ampollas entre
                     * dos lotes no autoriza dos descuentos: sería regalar el
                     * doble de lo que alguien firmó.
                     *
                     * El PORCENTAJE es al revés, y por eso se resuelve
                     * adentro: va en TODAS las filas. El 30 % de diez
                     * ampollas es el 30 % de las seis de un lote más el 30 %
                     * de las cuatro del otro. Dárselo solo a la primera fila
                     * le cobraría al paciente un descuento más chico que el
                     * que se le dijo, y cuánto más chico dependería de cómo
                     * quedó partido el inventario esa mañana.
                     */
                    conDescuento: $indice === 0,
                    ocurridoEn: $ocurridoEn,
                ));

                /*
                 * 🔴 Los totales se refrescan DESPUÉS DE CADA FILA, no al
                 * final del bucle.
                 *
                 * El tope por evento del seguro se lee de
                 * `total_aseguradora`. Con el refresco afuera, las tres
                 * filas de un reparto FEFO leerían el mismo acumulado y
                 * cada una se llevaría el tope entero: con L 1,000
                 * disponibles y tres lotes, la aseguradora terminaría
                 * cubriendo L 3,000 por encima de lo contratado. La
                 * glosa llega a los sesenta días, cuando ya no se cobra.
                 */
                $this->refrescarTotales($bloqueada);

                $indice++;
            }

            $primero = $cargos->first();

            if ($primero instanceof Cargo) {
                DB::table('cargo_claves')
                    ->where('clave', $linea->claveIdempotencia)
                    ->update([
                        'cargo_id'        => $primero->id,
                        'fecha_operacion' => $primero->fecha_operacion->toDateString(),
                    ]);
            }

            return $cargos;
        });

        return $cargos;
    }

    /**
     * Asienta UNA fila: la de un lote, o la única si el ítem no mueve
     * inventario.
     */
    private function asentar(
        Cuenta $cuenta,
        LineaDeCargo $linea,
        ?Lote $lote,
        Decimal $cantidad,
        int $indice,
        bool $conDescuento,
        CarbonInterface $ocurridoEn,
    ): Cargo {
        $item = $linea->item;
        $encuentro = $cuenta->encuentro;
        $convenio = $cuenta->convenio;

        $fechaServicio = $ocurridoEn;

        $precio = $this->resolverPrecio($linea, $cuenta, $fechaServicio, $lote);
        $descuento = $this->descuentoDe($encuentro->persona, $item, $fechaServicio);
        $cobertura = $this->coberturas->para($cuenta, $precio->fila);

        /*
         * El monto fijo va solo en la primera fila; el porcentaje, en
         * todas. El porqué está en el bucle que reparte por lote.
         */
        $montoAutorizado = $conDescuento ? $linea->descuentoComercial : null;
        $porcentajeAutorizado = $linea->descuentoComercialPorcentaje;
        $llevaDescuento = $montoAutorizado !== null || $porcentajeAutorizado !== null;

        $calculo = $this->calculadora->calcular(
            linea: new LineaDeCargo(
                item: $item,
                cantidad: $cantidad,
                claveIdempotencia: $linea->claveIdempotencia,
                almacen: $linea->almacen,
                lote: $lote,
                descuentoComercial: $montoAutorizado,
                descuentoComercialPorcentaje: $porcentajeAutorizado,
                motivoDescuento: $llevaDescuento ? $linea->motivoDescuento : null,
                autorizadoPor: $llevaDescuento ? $linea->autorizadoPor : null,
                servicioId: $linea->servicioId,
                ocurridoEn: $ocurridoEn,
                precioAcordado: $linea->precioAcordado,
                referenciaAcordada: $linea->referenciaAcordada,
                presupuestoId: $linea->presupuestoId,
                presupuestoLineaId: $linea->presupuestoLineaId,
                politica: $linea->politica,
                textoDelCargo: $linea->textoDelCargo,
            ),
            precio: $precio,
            descuentoLegal: $descuento,
            convenio: $convenio,
            cobertura: $cobertura,
            fechaServicio: $fechaServicio,
        );

        $movimiento = null;
        $costoUnitario = null;

        if ($linea->almacen instanceof Almacen && $item->mueveInventario()) {
            $costoUnitario = $this->costos->vigenteBloqueado($item, $linea->almacen);

            $movimiento = $this->movimientos->registrar(
                item: $item,
                lote: $lote,
                almacen: $linea->almacen,
                tipo: TipoMovimiento::SalidaPorDispensacion,
                cantidad: $cantidad,
                motivo: 'Cargo a la cuenta '.$cuenta->numero,
                referencia: $cuenta->numero,
                ocurridoEn: $ocurridoEn,
                costoUnitario: $costoUnitario,
            );

            /*
             * El promedio no cambia con una salida, pero la base contra
             * la que se pondera SÍ tiene que seguir a la existencia real
             * (lección 🔴 del bloque 5c). Sin esto, la próxima entrada
             * pondera contra una cantidad que ya no está en el estante.
             */
            $this->costos->sincronizarCantidadBase($item, $linea->almacen);
        }

        $columnas = array_merge($calculo->comoColumnas(), [
            'fecha_operacion' => $this->fechaDeOperacion($ocurridoEn),
            'sede_id'         => $cuenta->sede_id,
            'cuenta_id'       => $cuenta->id,
            'encuentro_id'    => $encuentro->id,
            'item_id'         => $item->id,
            'servicio_id'     => $linea->servicioId ?? $encuentro->servicio_id,

            /*
             * De quién es el honorario. Nulo en todo lo demás: un
             * medicamento con médico puesto ensuciaría la liquidación
             * con renglones que no son honorarios suyos.
             */
            'medico_id' => $linea->medicoId,
            /*
             * El almacén se guarda solo si de verdad salió algo de él. Un
             * ítem que no mueve inventario con `almacen_id` puesto
             * atribuye una consulta médica a la bodega central, y ese
             * ruido después no se limpia.
             */
            'almacen_id'       => $movimiento instanceof MovimientoKardex ? $linea->almacen?->id : null,
            'lote_id'          => $lote?->id,
            'movimiento_id'    => $movimiento instanceof MovimientoKardex ? $movimiento->id : null,
            'unidad_id'        => $item->unidad_dispensacion_id,
            'ocurrido_en'      => $ocurridoEn,
            'registrado_en'    => now(),
            'cantidad'         => $cantidad->redondeado(4),
            'texto'            => $linea->textoDelCargo ?? $this->textoCongelado($item->nombre, $lote),
            'convenio_id'      => $convenio->id,
            'motivo_descuento' => $llevaDescuento ? $linea->motivoDescuento : null,
            'autorizado_por'   => $llevaDescuento ? $linea->autorizadoPor : null,
            'costo_unitario'   => $costoUnitario?->redondeado(6),
            'costo_total'      => $costoUnitario === null
                ? null
                : $costoUnitario->por($cantidad)->redondeado(2),
            /*
             * El paquete presupuestado (ADR-0009). Con `presupuesto_id` y
             * sin línea, este cargo ES el paquete; con los dos, es un
             * consumo previsto que NO se le vuelve a cobrar al paciente.
             */
            'presupuesto_id'       => $linea->presupuestoId,
            'presupuesto_linea_id' => $linea->presupuestoLineaId,

            /*
             * La política del ítem manda, salvo que quien llama la
             * fuerce: un consumo que ya estaba presupuestado entra como
             * `IncluidoEnTarifa` aunque el ítem sea cobrable.
             */
            'politica_cargo' => ($linea->politica ?? $item->politica_cargo)->value,

            'estado'             => EstadoCargo::Pendiente->value,
            'es_tardio'          => $cuenta->estado->marcaCargosComoTardios(),
            'clave_origen'       => $linea->claveIdempotencia,
            'clave_idempotencia' => $this->claveDeLaFila($linea->claveIdempotencia, $indice),
            'created_by'         => auth()->id(),
        ]);

        return Cargo::query()->forceCreate($columnas);
    }

    /**
     * Cómo se reparte la cantidad entre lotes, en orden FEFO.
     *
     * Un solo elemento con lote `null` cuando el ítem no mueve
     * inventario o cuando quien llama ya eligió el lote a mano — que es
     * lo que hace la devolución y lo que hará la dispensación con receta
     * en el bloque 6.
     *
     * @return list<array{lote: Lote|null, cantidad: Decimal}>
     */
    private function repartirPorLote(LineaDeCargo $linea): array
    {
        if (! $linea->mueveInventario()) {
            return [['lote' => null, 'cantidad' => $linea->cantidad]];
        }

        if ($linea->lote instanceof Lote) {
            return [['lote' => $linea->lote, 'cantidad' => $linea->cantidad]];
        }

        /** @var Almacen $almacen */
        $almacen = $linea->almacen;

        $disponibles = $this->existencias->enOrdenFefo($linea->item, $almacen);

        $repartos = [];
        $pendiente = $linea->cantidad;

        foreach ($disponibles as $existencia) {
            if ($pendiente->esCero() || $pendiente->esNegativo()) {
                break;
            }

            $saldo = Decimal::de((string) $existencia->getAttribute('cantidad'));

            if ($saldo->esCero() || $saldo->esNegativo()) {
                continue;
            }

            $toma = $saldo->menorQue($pendiente) ? $saldo : $pendiente;

            $repartos[] = [
                /*
                 * `lote_id` y no `instanceof`: Larastan tipa las
                 * relaciones BelongsTo como NO nulas, así que preguntarle
                 * `instanceof` a una relación es una condición que el
                 * analizador da siempre por verdadera (§9.B1). La columna
                 * sí es nulable y es la que hay que mirar.
                 */
                'lote'     => $existencia->lote_id === null ? null : $existencia->lote,
                'cantidad' => $toma,
            ];

            $pendiente = $pendiente->restar($toma);
        }

        /*
         * 🔴 Si no alcanzó, se corta ACÁ y no se escribe nada.
         *
         * La tentación es empujar el faltante contra el último lote y
         * dejar que el kardex reviente. No sirve: en el camino FEFO ese
         * lote puede ser nulo, y un cargo de medicamento sin lote es un
         * paciente sin trazabilidad ante un retiro de mercado (§9.F9).
         * Además el mensaje llegaría después de haber tocado existencias.
         *
         * ⚠️ Deuda declarada: las existencias se leen sin candado para
         * planificar el reparto, así que dos dispensaciones simultáneas
         * del mismo lote pueden planificar las dos y la segunda falla al
         * escribir —el UPDATE condicional de `RegistradorDeMovimiento` la
         * detiene, así que no hay sobregiro; lo que hay es un reintento.
         */
        if (! $pendiente->esCero() && ! $pendiente->esNegativo()) {
            throw CargoException::sinExistenciaSuficiente(
                $linea->item->nombre,
                $almacen->nombre,
                $linea->cantidad->redondeado(4),
                $linea->cantidad->restar($pendiente)->redondeado(4),
            );
        }

        return $repartos === [] ? [['lote' => null, 'cantidad' => $linea->cantidad]] : $repartos;
    }

    /**
     * @throws CargoException
     */
    private function resolverPrecio(
        LineaDeCargo $linea,
        Cuenta $cuenta,
        CarbonInterface $fechaServicio,
        ?Lote $lote = null,
    ): PrecioResuelto {
        /*
         * 🔴 EL PRECIO ACORDADO GANA (ADR-0009).
         *
         * Es el paquete quirúrgico: la familia acordó L 40,000 por la
         * apendicectomía completa y ese monto no está en ningún
         * tarifario. Es el ÚNICO camino por el que un cargo lleva un
         * precio que no salió de una fila con vigencia; todo lo demás
         * sigue pasando por el resolutor de siempre (ADR-0003).
         */
        if ($linea->precioAcordado instanceof Monto) {
            return PrecioResuelto::acordado(
                $linea->precioAcordado,
                $linea->referenciaAcordada ?? 'del paciente',
            );
        }

        try {
            return $this->precios->para(
                item: $linea->item,
                convenio: $cuenta->convenio,
                fechaServicio: $fechaServicio,
                sede: $cuenta->sede,

                /*
                 * El envase sale del lote que FEFO eligió: es de ese
                 * frasco de donde se está sirviendo, y por eso es su
                 * precio el que corresponde.
                 */
                presentacion: $lote?->presentacion,
            );
        } catch (PrecioNoDefinidoException) {
            throw CargoException::sinPrecio(
                $linea->item->nombre,
                $cuenta->convenio->nombre,
                $fechaServicio->format('d/m/Y'),
            );
        }
    }

    /**
     * El descuento de ley se resuelve por la edad A LA FECHA DEL
     * SERVICIO, no a la de hoy. El paciente que cumplió sesenta el mes
     * pasado no tenía derecho en la cirugía de marzo.
     */
    private function descuentoDe(
        Persona $persona,
        Item $item,
        CarbonInterface $fechaServicio,
    ): DescuentoAplicable {
        $rango = $persona->rangoDeEdadEn($fechaServicio);

        if (! $rango instanceof RangoEdad) {
            return DescuentoAplicable::ninguno();
        }

        return $this->descuentos->para($item, $rango, $fechaServicio);
    }

    /**
     * `true` si la clave ya estaba reclamada — o sea, si esto es un
     * reintento y no un hecho nuevo.
     */
    private function reclamarLaClave(string $clave, CarbonInterface $ocurridoEn): bool
    {
        $insertadas = DB::table('cargo_claves')->insertOrIgnore([
            'clave'           => $clave,
            'cargo_id'        => 0,
            'fecha_operacion' => $this->fechaDeOperacion($ocurridoEn),
            'created_at'      => now(),
        ]);

        return $insertadas === 0;
    }

    /**
     * @return Collection<int, Cargo>
     */
    private function cargosDelOrigen(string $clave): Collection
    {
        /*
         * Sin el filtro, un reintento del mismo hecho devolvería también
         * la REVERSA —que hereda el `clave_origen` del original— y la
         * pantalla confirmaría el doble del monto. Peor todavía para el
         * bloque 6: una interfaz HL7 que reintente creería que el cobro
         * está puesto cuando ya fue anulado.
         */
        return Cargo::query()
            ->where('clave_origen', $clave)
            ->whereNull('revierte_a_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * La clave de cada fila, derivada del hecho. Determinista a
     * propósito: el reintento produce los mismos uuid y choca contra el
     * índice único en vez de duplicar.
     */
    private function claveDeLaFila(string $origen, int $indice): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, $origen.':'.$indice)->toString();
    }

    /**
     * La fecha de negocio, derivada por PHP y nunca por PostgreSQL
     * (§7.5-1). El servidor puede estar en UTC, y el corte de caja
     * saldría corrido seis horas.
     */
    private function fechaDeOperacion(CarbonInterface $ocurridoEn): string
    {
        return $ocurridoEn->copy()
            ->setTimezone((string) config('app.timezone', 'America/Tegucigalpa'))
            ->toDateString();
    }

    /**
     * El nombre del ítem congelado, con el lote adentro cuando lo hay.
     * El catálogo se corrige y se renombra; la factura del año pasado
     * tiene que seguir diciendo lo que decía.
     */
    private function textoCongelado(string $nombre, ?Lote $lote): string
    {
        $texto = $lote instanceof Lote
            ? $nombre.' · lote '.$lote->numero
            : $nombre;

        return mb_substr($texto, 0, 200);
    }

    /**
     * Los totales, escritos en la MISMA transacción que el cargo (§13.5).
     *
     * Se recalculan desde los cargos en vez de sumarle el delta al valor
     * anterior: es idempotente, se autocorrige si algo quedó desfasado, y
     * cuesta una agregación sobre las pocas filas de una cuenta abierta —
     * que ya vienen podadas por partición y por índice.
     */
    private function refrescarTotales(Cuenta $cuenta): void
    {
        $totales = $cuenta->recalcular();

        $cuenta->forceFill($totales);
        $cuenta->save();
    }

    /**
     * Cuánto hay en el estante, para que la pantalla avise antes de que
     * alguien intente cargar lo que no existe.
     */
    public function disponibleEn(Item $item, Almacen $almacen): Decimal
    {
        return $this->existencias->totalEn($item, $almacen);
    }
}
