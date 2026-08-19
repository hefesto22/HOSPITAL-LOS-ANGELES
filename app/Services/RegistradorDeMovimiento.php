<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta por la que se mueve una existencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS ESCRITURAS QUE NO SE PUEDEN SEPARAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada movimiento hace dos cosas en la misma transacción: mueve el saldo
 * y asienta la línea del kardex. Si solo se hiciera lo primero, no habría
 * historia; si solo lo segundo, el mostrador leería un número viejo.
 * Separarlas en dos llamadas es cómo aparece un saldo que no coincide con
 * su propio kardex.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LAS DOS DISPENSACIONES SIMULTÁNEAS DEL ÚLTIMO FRASCO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El descuento NO lee para después decidir. Es un solo
 * `UPDATE ... WHERE cantidad >= :cantidad`: si ya no alcanza, afecta cero
 * filas y termina en excepción.
 *
 * Con la versión ingenua —leer, comparar, restar— dos dispensaciones a la
 * vez leen las dos «hay 1», las dos aprueban, y el estante queda vacío
 * con el sistema diciendo que hay uno. Ese bug no se reproduce probando a
 * mano; solo aparece un martes a las 11 de la mañana.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIEMPRE EN UNIDAD DE DISPENSACIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá entran tabletas, no cajas. La conversión la hace
 * `ItemPresentacion::aUnidadesDeDispensacion()` antes de llamar: el
 * kardex no sabe de envases, y esa es justamente la razón por la que las
 * presentaciones existen.
 */
final class RegistradorDeMovimiento
{
    /**
     * @param Decimal $cantidad siempre POSITIVA: el signo lo pone el tipo
     *
     * @throws ExistenciaInsuficienteException
     */
    public function registrar(
        Item $item,
        ?Lote $lote,
        Almacen $almacen,
        TipoMovimiento $tipo,
        Decimal $cantidad,
        ?string $motivo = null,
        ?string $referencia = null,
        ?CarbonInterface $ocurridoEn = null,
        ?Decimal $costoUnitario = null,
        ?Decimal $costoPromedioDespues = null,
    ): MovimientoKardex {
        $this->verificar($item, $lote, $tipo, $cantidad, $motivo);

        /*
         * El saldo se crea ANTES de la transacción y en cero. Si dos
         * movimientos simultáneos intentan crearlo, uno pierde con el
         * índice único y vuelve a buscar — y un saldo en cero no cambia
         * nada aunque la transacción de después se caiga.
         *
         * Hacerlo adentro obligaría a un SAVEPOINT: en PostgreSQL un
         * INSERT fallido aborta la transacción entera, así que el
         * try/catch de toda la vida no alcanza.
         */
        $saldo = $this->saldoDe($item, $lote, $almacen);

        /** @var MovimientoKardex $movimiento */
        $movimiento = DB::transaction(function () use (
            $item,
            $lote,
            $almacen,
            $tipo,
            $cantidad,
            $motivo,
            $referencia,
            $ocurridoEn,
            $costoUnitario,
            $costoPromedioDespues,
            $saldo,
        ): MovimientoKardex {
            $firmada = $tipo->esEntrada() ? $cantidad : $cantidad->por('-1');

            if ($tipo->esEntrada()) {
                $this->sumar($saldo, $cantidad);
            } else {
                $this->restar($saldo, $cantidad, $item, $almacen);
            }

            /*
             * Se relee DESPUÉS de mover. La fila quedó bloqueada por
             * nuestro propio UPDATE hasta el commit, así que nadie más
             * puede haberla cambiado en el medio.
             */
            $saldo->refresh();

            return MovimientoKardex::query()->create([
                'item_id'       => $item->id,
                'lote_id'       => $lote?->id,
                'almacen_id'    => $almacen->id,
                'tipo'          => $tipo,
                'cantidad'      => $firmada->paraBase(4),
                'saldo_despues' => $saldo->cantidadDecimal()->paraBase(4),
                'motivo'        => $motivo,
                'referencia'    => $referencia,
                'ocurrido_en'   => $ocurridoEn ?? now(),

                /*
                 * Nulos cuando el movimiento no tiene costo asociado: un
                 * traslado, una devolución, o cualquiera anterior a que
                 * el sistema costeara. Nulo significa «no se sabe», que
                 * es la verdad; un cero significaría «costó cero», que no
                 * lo es.
                 */
                'costo_unitario'         => $costoUnitario?->paraBase(6),
                'costo_promedio_despues' => $costoPromedioDespues?->paraBase(6),

                /*
                 * A mano y no con `HasAuditFields`: ese trait también
                 * escribe `updated_by` y `deleted_by`, y acá esas
                 * columnas no existen porque nada se actualiza ni se
                 * borra. Quién lo asentó es lo único que hay que saber —
                 * y en un kardex es de lo más importante que hay.
                 */
                'created_by' => UsuarioAutenticado::id(),
            ]);
        });

        return $movimiento;
    }

    /**
     * Lo que la base no puede verificar sola.
     *
     * El CHECK del signo y el del motivo ya están en la migración. Acá
     * están las dos reglas que cruzan tablas —que el lote sea de ese
     * ítem, que el ítem que exige lote lo traiga— porque un CHECK no
     * puede mirar otra tabla.
     *
     * @throws ExistenciaInsuficienteException
     */
    private function verificar(
        Item $item,
        ?Lote $lote,
        TipoMovimiento $tipo,
        Decimal $cantidad,
        ?string $motivo,
    ): void {
        if ($cantidad->esCero() || $cantidad->esNegativo()) {
            throw ExistenciaInsuficienteException::laCantidadDebeSerPositiva();
        }

        if ($tipo->exigeMotivo() && mb_strlen(trim($motivo ?? '')) < 10) {
            throw ExistenciaInsuficienteException::faltaElMotivo($tipo->etiqueta());
        }

        if ($item->requiere_lote && ! $lote instanceof Lote) {
            throw ExistenciaInsuficienteException::faltaElLote($item->codigo);
        }

        if ($lote instanceof Lote && $lote->item_id !== $item->id) {
            throw ExistenciaInsuficienteException::elLoteNoEsDelItem($item->codigo, $lote->numero);
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ SQL A MANO Y NO `increment()` / `decrement()`
     * ─────────────────────────────────────────────────────────────────
     *
     * Los helpers de Eloquent declaran `float|int` en el monto. En
     * runtime aceptan el numeric-string —les basta con `is_numeric`—
     * pero el contrato pide float, y **convertir la cantidad a float es
     * exactamente lo que el §8.6.2 prohíbe**: con `precision=14`, un
     * saldo grande pierde dígitos al pasar por punto flotante, y acá se
     * están contando ampollas.
     *
     * Así que la sentencia va escrita, con la cantidad **ligada como
     * parámetro** —no interpolada— para que PostgreSQL la reciba como
     * `numeric` exacto. Es una línea más de SQL a cambio de no meter un
     * float en el kardex.
     */
    private function sumar(Existencia $saldo, Decimal $cantidad): void
    {
        DB::update(
            'update existencias set cantidad = cantidad + ?, updated_at = ? where id = ?',
            [$cantidad->paraBase(4), now()->toDateTimeString(), $saldo->id],
        );
    }

    /**
     * El descuento y su condición, en una sola sentencia.
     *
     * `where cantidad >= ?` dentro del mismo `UPDATE` es lo que hace
     * segura la dispensación simultánea: si ya no alcanza, afecta cero
     * filas. Leer primero y decidir después deja pasar las dos.
     *
     * @throws ExistenciaInsuficienteException
     */
    private function restar(
        Existencia $saldo,
        Decimal $cantidad,
        Item $item,
        Almacen $almacen,
    ): void {
        $pedida = $cantidad->paraBase(4);

        $afectadas = DB::update(
            'update existencias set cantidad = cantidad - ?, updated_at = ? '
            .'where id = ? and cantidad >= ?',
            [$pedida, now()->toDateTimeString(), $saldo->id, $pedida],
        );

        if ($afectadas === 0) {
            $saldo->refresh();

            throw ExistenciaInsuficienteException::paraSalida(
                $item->codigo,
                $almacen->nombre,
                $cantidad->redondeado(4),
                $saldo->cantidadDecimal()->redondeado(4),
            );
        }
    }

    /**
     * La fila del saldo, creándola en cero si es la primera vez.
     */
    private function saldoDe(Item $item, ?Lote $lote, Almacen $almacen): Existencia
    {
        $buscar = fn (): ?Existencia => Existencia::query()
            ->where('item_id', $item->id)
            ->where(fn (Builder $sub): Builder => $lote instanceof Lote
                ? $sub->where('lote_id', $lote->id)
                : $sub->whereNull('lote_id'))
            ->where('almacen_id', $almacen->id)
            ->first();

        $saldo = $buscar();

        if ($saldo instanceof Existencia) {
            return $saldo;
        }

        try {
            return Existencia::query()->create([
                'item_id'    => $item->id,
                'lote_id'    => $lote?->id,
                'almacen_id' => $almacen->id,
                'cantidad'   => '0',
            ]);
        } catch (QueryException $e) {
            /*
             * Otro movimiento simultáneo lo creó primero. El índice único
             * hizo su trabajo; buscamos el que ganó.
             */
            $saldo = $buscar();

            if ($saldo instanceof Existencia) {
                return $saldo;
            }

            throw $e;
        }
    }
}
