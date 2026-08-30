<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoAlmacen;
use App\Models\Almacen;
use App\Models\Sede;

/**
 * El código de un almacén sale de su tipo y de un número corrido:
 * BOD-01, FAR-01, SRV-01, SRV-02.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO SE PIDE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Nadie de afuera lo audita. Sirve para reconocer el estante en un
 * kardex, en una etiqueta y en el desplegable de «¿de dónde sale?».
 * Pedirlo era pedirle a quien crea el carrito que invente una convención
 * y después la recuerde — y a la tercera persona que crea uno, la
 * convención ya no existe: aparecen AM-1, CARRITO2 y CR_ROJO_1 en la
 * misma lista.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ CORRELATIVO Y NO DERIVADO DEL NOMBRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Al revés que en las plantillas de presupuesto, donde el nombre ES lo
 * que se busca. Acá los nombres se parecen entre sí —CARRITO ROJO 1,
 * CARRITO ROJO 2, CARRITO AZUL— y una raíz derivada del nombre daría
 * CARRITO, CARRITO-2, CARRITO-3: el mismo número corrido, pero
 * disfrazado y más largo.
 *
 * El prefijo por tipo sí agrega algo: leyendo SRV-02 en un movimiento
 * viejo ya se sabe que salió del estante de un servicio y no de la
 * bodega, sin abrir nada. El nombre completo va al lado en `etiqueta()`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL NÚMERO ES POR SEDE, COMO EL ÍNDICE ÚNICO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `almacenes` es único por `(sede_id, codigo)`, así que dos sedes pueden
 * tener las dos su SRV-01 sin chocar. Contar globalmente daría códigos
 * salteados en la segunda sede —SRV-04 siendo el primero de esa sede— y
 * eso se lee como si faltaran tres carritos.
 */
final class AsignadorDeCodigoDeAlmacen
{
    /**
     * Cuántos números se prueban antes de rendirse. El tope está para que
     * el ciclo termine siempre, no porque un hospital vaya a tener
     * noventa y nueve carritos.
     */
    private const INTENTOS = 99;

    public function siguiente(TipoAlmacen $tipo, Sede|int|null $sede): string
    {
        $prefijo = self::prefijoDe($tipo);
        $sedeId = $sede instanceof Sede ? $sede->id : $sede;

        for ($n = 1; $n <= self::INTENTOS; $n++) {
            $candidato = $prefijo.'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            if (! $this->existe($candidato, $sedeId)) {
                return $candidato;
            }
        }

        /*
         * El último recurso: la hora. Feo, pero único, y prefiero un
         * código feo a un error de índice único en la cara de quien está
         * creando el carrito.
         */
        return $prefijo.'-'.now()->format('His');
    }

    /**
     * Tres letras que se leen solas en un movimiento de hace seis meses.
     */
    public static function prefijoDe(TipoAlmacen $tipo): string
    {
        return match ($tipo) {
            TipoAlmacen::AlmacenUnico    => 'ALM',
            TipoAlmacen::BodegaCentral   => 'BOD',
            TipoAlmacen::FarmaciaVenta   => 'FAR',
            TipoAlmacen::FarmaciaInterna => 'FIN',
            TipoAlmacen::StockDeServicio => 'SRV',
        };
    }

    /**
     * ⚠️ `withTrashed()`: un almacén borrado sigue ocupando su código
     * mientras el índice único no lo excluya, y sobre todo sigue teniendo
     * movimientos que lo nombran. Reusar el código de un estante dado de
     * baja haría que dos historias distintas se lean como una.
     */
    private function existe(string $codigo, ?int $sedeId): bool
    {
        $consulta = Almacen::withTrashed()->where('codigo', $codigo);

        if ($sedeId !== null) {
            $consulta->where('sede_id', $sedeId);
        }

        return $consulta->exists();
    }
}
