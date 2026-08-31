<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\CargoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\MovimientoKardex;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Corregir un cargo mal puesto — sin borrar nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS FILAS, NUNCA UNA CORRECCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El cargo original queda con su monto y pasa a `anulado`; se asienta al
 * lado un cargo de REVERSA con los montos en negativo que lo apunta. Las
 * dos filas suman cero, la cuenta cuadra sola y ninguna se editó — que
 * es lo que el §9.0.3 exige y lo que un trigger de la base impone de
 * todos modos.
 *
 * Es exactamente el mismo mecanismo del kardex, y por la misma razón: el
 * día que un abogado pida «la cuenta como estaba el 12 de marzo», una
 * tabla mutable convierte al hospital en indefendible.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL INVENTARIO VUELVE, PERO NO SIEMPRE Y NO SOLO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si el cargo movió el kardex, la reversa asienta el movimiento opuesto
 * contra el MISMO lote. Que la existencia vuelva a estar disponible es
 * correcto acá porque anular un cargo significa «esto no se consumió».
 *
 * ⚠️ Lo que NO cubre esto es la devolución física de algo ya entregado:
 * §9.F10 es tajante en que un vial reconstituido, una jeringa preparada
 * o una bolsa de infusión mezclada jamás regresan al inventario. Esa
 * distinción vive en el módulo de devoluciones del bloque 6, y hasta
 * entonces anular es para el error de captura, no para el producto que
 * ya salió del carro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * FACTURADO NO SE ANULA ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un cargo ya facturado se corrige con nota de crédito, que consume su
 * propio CAI (§8.6.4). Eso es el bloque 7, hoy bloqueado por las
 * consultas al SAR (§8.11-1, §8.11-4). Este servicio se niega a tocarlo
 * en vez de fingir que puede.
 */
final class AnuladorDeCargo
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
    ) {}

    /**
     * @throws CargoException
     */
    public function anular(Cargo $cargo, string $motivo): Cargo
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw CargoException::motivoDeAnulacionCorto();
        }

        if (! $cargo->admiteAnulacionDirecta()) {
            throw CargoException::yaNoSeAnula($cargo->estado->etiqueta());
        }

        $cargo->loadMissing(['cuenta', 'item', 'lote', 'almacen']);

        /** @var Cargo $reversa */
        $reversa = DB::transaction(function () use ($cargo, $motivo): Cargo {
            $cuenta = Cuenta::query()
                ->whereKey($cargo->cuenta_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Se relee el cargo dentro de la transacción: entre que la
             * pantalla lo mostró y que llegó el clic, alguien pudo
             * facturarlo. Sin esta relectura se anularía un cargo que ya
             * está dentro de un documento fiscal.
             */
            $vigente = Cargo::query()
                ->whereKey($cargo->id)
                ->where('fecha_operacion', $cargo->fecha_operacion->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $vigente->admiteAnulacionDirecta()) {
                throw CargoException::yaNoSeAnula($vigente->estado->etiqueta());
            }

            $movimiento = $this->devolverAlEstante($vigente);

            $reversa = Cargo::query()->forceCreate(
                array_merge($this->negar($vigente), [
                    'estado'             => EstadoCargo::Anulacion->value,
                    'revierte_a_id'      => $vigente->id,
                    'motivo_anulacion'   => $motivo,
                    'movimiento_id'      => $movimiento instanceof MovimientoKardex ? $movimiento->id : null,
                    'registrado_en'      => now(),
                    'clave_origen'       => $vigente->clave_origen,
                    'clave_idempotencia' => Uuid::uuid5(
                        Uuid::NAMESPACE_OID,
                        'reversa:'.$vigente->id,
                    )->toString(),
                    'created_by' => auth()->id(),
                ])
            );

            $vigente->forceFill([
                'estado'           => EstadoCargo::Anulado->value,
                'motivo_anulacion' => $motivo,
                'updated_by'       => auth()->id(),
            ])->save();

            $totales = $cuenta->recalcular();
            $cuenta->forceFill($totales);
            $cuenta->save();

            return $reversa;
        });

        return $reversa;
    }

    /**
     * El movimiento opuesto, contra el mismo lote y el mismo almacén.
     */
    private function devolverAlEstante(Cargo $cargo): ?MovimientoKardex
    {
        if ($cargo->movimiento_id === null) {
            return null;
        }

        /*
         * Se pregunta por la COLUMNA y no con `instanceof` sobre la
         * relación: Larastan tipa los BelongsTo como no nulos, así que un
         * `instanceof` ahí es una condición que el analizador da siempre
         * por verdadera (§9.B1).
         */
        if ($cargo->almacen_id === null) {
            return null;
        }

        $item = $cargo->item;
        $almacen = $cargo->almacen;
        $lote = $cargo->lote_id === null ? null : $cargo->lote;

        /*
         * 🔴 ORDEN CANÓNICO DE CANDADOS: costo → existencia.
         *
         * Es el mismo orden que toma `RegistradorDeCargo`, y tiene que
         * serlo. Si acá se tomara al revés —existencia primero y costo
         * después— una dispensación y una anulación simultáneas del mismo
         * producto quedarían cruzadas: cada una con el candado que la
         * otra pide. PostgreSQL aborta una con 40P01 y el error 500 le
         * sale en la cara a quien estaba cobrando.
         *
         * La lectura del costo acá no se usa para nada más que tomar el
         * candado en el orden correcto: la reversa se asienta con el
         * costo CONGELADO del cargo original, no con el promedio de hoy.
         */
        $this->costos->vigenteBloqueado($item, $almacen);

        $movimiento = $this->movimientos->registrar(
            item: $item,
            lote: $lote,
            almacen: $almacen,
            tipo: TipoMovimiento::EntradaPorDevolucion,
            cantidad: $cargo->cantidadDecimal(),
            motivo: 'Anulación del cargo '.$cargo->id,
            referencia: (string) $cargo->id,
            ocurridoEn: now(),
            costoUnitario: $cargo->costo_unitario === null
                ? null
                : Decimal::de($cargo->costo_unitario),
        );

        $this->costos->sincronizarCantidadBase($item, $almacen);

        return $movimiento;
    }

    /**
     * El snapshot del original, con los montos y la cantidad en
     * negativo.
     *
     * Se copia entero y no se recalcula: la reversa tiene que cancelar
     * EXACTAMENTE lo que se cobró, con el precio y la cobertura de ese
     * día. Recalcularla con el tarifario de hoy dejaría un residuo cada
     * vez que se corrige algo después de un cambio de precios.
     *
     * @return array<string, mixed>
     */
    private function negar(Cargo $cargo): array
    {
        $columnas = $cargo->only([
            'fecha_operacion', 'sede_id', 'cuenta_id', 'encuentro_id', 'item_id',
            'servicio_id', 'medico_id', 'almacen_id', 'lote_id', 'unidad_id', 'ocurrido_en',
            'item_presentacion_id',
            'convenio_id', 'tarifario_id', 'condicion_id', 'origen_precio',
            'precio_unitario', 'factor_convenio', 'categoria_legal',
            'descuento_legal_fraccion', 'base_descuento_legal', 'regimen_isv',
            'tasa_isv', 'cobertura_fraccion', 'elegible', 'politica_cargo',
            'motivo_descuento', 'autorizado_por', 'costo_unitario', 'es_tardio',
        ]);

        /*
         * ⚠️ LA REVERSA SE FECHA HOY, NO EN EL DÍA DEL CARGO ORIGINAL.
         *
         * Es la decisión que mantiene coherentes las dos mitades: el
         * movimiento de kardex de la devolución ocurre hoy, así que si la
         * reversa se fechara en el día original, el consumo de agosto y
         * el costo de ventas de agosto dejarían de cuadrar entre sí —y
         * peor: el corte de caja y el ISV de un mes ya declarado
         * cambiarían retroactivamente (§7.5-4, §8.7-9).
         *
         * La corrección es un hecho de hoy. El enlace al día original
         * vive en `revierte_a_id`, que es lo que permite reconstruir la
         * historia sin reescribir un periodo cerrado.
         */
        $columnas['fecha_operacion'] = now()->toDateString();
        $columnas['ocurrido_en'] = now();
        $columnas['origen_precio'] = $cargo->origen_precio->value;
        $columnas['regimen_isv'] = $cargo->regimen_isv->value;
        $columnas['politica_cargo'] = $cargo->politica_cargo->value;
        $columnas['categoria_legal'] = $cargo->categoria_legal?->value;
        $columnas['texto'] = mb_substr('Reversa · '.$cargo->texto, 0, 200);

        foreach ([
            'cantidad' => 4,

            /*
             * 🔴 Los envases también se niegan. Sin esto la reversa
             * copiaba «de qué envase» pero no «cuántos», y el CHECK
             * `cargos_envase_completo` la rechazaba: quitar un renglón
             * moría con un error de base de datos en la cara del que
             * estaba cobrando.
             *
             * Y tienen que ser negativos, no positivos: el par original +
             * reversa suma cero envases, que es lo que hace que el
             * renglón desaparezca de la cuenta sin borrar nada.
             */
            'cantidad_presentacion' => 4,

            'descuento_legal'     => 2,
            'descuento_comercial' => 2,
            'bruto'               => 2,
            'subtotal'            => 2,
            'base_exenta'         => 2,
            'base_gravada'        => 2,
            'isv'                 => 2,
            'total'               => 2,
            'porcion_paciente'    => 2,
            'porcion_aseguradora' => 2,
            'costo_total'         => 2,
        ] as $columna => $decimales) {
            $valor = $cargo->getAttribute($columna);

            $columnas[$columna] = $valor === null
                ? null
                : Decimal::de((string) $valor)->por('-1')->redondeado($decimales);
        }

        return $columnas;
    }
}
