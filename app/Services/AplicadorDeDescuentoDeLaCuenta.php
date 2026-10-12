<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RubroDeDescuento;
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
 * EL DESCUENTO DEL HOSPITAL EN LO QUE NO SALE DE FARMACIA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio (11-oct-2026): «si se aplica un descuento por monto o por
 * porcentaje, se aplicaría a todo lo que no sea materiales y
 * medicamentos, ya que ese es descuento exclusivo de medicamentos y
 * materiales». Y al día siguiente: «también hacían descuentos en
 * honorarios». Son dos rubros (`RubroDeDescuento`), cada uno con su
 * campo, su motivo y sus columnas en la cuenta. El de farmacia vive
 * aparte, en `CuentasAbiertas::rehacerLasLineasConElDescuento()`.
 *
 * Lo mismo para los dos rubros:
 *
 *   · Va en porcentaje O en lempiras. «Rebájele L 500» se reparte entre
 *     los renglones del rubro en proporción a lo que vale cada uno, y el
 *     residuo de redondeo cae en el más grande (§8.6.2-4).
 *   · 🔴 EN PORCENTAJE, HASTA 20 %; EN LEMPIRAS, LIBRE (Mauricio,
 *     12-oct-2026: «si es monto se le puede colocar cantidad libre; en
 *     porcentaje que no pase del 20 %; en honorarios lo mismo»). Parejo
 *     para todos y encima del de ley
 *     (`PoliticaDeDescuentoComercial::topeDelDescuentoDeLaCuenta()`).
 *     Antes cada renglón se topaba con su categoría del Art. 30, y al
 *     adulto mayor no se le podía dar nada: «hasta L 0.00 en servicios».
 *     En lempiras cada renglón aguanta lo que vale después del de ley;
 *     lo que uno no aguanta pasa a los otros, y si entre todos no
 *     alcanza, no se aplica y se dice hasta cuánto se puede.
 *   · Lo que entra después también lo lleva. En porcentaje, la línea
 *     nueva nace con su parte; en lempiras, el monto se vuelve a repartir
 *     entre todos, porque «L 500» sigue siendo L 500.
 *
 * Ningún rubro toca el paquete quirúrgico ni lo que va adentro: tiene un
 * precio acordado con la familia, y su única rebaja es la de los
 * medicamentos entregados (ADR-0009). Tampoco lo que no se cobra ni lo
 * que ya se facturó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REHACER ES REHACER IGUAL (§9.M-12)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un renglón asentado no se edita: se anula y se vuelve a cargar con la
 * rebaja, y la bitácora lo cuenta. Vuelve con la misma fecha del
 * servicio —el precio se resuelve por esa fecha (§8.5-3)—, el mismo
 * médico, el mismo servicio, el mismo texto y EL MISMO PRECIO del cargo
 * original (§9.M-20), incluido el del médico en un honorario. Solo se
 * rehace lo que no tiene ya lo que le toca.
 */
final class AplicadorDeDescuentoDeLaCuenta
{
    public function __construct(
        private readonly PoliticaDeDescuentoComercial $politica,
        private readonly AnuladorDeCargo $anulador,
        private readonly RegistradorDeCargo $registrador,
    ) {}

    /**
     * A qué rubro pertenece un ítem. Nulo para farmacia y el paquete.
     */
    public static function rubroDe(Item $item): ?RubroDeDescuento
    {
        if ($item->se_almacena || $item->tipo === TipoItem::Paquete) {
            return null;
        }

        return $item->tipo === TipoItem::Honorario
            ? RubroDeDescuento::Honorarios
            : RubroDeDescuento::Servicios;
    }

    /**
     * Fija el descuento de un rubro en la cuenta y deja sus renglones al día.
     *
     * Uno de los dos: `$fraccion` (0.10 = 10 %) o `$monto` en lempiras.
     *
     * @return list<string> los renglones que quedaron en su tope, para decirlo en pantalla
     *
     * @throws CuentaException
     */
    public function fijar(
        Cuenta $cuenta,
        RubroDeDescuento $rubro,
        ?Decimal $fraccion,
        ?Decimal $monto,
        string $motivo,
        ?int $quien,
    ): array {
        if (($fraccion === null) === ($monto === null)) {
            throw CuentaException::descuentoEnUnaForma($rubro->enPlural());
        }

        $pedido = $fraccion ?? $monto;

        if ($pedido === null
            || $pedido->esCero()
            || $pedido->esNegativo()
            || ($fraccion instanceof Decimal && $fraccion->mayorQue('1'))) {
            throw CuentaException::descuentoInvalido($rubro->enPlural());
        }

        $tope = $this->politica->topeDelDescuentoDeLaCuenta();

        if ($fraccion instanceof Decimal && $fraccion->mayorQue($tope)) {
            throw CuentaException::porcentajeMayorQueElTope(
                $rubro->enPlural(),
                $fraccion->comoPorcentaje(),
                $tope->comoPorcentaje(),
            );
        }

        $motivo = TextoCanonico::parrafoEnMayusculas($motivo) ?? '';

        if (mb_strlen($motivo) < 10) {
            throw CuentaException::faltaElMotivoDelDescuento();
        }

        /** @var list<string> $topadas */
        $topadas = DB::transaction(function () use ($cuenta, $rubro, $fraccion, $monto, $motivo, $quien): array {
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
                $this->repartir($bloqueada, $rubro, $this->lineasDe($bloqueada, $rubro), $monto);
            }

            $bloqueada->forceFill([
                $rubro->columnaFraccion() => $fraccion?->redondeado(4),
                $rubro->columnaMonto()    => $monto?->redondeado(2),
                $rubro->columnaMotivo()   => $motivo,
                $rubro->columnaPor()      => $quien,
                $rubro->columnaEn()       => now(),
            ])->save();

            return $this->ponerAlDia($bloqueada, $rubro);
        });

        return $topadas;
    }

    /**
     * Quita el descuento de un rubro y devuelve sus renglones a precio lleno.
     */
    public function quitar(Cuenta $cuenta, RubroDeDescuento $rubro): void
    {
        DB::transaction(function () use ($cuenta, $rubro): void {
            $bloqueada = Cuenta::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            $bloqueada->forceFill([
                $rubro->columnaFraccion() => null,
                $rubro->columnaMonto()    => null,
                $rubro->columnaMotivo()   => null,
                $rubro->columnaPor()      => null,
                $rubro->columnaEn()       => null,
            ])->save();

            $this->ponerAlDia($bloqueada, $rubro);
        });
    }

    /**
     * Rehace los renglones del rubro que no tienen lo que les toca.
     *
     * Se llama al fijar, al quitar, y —con el descuento en lempiras— cada
     * vez que entra o sale un renglón del rubro, porque el reparto cambia.
     *
     * @return list<string> los renglones que quedaron en su tope
     */
    public function ponerAlDia(Cuenta $cuenta, RubroDeDescuento $rubro): array
    {
        /** @var list<string> $topadas */
        $topadas = DB::transaction(function () use ($cuenta, $rubro): array {
            $lineas = $this->lineasDe($cuenta, $rubro);
            $objetivos = $this->objetivos($cuenta, $rubro, $lineas);
            $topadas = [];

            foreach ($lineas as $cargo) {
                $objetivo = $objetivos[$cargo->id] ?? ['monto' => Decimal::cero(), 'topada' => false];

                if ($objetivo['topada']) {
                    $topadas[] = $cargo->texto;
                }

                if (Decimal::de($cargo->descuento_comercial)->igualA($objetivo['monto']->redondeado(2))) {
                    continue;
                }

                $this->rehacer($cuenta, $rubro, $cargo, $objetivo['monto']);
            }

            return $topadas;
        });

        return $topadas;
    }

    /**
     * La rebaja con la que nace una línea nueva, en porcentaje.
     *
     * Nula si el rubro del ítem no tiene descuento en la cuenta, si está en
     * lempiras —ahí se reparte después con `ponerAlDia()`— o si el ítem es
     * de farmacia. Nunca más que el tope del porcentaje, aunque la cuenta
     * se haya guardado con otro antes de que lo bajaran.
     */
    public function fraccionParaLaLineaNueva(Cuenta $cuenta, Item $item, CarbonInterface $fecha): ?Decimal
    {
        $rubro = self::rubroDe($item);
        $pedida = $rubro instanceof RubroDeDescuento ? self::fraccionDe($cuenta, $rubro) : null;

        if (! $pedida instanceof Decimal) {
            return null;
        }

        $tope = $this->politica->topeDelDescuentoDeLaCuenta();
        $aplica = $pedida->mayorQue($tope) ? $tope : $pedida;

        return $aplica->esCero() ? null : $aplica;
    }

    /**
     * Cuánto se le puede rebajar en lempiras a lo que ya está cargado del
     * rubro: lo que suma después del descuento de ley.
     */
    public function maximoEnLempiras(Cuenta $cuenta, RubroDeDescuento $rubro): Decimal
    {
        $maximo = Decimal::cero();

        foreach ($this->lineasDe($cuenta, $rubro) as $cargo) {
            $maximo = $maximo->sumar(self::loQueQuedaDe($cargo));
        }

        return $maximo;
    }

    /**
     * Los renglones del rubro a los que alcanza el descuento.
     *
     * @return Collection<int, Cargo>
     */
    public function lineasDe(Cuenta $cuenta, RubroDeDescuento $rubro): Collection
    {
        return $cuenta->cargos()
            ->with(['item', 'almacen', 'lote', 'presentacion'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Cargo $cargo): bool => $cargo->admiteAnulacionDirecta()
                && $cargo->item instanceof Item
                && self::rubroDe($cargo->item) === $rubro
                && $cargo->presupuesto_id === null
                && $cargo->politica_cargo === PoliticaCargo::Cobrable
                && Decimal::de($cargo->bruto)->mayorQue('0'))
            ->values();
    }

    public static function fraccionDe(Cuenta $cuenta, RubroDeDescuento $rubro): ?Decimal
    {
        $valor = $cuenta->getAttribute($rubro->columnaFraccion());

        return is_numeric($valor) ? Decimal::de((string) $valor) : null;
    }

    public static function montoDe(Cuenta $cuenta, RubroDeDescuento $rubro): ?Decimal
    {
        $valor = $cuenta->getAttribute($rubro->columnaMonto());

        return is_numeric($valor) ? Decimal::de((string) $valor) : null;
    }

    public static function motivoDe(Cuenta $cuenta, RubroDeDescuento $rubro): ?string
    {
        $valor = $cuenta->getAttribute($rubro->columnaMotivo());

        return is_string($valor) ? $valor : null;
    }

    public static function quienLoDio(Cuenta $cuenta, RubroDeDescuento $rubro): ?int
    {
        $valor = $cuenta->getAttribute($rubro->columnaPor());

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * Lo que le toca a cada renglón, en lempiras.
     *
     * @param Collection<int, Cargo> $lineas
     *
     * @return array<int, array{monto: Decimal, topada: bool}>
     */
    private function objetivos(Cuenta $cuenta, RubroDeDescuento $rubro, Collection $lineas): array
    {
        $monto = self::montoDe($cuenta, $rubro);

        if ($monto instanceof Decimal) {
            return $this->repartir($cuenta, $rubro, $lineas, $monto);
        }

        $objetivos = [];
        $pedida = self::fraccionDe($cuenta, $rubro);
        $tope = $this->politica->topeDelDescuentoDeLaCuenta();

        foreach ($lineas as $cargo) {
            if (! $pedida instanceof Decimal) {
                $objetivos[$cargo->id] = ['monto' => Decimal::cero(), 'topada' => false];

                continue;
            }

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
     * El tope de cada renglón es lo que vale después del descuento de ley
     * (12-oct-2026: en lempiras, libre). Cuando la parte de un renglón
     * pasa su tope, el renglón queda en su tope y lo que sobra se vuelve a
     * repartir entre los demás, en proporción. Termina porque en cada vuelta o se reparte todo o al
     * menos un renglón se llena.
     *
     * @param Collection<int, Cargo> $lineas
     *
     * @return array<int, array{monto: Decimal, topada: bool}>
     *
     * @throws CuentaException
     */
    private function repartir(Cuenta $cuenta, RubroDeDescuento $rubro, Collection $lineas, Decimal $monto): array
    {
        $pedido = self::aCentavos($monto);

        if ($lineas->isEmpty()) {
            throw CuentaException::noHayNadaParaRepartir($rubro->enPlural(), $monto->redondeado(2));
        }

        $bruto = [];
        $tope = [];

        foreach ($lineas as $cargo) {
            $bruto[$cargo->id] = self::aCentavos(Decimal::de($cargo->bruto));
            $tope[$cargo->id] = self::aCentavos(self::loQueQuedaDe($cargo));
        }

        $cabe = array_sum($tope);

        if ($pedido > $cabe) {
            throw CuentaException::descuentoMayorQueElTope(
                $rubro->enPlural(),
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
     * Lo que vale el renglón después del descuento de ley: hasta ahí llega
     * el descuento en lempiras. Más que eso lo dejaría en negativo.
     */
    private static function loQueQuedaDe(Cargo $cargo): Decimal
    {
        $queda = Decimal::de($cargo->bruto)->restar(Decimal::de($cargo->descuento_legal));

        return $queda->esNegativo() ? Decimal::cero() : $queda;
    }

    /**
     * Anula el renglón y lo vuelve a cargar igual, con la rebaja nueva.
     */
    private function rehacer(Cuenta $cuenta, RubroDeDescuento $rubro, Cargo $cargo, Decimal $rebaja): void
    {
        $item = $cargo->item;

        if (! $item instanceof Item) {
            return;
        }

        $conRebaja = ! $rebaja->esCero();

        $this->anulador->anular(
            $cargo,
            'Se rehace con el descuento en '.$rubro->enPlural().' de la cuenta '.$cuenta->numero,
        );

        $this->registrador->registrar($cuenta, new LineaDeCargo(
            item: $item,
            cantidad: Decimal::de($cargo->cantidad),
            claveIdempotencia: (string) Str::uuid(),
            almacen: $cargo->almacen,
            lote: $cargo->lote_id === null ? null : $cargo->lote,
            descuentoComercial: $conRebaja ? Monto::de($rebaja->redondeado(2)) : null,
            motivoDescuento: $conRebaja ? self::motivoDe($cuenta, $rubro) : null,
            autorizadoPor: $conRebaja ? self::quienLoDio($cuenta, $rubro) : null,
            servicioId: $cargo->servicio_id,
            ocurridoEn: $cargo->ocurrido_en,
            medicoId: $cargo->medico_id,
            presentacion: $cargo->presentacion,
            envases: is_numeric($cargo->cantidad_presentacion)
                ? Decimal::de($cargo->cantidad_presentacion)
                : null,
            textoDelCargo: $cargo->texto,

            /*
             * 🔴 Con el precio que ya tenía, no con el de hoy: la rebaja
             * se calculó sobre ESE bruto, y el honorario con la lista
             * del médico vuelve también tal cual (LineaDeCargo).
             */
            mismoPrecioQue: $cargo,
            descuentoDeLaCuenta: $conRebaja ? $rubro : null,
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
