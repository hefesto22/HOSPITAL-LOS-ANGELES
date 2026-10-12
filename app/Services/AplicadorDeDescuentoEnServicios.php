<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\CuentaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\Monto;
use App\Models\Cargo;
use App\Models\Cuenta;
use App\Models\Item;
use App\Support\TextoCanonico;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ─────────────────────────────────────────────────────────────────────
 * EL DESCUENTO DEL HOSPITAL EN LO QUE NO SALE DE FARMACIA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «si se aplica un descuento por monto o por porcentaje, se
 * aplicaría a todo lo que no sea materiales y medicamentos, ya que ese
 * es descuento exclusivo de medicamentos y materiales».
 *
 * Es el hermano del descuento de farmacia de la cuenta, con tres
 * diferencias:
 *
 *   · Va en porcentaje O en lempiras. «Rebájele L 500 en servicios» se
 *     reparte entre los renglones en proporción a lo que vale cada uno,
 *     y el residuo de redondeo cae en el más grande (§8.6.2-4).
 *   · Cada renglón se topa con SU categoría del Art. 30
 *     (`PoliticaDeDescuentoComercial::topeDeLaCategoria()`): la
 *     alimentación aguanta 20 % a un joven y el laboratorio 25 %. En
 *     porcentaje, el renglón que no aguanta lo pedido queda en su tope; en
 *     lempiras, lo que un renglón no aguanta se pasa a los otros, y si
 *     entre todos no alcanza, no se aplica y se dice hasta cuánto se puede.
 *   · Lo que entra después también lo lleva. En porcentaje, la línea
 *     nueva nace con su parte; en lempiras, el monto se vuelve a repartir
 *     entre todos, porque «L 500 en servicios» sigue siendo L 500.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE NO TOCA, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Lo de farmacia (`se_almacena`): tiene su propio descuento.
 *   · Los honorarios médicos: es plata del médico. Rebajarla desde caja
 *     es regalar algo que no es del hospital, y la liquidación al doctor
 *     saldría menor sin que él lo decidiera.
 *   · El paquete quirúrgico y lo que va adentro: tiene un precio
 *     acordado con la familia, y su única rebaja es la de los
 *     medicamentos entregados (ADR-0009).
 *   · Lo que no se cobra (`IncluidoEnTarifa`, `GastoDelServicio`) y lo
 *     que ya se facturó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REHACER ES REHACER IGUAL (§9.M-12)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un renglón asentado no se edita: se anula y se vuelve a cargar con la
 * rebaja, y la bitácora lo cuenta. Vuelve con la misma fecha del
 * servicio —el precio se resuelve por esa fecha (§8.5-3) y la
 * alimentación del lunes sigue siendo del lunes—, el mismo médico, el
 * mismo servicio y el mismo texto. Solo se rehace lo que no tiene ya lo
 * que le toca: salir del campo dos veces no anula la cuenta entera.
 */
final class AplicadorDeDescuentoEnServicios
{
    /**
     * Topes ya leídos en esta vuelta, por cuenta, categoría y día.
     *
     * Cada tope lee la edad del paciente y la tabla de la ley; repartir
     * entre veinte renglones o dibujar la banda en cada render lo pediría
     * veinte veces para tres o cuatro respuestas distintas (§13.2).
     *
     * @var array<string, Decimal>
     */
    private array $topes = [];

    public function __construct(
        private readonly PoliticaDeDescuentoComercial $politica,
        private readonly AnuladorDeCargo $anulador,
        private readonly RegistradorDeCargo $registrador,
    ) {}

    /**
     * ¿Este ítem entra en el descuento de servicios?
     */
    public static function aplicaAlItem(Item $item): bool
    {
        return ! $item->se_almacena
            && $item->tipo !== TipoItem::Honorario
            && $item->tipo !== TipoItem::Paquete;
    }

    /**
     * Fija el descuento en la cuenta y deja sus renglones al día.
     *
     * Uno de los dos: `$fraccion` (0.10 = 10 %) o `$monto` en lempiras.
     *
     * @return list<string> los renglones que quedaron en su tope, para decirlo en pantalla
     *
     * @throws CuentaException
     */
    public function fijar(Cuenta $cuenta, ?Decimal $fraccion, ?Decimal $monto, string $motivo, ?int $quien): array
    {
        if (($fraccion === null) === ($monto === null)) {
            throw CuentaException::descuentoEnServiciosEnUnaForma();
        }

        $pedido = $fraccion ?? $monto;

        if ($pedido === null
            || $pedido->esCero()
            || $pedido->esNegativo()
            || ($fraccion instanceof Decimal && $fraccion->mayorQue('1'))) {
            throw CuentaException::descuentoEnServiciosInvalido();
        }

        $motivo = TextoCanonico::parrafoEnMayusculas($motivo) ?? '';

        if (mb_strlen($motivo) < 10) {
            throw CuentaException::faltaElMotivoDelDescuento();
        }

        /** @var list<string> $topadas */
        $topadas = DB::transaction(function () use ($cuenta, $fraccion, $monto, $motivo, $quien): array {
            $bloqueada = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estado->estaViva()) {
                throw CuentaException::noAdmiteCambios($bloqueada->numero, $bloqueada->estado->etiqueta());
            }

            /*
             * El monto se reparte ANTES de guardarlo: si entre todos los
             * renglones no alcanza, la cuenta queda como estaba y el
             * mensaje dice hasta cuánto se puede.
             */
            if ($monto instanceof Decimal) {
                $this->repartir($bloqueada, $this->lineasDe($bloqueada), $monto);
            }

            $bloqueada->forceFill([
                'descuento_servicios'        => $fraccion?->redondeado(4),
                'descuento_servicios_monto'  => $monto?->redondeado(2),
                'motivo_descuento_servicios' => $motivo,
                'descuento_servicios_por'    => $quien,
                'descuento_servicios_en'     => now(),
            ])->save();

            return $this->ponerAlDia($bloqueada);
        });

        return $topadas;
    }

    /**
     * Quita el descuento y devuelve los renglones a precio lleno.
     */
    public function quitar(Cuenta $cuenta): void
    {
        DB::transaction(function () use ($cuenta): void {
            $bloqueada = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            $bloqueada->forceFill([
                'descuento_servicios'        => null,
                'descuento_servicios_monto'  => null,
                'motivo_descuento_servicios' => null,
                'descuento_servicios_por'    => null,
                'descuento_servicios_en'     => null,
            ])->save();

            $this->ponerAlDia($bloqueada);
        });
    }

    /**
     * Rehace los renglones que no tienen lo que les toca.
     *
     * Se llama al fijar, al quitar, y —con el descuento en lempiras— cada
     * vez que entra o sale un servicio, porque el reparto cambia.
     *
     * @return list<string> los renglones que quedaron en su tope
     */
    public function ponerAlDia(Cuenta $cuenta): array
    {
        /** @var list<string> $topadas */
        $topadas = DB::transaction(function () use ($cuenta): array {
            $lineas = $this->lineasDe($cuenta);
            $objetivos = $this->objetivos($cuenta, $lineas);
            $topadas = [];

            foreach ($lineas as $cargo) {
                $objetivo = $objetivos[$cargo->id] ?? ['monto' => Decimal::cero(), 'topada' => false];

                if ($objetivo['topada']) {
                    $topadas[] = $cargo->texto;
                }

                if (Decimal::de($cargo->descuento_comercial)->igualA($objetivo['monto']->redondeado(2))) {
                    continue;
                }

                $this->rehacer($cuenta, $cargo, $objetivo['monto']);
            }

            return $topadas;
        });

        return $topadas;
    }

    /**
     * La rebaja con la que nace una línea nueva, en porcentaje.
     *
     * Nula si la cuenta no tiene descuento en servicios, si está en
     * lempiras —ahí se reparte después con `ponerAlDia()`— o si el ítem
     * no entra. Topada con la categoría del ítem: al laboratorio de un
     * joven el 30 % pedido le llega como 25 %.
     */
    public function fraccionParaLaLineaNueva(Cuenta $cuenta, Item $item, CarbonInterface $fecha): ?Decimal
    {
        if ($cuenta->descuento_servicios === null || ! self::aplicaAlItem($item)) {
            return null;
        }

        $pedida = Decimal::de($cuenta->descuento_servicios);
        $tope = $this->topeEnLaCategoria($cuenta, $item->categoria_legal_descuento, $fecha);
        $aplica = $pedida->mayorQue($tope) ? $tope : $pedida;

        return $aplica->esCero() ? null : $aplica;
    }

    /**
     * Hasta cuánto se le puede rebajar a este paciente, por categoría.
     *
     * Para que la pantalla lo diga antes de que alguien teclee un número
     * que después se recorta. Solo las categorías que de verdad aparecen
     * en el hospital, en el orden en que se cobran más seguido.
     *
     * @return list<array{categoria: CategoriaLegalDeDescuento, tope: Decimal}>
     */
    public function topesDe(Cuenta $cuenta, CarbonInterface $fecha): array
    {
        $categorias = [
            CategoriaLegalDeDescuento::ServicioHospitalario,
            CategoriaLegalDeDescuento::ConsultaGeneral,
            CategoriaLegalDeDescuento::ConsultaEspecializada,
            CategoriaLegalDeDescuento::IntervencionQuirurgica,
            CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
            CategoriaLegalDeDescuento::SinDescuentoLegal,
        ];

        return array_map(
            fn (CategoriaLegalDeDescuento $categoria): array => [
                'categoria' => $categoria,
                'tope'      => $this->topeEnLaCategoria($cuenta, $categoria, $fecha),
            ],
            $categorias,
        );
    }

    /**
     * Cuánto se le puede rebajar en lempiras a lo que ya está cargado.
     */
    public function maximoEnLempiras(Cuenta $cuenta): Decimal
    {
        $maximo = Decimal::cero();

        foreach ($this->lineasDe($cuenta) as $cargo) {
            $maximo = $maximo->sumar($this->topeEnLempiras($cuenta, $cargo));
        }

        return $maximo;
    }

    /**
     * Los renglones a los que alcanza el descuento.
     *
     * @return Collection<int, Cargo>
     */
    public function lineasDe(Cuenta $cuenta): Collection
    {
        return $cuenta->cargos()
            ->with(['item', 'almacen', 'lote', 'presentacion'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Cargo $cargo): bool => $cargo->admiteAnulacionDirecta()
                && $cargo->item instanceof Item
                && self::aplicaAlItem($cargo->item)
                && $cargo->presupuesto_id === null
                && $cargo->origen_precio !== OrigenDelPrecio::Presupuestado
                && $cargo->politica_cargo === PoliticaCargo::Cobrable
                && Decimal::de($cargo->bruto)->mayorQue('0'))
            ->values();
    }

    /**
     * Lo que le toca a cada renglón, en lempiras.
     *
     * @param Collection<int, Cargo> $lineas
     *
     * @return array<int, array{monto: Decimal, topada: bool}>
     */
    private function objetivos(Cuenta $cuenta, Collection $lineas): array
    {
        if ($cuenta->descuento_servicios_monto !== null) {
            return $this->repartir($cuenta, $lineas, Decimal::de($cuenta->descuento_servicios_monto));
        }

        $objetivos = [];
        $pedida = $cuenta->descuento_servicios === null ? null : Decimal::de($cuenta->descuento_servicios);

        foreach ($lineas as $cargo) {
            if (! $pedida instanceof Decimal) {
                $objetivos[$cargo->id] = ['monto' => Decimal::cero(), 'topada' => false];

                continue;
            }

            $tope = $this->topeDe($cuenta, $cargo);
            $topada = $pedida->mayorQue($tope);
            $aplica = $topada ? $tope : $pedida;

            $objetivos[$cargo->id] = [
                'monto'  => Decimal::de(Decimal::de($cargo->bruto)->por($aplica)->redondeado(2)),
                'topada' => $topada,
            ];
        }

        return $objetivos;
    }

    /**
     * Reparte un monto entre los renglones, sin pasar el tope de ninguno.
     *
     * ─────────────────────────────────────────────────────────────────
     * EN CENTAVOS ENTEROS, NO EN DECIMALES
     * ─────────────────────────────────────────────────────────────────
     *
     * Repartir es dividir y quedarse con un resto. En centavos enteros el
     * resto es exacto y se ve: lo que no se pudo repartir parejo va
     * entero al renglón más grande que todavía tiene lugar (§8.6.2-4), y
     * la suma da el monto pedido al centavo, siempre. Nada de `float`: son
     * enteros de PHP, que no redondean.
     *
     * Cuando la parte de un renglón pasa su tope, el renglón queda en su
     * tope y lo que sobra se vuelve a repartir entre los demás, en
     * proporción. Termina porque en cada vuelta o se reparte todo o al
     * menos un renglón se llena.
     *
     * @param Collection<int, Cargo> $lineas
     *
     * @return array<int, array{monto: Decimal, topada: bool}>
     *
     * @throws CuentaException
     */
    private function repartir(Cuenta $cuenta, Collection $lineas, Decimal $monto): array
    {
        $pedido = self::aCentavos($monto);

        if ($lineas->isEmpty()) {
            throw CuentaException::noHayServiciosParaRepartir($monto->redondeado(2));
        }

        $bruto = [];
        $tope = [];

        foreach ($lineas as $cargo) {
            $bruto[$cargo->id] = self::aCentavos(Decimal::de($cargo->bruto));
            $tope[$cargo->id] = self::aCentavos($this->topeEnLempiras($cuenta, $cargo));
        }

        $cabe = array_sum($tope);

        if ($pedido > $cabe) {
            throw CuentaException::descuentoEnServiciosMayorQueElTope(
                $monto->redondeado(2),
                self::deCentavos($cabe)->redondeado(2),
            );
        }

        $asignado = array_fill_keys(array_keys($bruto), 0);
        $activos = array_keys(array_filter($tope, fn (int $centavos): bool => $centavos > 0));
        $resta = $pedido;

        while ($resta > 0 && $activos !== []) {
            $base = 0;

            foreach ($activos as $id) {
                $base += $bruto[$id];
            }

            $partes = [];

            foreach ($activos as $id) {
                $partes[$id] = intdiv($resta * $bruto[$id], $base);
            }

            $llenos = array_values(array_filter(
                $activos,
                fn (int $id): bool => $tope[$id] <= $asignado[$id] + $partes[$id],
            ));

            if ($llenos !== []) {
                foreach ($llenos as $id) {
                    $resta -= $tope[$id] - $asignado[$id];
                    $asignado[$id] = $tope[$id];
                }

                $activos = array_values(array_diff($activos, $llenos));

                continue;
            }

            foreach ($activos as $id) {
                $asignado[$id] += $partes[$id];
                $resta -= $partes[$id];
            }

            /*
             * El resto de las divisiones —menos de un centavo por
             * renglón— va al más grande que todavía tiene lugar.
             */
            $porTamano = $activos;
            usort($porTamano, fn (int $a, int $b): int => $bruto[$b] <=> $bruto[$a]);

            foreach ($porTamano as $id) {
                if ($resta === 0) {
                    break;
                }

                $lugar = $tope[$id] - $asignado[$id];
                $va = min($lugar, $resta);
                $asignado[$id] += $va;
                $resta -= $va;
            }
        }

        $objetivos = [];

        foreach ($asignado as $id => $centavos) {
            $objetivos[$id] = [
                'monto'  => self::deCentavos($centavos),
                'topada' => $centavos > 0 && $centavos === $tope[$id],
            ];
        }

        return $objetivos;
    }

    /**
     * El tope de la rebaja de un renglón, como fracción.
     *
     * Con la fecha del SERVICIO: la edad que cuenta es la que tenía el
     * paciente ese día, igual que para el descuento de ley.
     */
    private function topeDe(Cuenta $cuenta, Cargo $cargo): Decimal
    {
        $item = $cargo->item;

        if (! $item instanceof Item) {
            return Decimal::cero();
        }

        return $this->topeEnLaCategoria($cuenta, $item->categoria_legal_descuento, $cargo->ocurrido_en);
    }

    private function topeEnLaCategoria(Cuenta $cuenta, CategoriaLegalDeDescuento $categoria, CarbonInterface $fecha): Decimal
    {
        $clave = $cuenta->id.'|'.$categoria->value.'|'.$fecha->format('Y-m-d');

        return $this->topes[$clave] ??= $this->politica->topeEnLaCuenta($cuenta, $categoria, $fecha);
    }

    /**
     * El mismo tope, en lempiras y redondeado como lo redondea la
     * calculadora: así lo que se reparte nunca pasa lo que el cargo acepta.
     */
    private function topeEnLempiras(Cuenta $cuenta, Cargo $cargo): Decimal
    {
        return Decimal::de(Decimal::de($cargo->bruto)->por($this->topeDe($cuenta, $cargo))->redondeado(2));
    }

    /**
     * Anula el renglón y lo vuelve a cargar igual, con la rebaja nueva.
     */
    private function rehacer(Cuenta $cuenta, Cargo $cargo, Decimal $rebaja): void
    {
        $item = $cargo->item;

        if (! $item instanceof Item) {
            return;
        }

        $conRebaja = ! $rebaja->esCero();

        $this->anulador->anular(
            $cargo,
            'Se rehace con el descuento en servicios de la cuenta '.$cuenta->numero,
        );

        $this->registrador->registrar($cuenta, new LineaDeCargo(
            item: $item,
            cantidad: Decimal::de($cargo->cantidad),
            claveIdempotencia: (string) Str::uuid(),
            almacen: $cargo->almacen,
            lote: $cargo->lote_id === null ? null : $cargo->lote,
            descuentoComercial: $conRebaja ? Monto::de($rebaja->redondeado(2)) : null,
            motivoDescuento: $conRebaja ? $cuenta->motivo_descuento_servicios : null,
            autorizadoPor: $conRebaja ? $cuenta->descuento_servicios_por : null,
            servicioId: $cargo->servicio_id,
            ocurridoEn: $cargo->ocurrido_en,
            medicoId: $cargo->medico_id,
            presentacion: $cargo->presentacion,
            envases: is_numeric($cargo->cantidad_presentacion)
                ? Decimal::de($cargo->cantidad_presentacion)
                : null,
            textoDelCargo: $cargo->texto,
        ));
    }

    private static function aCentavos(Decimal $lempiras): int
    {
        return (int) $lempiras->por('100')->redondeado(0);
    }

    private static function deCentavos(int $centavos): Decimal
    {
        return Decimal::de(Decimal::de((string) $centavos)->entre('100')->redondeado(2));
    }
}
