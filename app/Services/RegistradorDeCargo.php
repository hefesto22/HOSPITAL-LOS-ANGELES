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
use App\Models\ConvenioCondicion;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Persona;
use App\Models\Tarifario;
use App\Support\TextoCanonico;
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
        private readonly FormasDeCobro $formas,
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

        if ($linea->item->mueveInventario()
            && ! $linea->almacen instanceof Almacen
            && ! $this->seCargaSinInventario($linea)) {
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
            $envasesPorFila = $this->envasesPorFila($linea, $repartos);

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
                    envases: $envasesPorFila[$indice] ?? null,
                    sinExistencia: $reparto['sinExistencia'] ?? false,
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
     *
     * `$sinExistencia`: la parte de un producto con stock infinito que no
     * alcanzó en el estante. Se cobra igual, pero no mueve el kardex: no
     * hay de dónde sacarla.
     */
    private function asentar(
        Cuenta $cuenta,
        LineaDeCargo $linea,
        ?Lote $lote,
        Decimal $cantidad,
        int $indice,
        bool $conDescuento,
        CarbonInterface $ocurridoEn,
        ?Decimal $envases = null,
        bool $sinExistencia = false,
    ): Cargo {
        $item = $linea->item;
        $encuentro = $cuenta->encuentro;
        $convenio = $cuenta->convenio;

        $fechaServicio = $ocurridoEn;

        $precio = $this->resolverPrecio($linea, $cuenta, $fechaServicio);
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
                mismoPrecioQue: $linea->mismoPrecioQue,
                descuentoDeLaCuenta: $linea->descuentoDeLaCuenta,
                /*
                 * El envase y cuántos de ESTA fila: con ellos la
                 * calculadora cobra la caja sin abrir a su precio entero
                 * y no a cien veces el unitario.
                 */
                presentacion: $this->presentacionDelCargo($item, $linea),
                envases: $envases,
            ),
            precio: $precio,
            descuentoLegal: $descuento,
            convenio: $convenio,
            cobertura: $cobertura,
            fechaServicio: $fechaServicio,
        );

        $movimiento = null;
        $costoUnitario = null;

        if (! $sinExistencia && $linea->almacen instanceof Almacen && $item->mueveInventario()) {
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
            'almacen_id'    => $movimiento instanceof MovimientoKardex ? $linea->almacen?->id : null,
            'lote_id'       => $lote?->id,
            'movimiento_id' => $movimiento instanceof MovimientoKardex ? $movimiento->id : null,
            'unidad_id'     => $item->unidad_dispensacion_id,

            /*
             * En qué envase se cobró. Solo si de verdad se cobró por
             * envase: un honorario o un jarabe vendido por mililitro
             * viajan sin esto, y el renglón se lee con la cantidad de
             * dispensación, que ahí es la correcta.
             *
             * ⚠️ Se valida que la presentación sea DE ESTE ítem. Una de
             * otro producto convertiría el renglón en «1 CAJA X 100» de
             * algo que se entregó en frasco, y eso el CHECK de la base no
             * lo puede ver.
             */
            'item_presentacion_id'  => $this->presentacionDelCargo($item, $linea)?->id,
            'cantidad_presentacion' => $envases?->redondeado(4),
            'ocurrido_en'           => $ocurridoEn,
            'registrado_en'         => now(),
            'cantidad'              => $cantidad->redondeado(4),
            'texto'                 => $linea->textoDelCargo ?? $this->textoCongelado($item->nombre, $lote),
            'convenio_id'           => $convenio->id,
            'motivo_descuento'      => $llevaDescuento ? TextoCanonico::parrafoEnMayusculas($linea->motivoDescuento) : null,
            'autorizado_por'        => $llevaDescuento ? $linea->autorizadoPor : null,
            'costo_unitario'        => $costoUnitario?->redondeado(6),
            'costo_total'           => $costoUnitario === null
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
     * Cuántos envases le tocan a cada fila del reparto por lote.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LOS ENVASES SE REPARTEN ENTRE LAS FILAS, NO SE COPIAN
     * ─────────────────────────────────────────────────────────────────
     *
     * Tres cajas sin abrir pueden salir dos de un lote y una de otro: se
     * asientan dos filas. Con los envases copiados enteros a cada una, el
     * renglón —que los suma— diría «6 CAJA» por trescientas tabletas: una
     * cantidad que nadie entregó, en la factura que el paciente lee.
     *
     * Se reparten en la proporción de lo que salió de cada lote. Como un
     * envase sin abrir sale entero de un solo lote (`repartirPorLote()`),
     * cada fila recibe envases enteros: 2 y 1. El residuo del redondeo va
     * igual a la última fila, para que la suma dé exactamente lo vendido;
     * una parte que redondea a cero queda sin envases: la base prohíbe el
     * cero, y la fila igual dice de qué presentación salió.
     *
     * @param list<array{lote: Lote|null, cantidad: Decimal, sinExistencia?: bool}> $repartos
     *
     * @return list<Decimal|null>
     */
    private function envasesPorFila(LineaDeCargo $linea, array $repartos): array
    {
        $envases = $this->envaseDelCargo($linea->item, $linea);

        if (! $envases instanceof Decimal || count($repartos) <= 1) {
            return array_map(static fn (array $reparto): ?Decimal => $envases, $repartos);
        }

        $filas = [];
        $asignado = Decimal::cero();
        $ultima = count($repartos) - 1;

        foreach ($repartos as $indice => $reparto) {
            $parte = $indice === $ultima
                ? $envases->restar($asignado)
                : Decimal::de($envases->por($reparto['cantidad'])->entre($linea->cantidad)->redondeado(4));

            $asignado = $asignado->sumar($parte);
            $filas[] = Decimal::de($parte->redondeado(4))->esCero() ? null : $parte;
        }

        return $filas;
    }

    /**
     * Cómo se reparte la cantidad entre lotes, en orden FEFO.
     *
     * Un solo elemento con lote `null` cuando el ítem no mueve
     * inventario o cuando quien llama ya eligió el lote a mano — que es
     * lo que hace la devolución y lo que hará la dispensación con receta
     * en el bloque 6.
     *
     * Con stock infinito, lo que no alcanzó va al final como una fila
     * más, sin lote y marcada `sinExistencia`.
     *
     * @return list<array{lote: Lote|null, cantidad: Decimal, sinExistencia?: bool}>
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

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL ENVASE SIN ABRIR SALE ENTERO, DE UN LOTE QUE LO TRAE
         * ─────────────────────────────────────────────────────────────
         *
         * Una caja de 100 vendida sin abrir es una caja que estaba en el
         * estante con su lote impreso. Si FEFO la armara con tabletas de
         * un lote de blíster —o con las 60 que quedaban en una caja ya
         * abierta y 40 de otra—, el cargo diría que el paciente se llevó
         * un lote y la caja en la mano diría otro: ante un retiro de
         * mercado, la trazabilidad lote → paciente (§9.F9) apuntaría al
         * paciente equivocado.
         *
         * Así que de cada lote se toman solo envases enteros, y solo de
         * los lotes que entraron en ese envase o en uno que lo trae
         * adentro: el BLISTER X 10 sí sale de un lote de CAJA X 100
         * (Mauricio, 11-oct-2026). La regla es de
         * `FormasDeCobro::sinAbrirEnElLote()`.
         *
         * Solo para lo que se vende sin abrir. La tableta suelta sigue
         * saliendo de donde FEFO diga, que es la regla de siempre.
         */
        $sinAbrir = $this->envaseDelCargo($linea->item, $linea) instanceof Decimal
            ? $this->presentacionDelCargo($linea->item, $linea)
            : null;

        $presentaciones = $sinAbrir instanceof ItemPresentacion
            ? $this->presentacionesDe($linea->item)
            : null;

        $repartos = [];
        $pendiente = $linea->cantidad;

        foreach ($disponibles as $existencia) {
            if ($pendiente->esCero() || $pendiente->esNegativo()) {
                break;
            }

            $saldo = Decimal::de((string) $existencia->getAttribute('cantidad'));

            if ($sinAbrir instanceof ItemPresentacion && $presentaciones !== null) {
                $saldo = $this->formas->sinAbrirEnElLote(
                    envase: $sinAbrir,
                    envaseDelLote: $existencia->lote_id === null ? null : $existencia->lote->item_presentacion_id,
                    saldo: $saldo,
                    presentaciones: $presentaciones,
                );
            }

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
        /*
         * ─────────────────────────────────────────────────────────────
         * STOCK INFINITO: LO QUE NO ALCANZÓ SE CARGA IGUAL (13-oct-2026)
         * ─────────────────────────────────────────────────────────────
         *
         * Mientras el inventario de este producto no esté cuadrado, se
         * cobra aunque el estante no alcance. Lo que sí había ya salió
         * arriba, de su lote y con su kardex —así, cuando se apague el
         * infinito, la existencia ya es la de verdad—. El resto va como
         * una fila más, sin lote y sin movimiento.
         *
         * Nunca para un controlado: `tieneStockInfinito()` lo descarta.
         */
        if (! $pendiente->esCero() && ! $pendiente->esNegativo() && $linea->item->tieneStockInfinito()) {
            $repartos[] = ['lote' => null, 'cantidad' => $pendiente, 'sinExistencia' => true];

            return $repartos;
        }

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
     * ¿Se asienta sin tocar el inventario, aunque el ítem se almacene y no
     * venga almacén?
     *
     *   · Producto con stock infinito y sin estante elegido: no hay en
     *     ningún lado, y mientras se cuadra el inventario se cobra igual.
     *   · Línea REHECHA de un cargo que no movió el kardex —un descuento
     *     aplicado a algo que se vendió sin existencia—. Rehacerla no es
     *     volver a venderla: si el original no sacó nada del estante, el
     *     rehecho tampoco, aunque al producto ya le hayan apagado el
     *     infinito. Sin esto, apagarlo trabaría el descuento de cualquier
     *     cuenta abierta que lo tuviera.
     */
    private function seCargaSinInventario(LineaDeCargo $linea): bool
    {
        if ($linea->item->tieneStockInfinito()) {
            return true;
        }

        $original = $linea->mismoPrecioQue;

        return $original instanceof Cargo && $original->movimiento_id === null;
    }

    /**
     * @throws CargoException
     */
    private function resolverPrecio(
        LineaDeCargo $linea,
        Cuenta $cuenta,
        CarbonInterface $fechaServicio,
    ): PrecioResuelto {
        /*
         * 🔴 LA LÍNEA REHECHA CONSERVA SU PRECIO. Cambiarle el descuento
         * no es volver a venderla: va antes que todo, incluso que el
         * precio acordado, porque el acordado también quedó en el cargo.
         */
        if ($linea->mismoPrecioQue instanceof Cargo) {
            return $this->precioComoEnElCargo($linea->mismoPrecioQue);
        }

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
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL PRECIO ES DE LA FORMA EN QUE SE VENDE (11-oct-2026)
                 * ─────────────────────────────────────────────────────
                 *
                 * Antes era el del envase del LOTE del que se servía. Con
                 * la caja como precio de promoción eso cobraba la tableta
                 * suelta al precio de la caja solo porque salía de una
                 * caja. Ahora: la caja sin abrir, al precio de la caja; el
                 * blíster sin abrir, al del blíster —aunque salga de un
                 * lote de cajas—; lo suelto, al de la suelta, que es la
                 * fila sin envase. Sin fila propia del envase se cae a la
                 * de la suelta, que nunca es más barata.
                 */
                presentacion: $this->envaseDelCargo($linea->item, $linea) instanceof Decimal
                    ? $this->presentacionDelCargo($linea->item, $linea)
                    : null,
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
     * El precio tal como quedó congelado en un cargo ya asentado.
     *
     * El NÚMERO sale del cargo: la fila del tarifario pudo corregirse
     * después, y lo que se le cobró al paciente es lo que dice el cargo
     * (§8.5-5). La fila y la condición van igual —borradas incluidas—
     * porque de ellas salen la cobertura, la explicación y el rastro de
     * dónde vino el precio.
     */
    private function precioComoEnElCargo(Cargo $cargo): PrecioResuelto
    {
        $fila = $cargo->tarifario_id === null
            ? null
            : Tarifario::query()->withTrashed()->find($cargo->tarifario_id);

        $condicion = $cargo->condicion_id === null
            ? null
            : ConvenioCondicion::query()->withTrashed()->find($cargo->condicion_id);

        return new PrecioResuelto(
            precio: Monto::de($cargo->precio_unitario),
            origen: $cargo->origen_precio,
            fila: $fila instanceof Tarifario ? $fila : null,
            condicion: $condicion instanceof ConvenioCondicion ? $condicion : null,
        );
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
     * De qué envase salió, valga o no como unidad de cobro.
     *
     * 🔴 Esto es el ORIGEN y se guarda SIEMPRE que la presentación sea de
     * este ítem, con o sin `cantidad_presentacion`. Antes las dos columnas
     * iban juntas o ninguna, y eso perdía el dato justo en el caso más
     * común: cinco tabletas sacadas de la caja de 100. La cantidad va en
     * tabletas —de una caja abierta no se cobra «media caja»— pero de cuál
     * salieron es lo que contesta «de qué envase se está vendiendo» y lo
     * que le da a quién aplicarse al precio por presentación.
     *
     * ⚠️ Se valida que la presentación sea DE ESTE ítem. Una de otro
     * producto convertiría el renglón en «CAJA X 100» de algo que se
     * entregó en frasco, y eso el CHECK de la base no lo puede ver.
     */
    private function presentacionDelCargo(Item $item, LineaDeCargo $linea): ?ItemPresentacion
    {
        $presentacion = $linea->presentacion;

        return $presentacion instanceof ItemPresentacion && $presentacion->item_id === $item->id
            ? $presentacion
            : null;
    }

    /**
     * Cuántos envases se cobraron, o nulo cuando no se cobró por envase.
     *
     * ⚠️ Devuelve nulo también cuando la presentación NO es de este
     * ítem. Una de otro producto dejaría el renglón diciendo «1 CAJA X
     * 100» de algo que se entregó en frasco, y ese es un error que el
     * CHECK de la base no puede ver: las dos columnas estarían llenas y
     * la fila sería válida.
     */
    private function envaseDelCargo(Item $item, LineaDeCargo $linea): ?Decimal
    {
        if (! $this->presentacionDelCargo($item, $linea) instanceof ItemPresentacion) {
            return null;
        }

        $envases = $linea->envases;

        if (! $envases instanceof Decimal || $envases->esCero() || $envases->esNegativo()) {
            return null;
        }

        return $envases;
    }

    /**
     * Las presentaciones del ítem con su unidad, para saber de qué lotes
     * puede salir un envase sin abrir. Solo se consultan cuando se vende
     * uno: la tableta suelta no las necesita.
     *
     * @return Collection<int, ItemPresentacion>
     */
    private function presentacionesDe(Item $item): Collection
    {
        return ItemPresentacion::query()
            ->with('unidad')
            ->where('item_id', $item->id)
            ->get();
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
