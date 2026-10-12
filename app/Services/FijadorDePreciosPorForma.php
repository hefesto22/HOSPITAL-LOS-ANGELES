<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Models\Item;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Escribe de una vez el precio de cada forma de venta del producto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ FILA LLEVA CADA FORMA
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · La unidad suelta, la fila SIN envase: `precio` = la tableta.
 *   · Cada envase, su fila: `precio` = lo que vale cada unidad adentro,
 *     y `precio_envase` = el envase entero, exacto. Es el que se cobra
 *     cuando sale sin abrir (`CalculadoraDeCargo`).
 *
 * Todas en una transacción y con la misma fecha: el blíster nuevo con la
 * caja vieja sería un día en que el blíster sale más barato por tableta
 * que la caja.
 *
 * Cada una pasa por `FijadorDePrecio`, que cierra la vigente de su llave
 * el día anterior. Ninguna se edita.
 */
final class FijadorDePreciosPorForma
{
    public function __construct(
        private readonly FijadorDePrecio $precios,
    ) {}

    /**
     * @return list<Tarifario>
     *
     * @throws PrecioNoFijableException
     */
    public function fijar(
        Item $item,
        PreciosPorForma $precios,
        ?Sede $sede,
        string $motivo,
        CarbonInterface $desde,
    ): array {
        $motivo = $this->motivoCompleto($motivo, $precios);

        /*
         * `DB::transaction()` está declarado devolviendo `mixed`.
         *
         * @var list<Tarifario> $filas
         */
        $filas = DB::transaction(function () use ($item, $precios, $sede, $motivo, $desde): array {
            $filas = [];

            foreach ($precios->formas as $forma) {
                $filas[] = $this->precios->fijar(
                    item: $item,
                    convenio: null,
                    sede: $sede,
                    precio: Monto::de($forma->listaPorUnidad()),
                    motivo: $motivo,
                    desde: $desde,
                    presentacion: $forma->presentacion,
                    precioEnvase: $forma->esLaSuelta() ? null : $forma->lista,
                );
            }

            return $filas;
        });

        return $filas;
    }

    /**
     * El porqué que escribió la persona, y al lado de dónde salieron los
     * números: «Mínimo L. 120.00 por CAJA X 100; blíster +10 %, suelta
     * +25 %». Dentro de dos años es lo único que explica un L 2.50.
     */
    private function motivoCompleto(string $motivo, PreciosPorForma $precios): string
    {
        $principal = $precios->laPrincipal();

        $origen = $principal === null
            ? ''
            : sprintf(
                ' — Mínimo %s por %s; envase más chico +%s, suelta +%s.',
                $principal->minimo->formateado(),
                $principal->rotulo,
                $precios->recargoEnvase->comoPorcentaje(),
                $precios->recargoSuelta->comoPorcentaje(),
            );

        return mb_substr(trim($motivo).$origen, 0, 255);
    }
}
