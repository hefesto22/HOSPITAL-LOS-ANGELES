<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\PreciosPorForma;
use App\Models\Item;
use App\Models\PrecioMinimo;
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
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS PUERTAS: LA PERSONA Y EL AJUSTE AUTOMÁTICO (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · `fijar()` — alguien escribió un mínimo. Además de las filas, deja
 *     guardado ese mínimo y sus recargos (`PrecioMinimo`): es la
 *     política de la que van a salir los ajustes que vengan.
 *   · `reajustar()` — el sistema recalculó desde esa política porque
 *     cambió un envase o el descuento por edad. Escribe las filas, con su
 *     porqué, y NO toca la política: nadie decidió un mínimo nuevo.
 */
final class FijadorDePreciosPorForma
{
    /**
     * Lo que separa el porqué de la persona del origen de los números.
     */
    private const SEPARADOR = ' — Mínimo ';

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
        /*
         * `DB::transaction()` está declarado devolviendo `mixed`.
         *
         * @var list<Tarifario> $filas
         */
        $filas = DB::transaction(function () use ($item, $precios, $sede, $motivo, $desde): array {
            $filas = $this->escribir($item, $precios, $sede, $motivo, $desde);

            $this->guardarElMinimo($item, $precios, $sede, $motivo, $desde);

            return $filas;
        });

        return $filas;
    }

    /**
     * Las mismas filas que `fijar()`, sin tocar el mínimo guardado: lo que
     * cambió fue el envase o la ley, no la decisión de cuánto es lo mínimo.
     *
     * @return list<Tarifario>
     *
     * @throws PrecioNoFijableException
     */
    public function reajustar(
        Item $item,
        PreciosPorForma $precios,
        ?Sede $sede,
        string $motivo,
        CarbonInterface $desde,
    ): array {
        /*
         * @var list<Tarifario> $filas
         */
        $filas = DB::transaction(fn (): array => $this->escribir($item, $precios, $sede, $motivo, $desde));

        return $filas;
    }

    /**
     * @return list<Tarifario>
     *
     * @throws PrecioNoFijableException
     */
    private function escribir(
        Item $item,
        PreciosPorForma $precios,
        ?Sede $sede,
        string $motivo,
        CarbonInterface $desde,
    ): array {
        $corregidas = $this->retirarLasDeHoy($item, $sede, $desde);
        $motivo = $this->motivoCompleto($motivo, $precios, corrige: $corregidas > 0);

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
    }

    /**
     * El mínimo que se escribió, con sus recargos, desde ese día.
     *
     * El del mismo día se reemplaza —es el error de tecleo que se está
     * corrigiendo, y una política no la cita ningún cobro— y el vigente
     * se cierra el día anterior, como cualquier fila con vigencia.
     */
    private function guardarElMinimo(
        Item $item,
        PreciosPorForma $precios,
        ?Sede $sede,
        string $motivo,
        CarbonInterface $desde,
    ): void {
        $principal = $precios->laPrincipal();

        if ($principal === null) {
            return;
        }

        $dia = $desde->copy()->startOfDay();
        $sedeId = $sede?->id;

        $deLaLlave = fn () => PrecioMinimo::query()
            ->where('item_id', $item->id)
            ->when(
                $sedeId === null,
                fn ($consulta) => $consulta->whereNull('sede_id'),
                fn ($consulta) => $consulta->where('sede_id', $sedeId),
            );

        foreach ($deLaLlave()->whereDate('vigencia_desde', '>=', $dia->toDateString())->lockForUpdate()->get() as $mismoDia) {
            $mismoDia->delete();
        }

        $vigente = $deLaLlave()->vigentesEn($dia)->lockForUpdate()->first();

        if ($vigente instanceof PrecioMinimo) {
            $vigente->update(['vigencia_hasta' => $dia->copy()->subDay()->toDateString()]);
        }

        $porQue = trim($motivo);

        PrecioMinimo::query()->create([
            'item_id'              => $item->id,
            'sede_id'              => $sedeId,
            'item_presentacion_id' => $principal->presentacion?->id,
            'minimo'               => $principal->minimo->cantidad()->paraBase(4),
            'contenido'            => $principal->contenido->paraBase(4),
            'recargo_envase'       => $precios->recargoEnvase->paraBase(4),
            'recargo_suelta'       => $precios->recargoSuelta->paraBase(4),
            'motivo'               => mb_strlen($porQue) >= 10 ? mb_substr($porQue, 0, 255) : 'PRECIO FIJADO DESDE LA FICHA',
            'vigencia_desde'       => $dia->toDateString(),
            'vigencia_hasta'       => null,
        ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL ERROR DEL MISMO DÍA SE CORRIGE (13-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «Cambiar el precio» dos veces el mismo día chocaba con lo que se
     * fijó a la mañana: `FijadorDePrecio` no deja meter una fila que
     * arranque el mismo día que otra. Si las de hoy todavía no se usaron
     * para cobrar ni para presupuestar, se retiran y quedan las nuevas —
     * igual que en la ficha de servicios—. Si con alguna ya se cobró, no
     * se toca nada: esa ya es historia que una factura cita, y el cambio
     * nuevo va desde mañana.
     *
     * @return int cuántas se retiraron
     *
     * @throws PrecioNoFijableException
     */
    private function retirarLasDeHoy(Item $item, ?Sede $sede, CarbonInterface $desde): int
    {
        $deHoy = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->when(
                $sede instanceof Sede,
                fn ($consulta) => $consulta->where('sede_id', $sede?->id),
                fn ($consulta) => $consulta->whereNull('sede_id'),
            )
            ->whereDate('vigencia_desde', $desde->toDateString())
            ->lockForUpdate()
            ->get();

        foreach ($deHoy as $fila) {
            if ($fila->yaSeUso()) {
                throw PrecioNoFijableException::yaSeUsoHoy($item->nombre, 'el precio de lista', $fila->monto()->formateado());
            }
        }

        foreach ($deHoy as $fila) {
            $fila->delete();
        }

        return $deHoy->count();
    }

    /**
     * El porqué que escribió la persona, y al lado de dónde salieron los
     * números: «Mínimo L. 120.00 por CAJA X 100; blíster +10 %, suelta
     * +25 %». Dentro de dos años es lo único que explica un L 2.50.
     *
     * ⚠️ El porqué se recorta ANTES de pegarle el origen, no después: la
     * columna admite 255 y, cortado al final, se perdía justo el mínimo
     * —lo único que `origenDe()` necesita para volver a calcular—.
     */
    private function motivoCompleto(string $motivo, PreciosPorForma $precios, bool $corrige = false): string
    {
        $principal = $precios->laPrincipal();

        $origen = $principal === null
            ? ''
            : sprintf(
                self::SEPARADOR.'%s por %s; envase más chico +%s, suelta +%s.',
                $principal->minimo->formateado(),
                $principal->rotulo,
                $precios->recargoEnvase->comoPorcentaje(),
                $precios->recargoSuelta->comoPorcentaje(),
            );

        $porQue = ($corrige ? 'CORREGIDO EL MISMO DÍA. ' : '').trim($motivo);

        return mb_substr($porQue, 0, max(10, 255 - mb_strlen($origen))).$origen;
    }

    /**
     * El mínimo y los recargos de los que salió un precio, leídos de su
     * motivo — el texto que escribe `motivoCompleto()`, en esta misma
     * clase, para que el formato y quien lo lee no se separen nunca.
     *
     * Sirve para dos cosas: que «Cambiar el precio» arranque con el
     * mínimo que se escribió la vez pasada (L 220.00, no los L 220.20 que
     * paga la cuarta edad después de redondear la lista), y que la ficha
     * avise si la ley de hoy hace pagar menos que ese mínimo.
     *
     * `null` si el precio no salió de «Fijar el precio de venta».
     *
     * @return array{minimo: Decimal, envase: Decimal, suelta: Decimal}|null
     */
    public static function origenDe(?string $motivo): ?array
    {
        if ($motivo === null) {
            return null;
        }

        $patron = '/'.preg_quote(trim(self::SEPARADOR), '/')
            .'\s*L\.?\s*([\d,]+(?:\.\d+)?)\s+por\s+.+?;\s*envase más chico \+(\d+(?:\.\d+)?)\s*%,\s*suelta \+(\d+(?:\.\d+)?)\s*%/iu';

        if (preg_match($patron, $motivo, $partes) !== 1) {
            return null;
        }

        return [
            'minimo' => Decimal::de(str_replace(',', '', $partes[1])),
            'envase' => Decimal::de($partes[2])->entre('100'),
            'suelta' => Decimal::de($partes[3])->entre('100'),
        ];
    }

    /**
     * Solo lo que escribió la persona, sin el origen que se le pega al
     * final: en la ficha el mínimo ya se ve en la tabla.
     */
    public static function soloElPorque(?string $motivo): ?string
    {
        if ($motivo === null) {
            return null;
        }

        $donde = mb_stripos($motivo, trim(self::SEPARADOR));
        $porQue = trim($donde === false ? $motivo : mb_substr($motivo, 0, $donde));

        return $porQue === '' ? null : $porQue;
    }
}
