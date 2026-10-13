<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\PrecioMinimo;
use App\Models\Sede;
use App\Models\Tarifario;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;

/**
 * Lo que la ficha de un producto muestra en «Precios» (13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO VIGENTE Y LO DE ANTES, SEPARADOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «¿cuál es el precio vigente? No se sabe cuáles son del
 * historial y cuál el vigente». La tabla mostraba todas las filas del
 * tarifario juntas, y dos decían «Vigente» para la tableta: L 4.05 de la
 * sede y L 3.00 de todas las sedes. Las dos lo eran, pero solo una se
 * cobra.
 *
 * Acá «vigente» es lo que de verdad se cobra hoy en esa sede, resuelto
 * con la MISMA regla que el cargo (`Tarifario::scopeResolviendoPara`):
 * una fila por forma de venta. Todo lo demás va aparte, y dice por qué no
 * se cobra: se cerró, es de otra sede, otra le gana acá, o empieza más
 * adelante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA CUARTA EDAD, CON LA LEY DE HOY
 * ─────────────────────────────────────────────────────────────────────
 *
 * Lo que paga cada edad se calcula con el descuento que rige hoy, no con
 * el que regía cuando se fijó el precio. Si la ley sube del 40 al 45 %,
 * los precios se ajustan solos (`ReajustadorDePrecios`); si por algo eso
 * no corrió todavía, la ficha muestra que la cuarta edad pasa a pagar
 * menos que el mínimo, y lo avisa (Mauricio, 13-oct-2026).
 *
 * @phpstan-type Forma array{
 *     clave: string,
 *     rotulo: string,
 *     contenido: string|null,
 *     esLaPrincipal: bool,
 *     recargo: string|null,
 *     lista: Monto,
 *     porUnidad: Monto|null,
 *     propio: bool,
 *     porEdad: list<array{rango: string, paga: Monto}>,
 * }
 * @phpstan-type Origen array{minimo: Decimal, envase: Decimal, suelta: Decimal}
 * @phpstan-type Cambio array{
 *     desde: CarbonInterface,
 *     hasta: CarbonInterface|null,
 *     estado: string,
 *     donde: string,
 *     pagador: string,
 *     porQue: string|null,
 *     precios: list<array{rotulo: string, monto: Monto}>,
 * }
 */
final class ResumenDePrecios
{
    public function __construct(
        private readonly CalculadoraDePreciosPorForma $calculadora,
    ) {}

    /**
     * @return array{
     *     formas: list<Forma>,
     *     hayDescuentoPorEdad: bool,
     *     desde: CarbonInterface|null,
     *     porQue: string|null,
     *     origen: Origen|null,
     *     principal: string,
     *     minimo: Monto|null,
     *     debajoDelMinimo: array{rotulo: string, rango: string, paga: Monto, minimo: Monto}|null,
     *     seguros: list<array{pagador: string, rotulo: string, monto: Monto, desde: CarbonInterface}>,
     *     otros: list<Cambio>,
     *     sinPrecio: list<string>,
     * }
     */
    public function de(Item $item, ?Sede $sede, CarbonInterface $hoy): array
    {
        $presentaciones = ItemPresentacion::query()->with('unidad')->where('item_id', $item->id)->get();
        $envases = $this->calculadora->envasesDe($presentaciones);
        $principal = $this->calculadora->principalEntre($envases);
        $sedeId = $sede?->id;

        $sueltaFila = $this->ganadora($item, $sedeId, null, $hoy);
        $deLaPrincipal = $principal instanceof ItemPresentacion
            ? $this->ganadora($item, $sedeId, $principal->id, $hoy)
            : $sueltaFila;
        $origen = $this->origen($item, $sedeId, $hoy, $principal)
            ?? FijadorDePreciosPorForma::origenDe($deLaPrincipal?->motivo);

        $formas = [];
        $ganadoras = [];
        $sinPrecio = [];

        /*
         * De la más grande a la suelta: la caja, el blíster, la tableta.
         * Es el orden en que se lee una lista de precios de farmacia.
         */
        foreach (array_reverse($envases) as $envase) {
            $fila = $this->ganadora($item, $sedeId, $envase->id, $hoy);

            if (! $fila instanceof Tarifario) {
                $sinPrecio[] = $envase->envase();

                continue;
            }

            $ganadoras[$fila->id] = true;
            $contenido = Decimal::de($envase->unidades_por_presentacion);
            $propio = $fila->item_presentacion_id === $envase->id;

            /*
             * El envase entero exacto cuando la fila lo tiene; si no, lo
             * que sale de multiplicar. Sin fila propia, el envase se cobra
             * como esa cantidad de sueltas — y se dice.
             */
            $lista = ($propio ? $fila->montoDelEnvase() : null)
                ?? Monto::de(Decimal::de($fila->precio)->por($contenido)->redondeado(2));

            $formas[] = $this->forma(
                item: $item,
                clave: FormasDeCobro::ENVASE.$envase->id,
                rotulo: $envase->envase(),
                contenido: ItemPresentacion::sinCerosDeMas($envase->unidades_por_presentacion),
                esLaPrincipal: $principal instanceof ItemPresentacion && $envase->id === $principal->id,
                recargo: $this->recargoDe($envase, $principal, $origen),
                lista: $lista,
                porUnidad: $fila->monto(),
                propio: $propio,
                hoy: $hoy,
            );
        }

        if ($sueltaFila instanceof Tarifario) {
            $ganadoras[$sueltaFila->id] = true;

            $formas[] = $this->forma(
                item: $item,
                clave: FormasDeCobro::SUELTA,
                rotulo: $this->calculadora->rotuloDeLaSuelta($item),
                contenido: null,
                esLaPrincipal: ! $principal instanceof ItemPresentacion,
                recargo: $principal instanceof ItemPresentacion && $origen !== null && $origen['suelta']->mayorQue('0')
                    ? '+'.$origen['suelta']->comoPorcentaje()
                    : null,
                lista: $sueltaFila->monto(),
                porUnidad: null,
                propio: true,
                hoy: $hoy,
            );
        } else {
            $sinPrecio[] = $this->calculadora->rotuloDeLaSuelta($item);
        }

        $seguros = $this->seguros($item, $sedeId, $hoy, $presentaciones);

        foreach ($seguros['ids'] as $id) {
            $ganadoras[$id] = true;
        }

        return [
            'formas'              => $formas,
            'hayDescuentoPorEdad' => $this->hayDescuentoPorEdad($formas),
            'desde'               => $deLaPrincipal?->vigencia_desde,
            'porQue'              => FijadorDePreciosPorForma::soloElPorque($deLaPrincipal?->motivo),
            'origen'              => $origen,
            'principal'           => $principal instanceof ItemPresentacion ? $principal->envase() : $this->calculadora->rotuloDeLaSuelta($item),
            'minimo'              => $origen === null ? null : Monto::de($origen['minimo']->redondeado(2)),
            'debajoDelMinimo'     => $this->debajoDelMinimo($formas, $origen),
            'seguros'             => $seguros['filas'],
            'otros'               => $this->otros($item, $sedeId, $hoy, $ganadoras),
            'sinPrecio'           => $sinPrecio,
        ];
    }

    /**
     * El mínimo y los recargos de los que salen los precios de hoy, desde
     * lo guardado (`PrecioMinimo`): el mínimo, del envase que HOY es el
     * principal. Si la caja pasó de 100 a 120 tabletas, acá dice L 144 y
     * no los L 120 que se escribieron para la de 100.
     *
     * Nulo si no hay nada guardado —un precio de antes del 13-oct-2026
     * que la migración no pudo leer—, y entonces se lee del motivo.
     *
     * @return Origen|null
     */
    private function origen(Item $item, ?int $sedeId, CarbonInterface $hoy, ?ItemPresentacion $principal): ?array
    {
        $guardado = PrecioMinimo::queRigePara($item, $sedeId, $hoy);

        if (! $guardado instanceof PrecioMinimo) {
            return null;
        }

        $contenido = $principal instanceof ItemPresentacion ? Decimal::de($principal->unidades_por_presentacion) : Decimal::de('1');

        return [
            'minimo' => $guardado->minimoPara($contenido),
            'envase' => $guardado->recargoEnvase(),
            'suelta' => $guardado->recargoSuelta(),
        ];
    }

    /**
     * La fila que el cargo usaría hoy para esa forma: la misma consulta
     * que `ResolutorDePrecio::deLista()`.
     */
    private function ganadora(Item $item, ?int $sedeId, ?int $presentacionId, CarbonInterface $hoy): ?Tarifario
    {
        return Tarifario::query()
            ->where('item_id', $item->id)
            ->resolviendoPara(null, $sedeId, $presentacionId)
            ->vigentesEn($hoy)
            ->first();
    }

    /**
     * @return Forma
     */
    private function forma(
        Item $item,
        string $clave,
        string $rotulo,
        ?string $contenido,
        bool $esLaPrincipal,
        ?string $recargo,
        Monto $lista,
        ?Monto $porUnidad,
        bool $propio,
        CarbonInterface $hoy,
    ): array {
        $porEdad = [];

        foreach ($this->calculadora->porEdadDe($item, $lista, $hoy) as ['rango' => $rango, 'paga' => $paga]) {
            $porEdad[] = ['rango' => $rango->etiqueta(), 'paga' => $paga];
        }

        return [
            'clave'         => $clave,
            'rotulo'        => $rotulo,
            'contenido'     => $contenido,
            'esLaPrincipal' => $esLaPrincipal,
            'recargo'       => $esLaPrincipal ? null : $recargo,
            'lista'         => $lista,
            'porUnidad'     => $porUnidad,
            'propio'        => $propio,
            'porEdad'       => $porEdad,
        ];
    }

    /**
     * «+5 %» en los envases más chicos que el principal, con el recargo
     * con el que se fijó el precio. Nada si no se sabe.
     *
     * @param Origen|null $origen
     */
    private function recargoDe(ItemPresentacion $envase, ?ItemPresentacion $principal, ?array $origen): ?string
    {
        if (! $principal instanceof ItemPresentacion || $origen === null || ! $origen['envase']->mayorQue('0')) {
            return null;
        }

        return Decimal::de($envase->unidades_por_presentacion)->menorQue($principal->unidades_por_presentacion)
            ? '+'.$origen['envase']->comoPorcentaje()
            : null;
    }

    /**
     * @param list<Forma> $formas
     */
    private function hayDescuentoPorEdad(array $formas): bool
    {
        foreach ($formas as $forma) {
            foreach ($forma['porEdad'] as ['paga' => $paga]) {
                if (! $paga->igualA($forma['lista'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 🔴 La principal, si con la ley de hoy alguna edad paga menos que el
     * mínimo con el que se fijó el precio.
     *
     * Pasa cuando el descuento de ley sube después de fijar el precio: la
     * lista quedó calculada para el 40 % y la cuarta edad ahora se lleva
     * el 45 %. Es pérdida en cada venta a esa edad, y no avisa nadie más.
     *
     * @param list<Forma> $formas
     * @param Origen|null $origen
     *
     * @return array{rotulo: string, rango: string, paga: Monto, minimo: Monto}|null
     */
    private function debajoDelMinimo(array $formas, ?array $origen): ?array
    {
        if ($origen === null) {
            return null;
        }

        $minimo = Monto::de($origen['minimo']->redondeado(2));

        foreach ($formas as $forma) {
            if (! $forma['esLaPrincipal']) {
                continue;
            }

            foreach ($forma['porEdad'] as ['rango' => $rango, 'paga' => $paga]) {
                if ($paga->menorQue($minimo)) {
                    return ['rotulo' => $forma['rotulo'], 'rango' => $rango, 'paga' => $paga, 'minimo' => $minimo];
                }
            }
        }

        return null;
    }

    /**
     * Los precios propios de un pagador que rigen hoy en esta sede. En
     * farmacia no hay —los seguros pagan la lista—, pero un producto
     * «Otro» sí puede tenerlos.
     *
     * @param ColeccionDeModelos<int, ItemPresentacion> $presentaciones
     *
     * @return array{filas: list<array{pagador: string, rotulo: string, monto: Monto, desde: CarbonInterface}>, ids: list<int>}
     */
    private function seguros(Item $item, ?int $sedeId, CarbonInterface $hoy, ColeccionDeModelos $presentaciones): array
    {
        $vigentes = Tarifario::query()
            ->with('convenio:id,nombre')
            ->where('item_id', $item->id)
            ->whereNotNull('convenio_id')
            ->where(fn ($consulta) => $consulta->whereNull('sede_id')->when(
                $sedeId !== null,
                fn ($sub) => $sub->orWhere('sede_id', $sedeId),
            ))
            ->vigentesEn($hoy)
            ->orderByRaw('sede_id is null')
            ->get();

        $filas = [];
        $ids = [];
        $vistos = [];

        foreach ($vigentes as $fila) {
            $llave = $fila->convenio_id.'|'.($fila->item_presentacion_id ?? 0);

            /* La de la sede gana a la de todas: viene primero. */
            if (isset($vistos[$llave])) {
                continue;
            }

            $vistos[$llave] = true;
            $ids[] = $fila->id;
            $presentacion = $fila->item_presentacion_id === null ? null : $presentaciones->firstWhere('id', $fila->item_presentacion_id);
            $convenio = $fila->convenio;

            $filas[] = [
                'pagador' => $convenio instanceof Convenio ? $convenio->nombre : 'Seguro',
                'rotulo'  => $presentacion instanceof ItemPresentacion ? $presentacion->envase() : $this->calculadora->rotuloDeLaSuelta($item),
                'monto'   => $presentacion instanceof ItemPresentacion
                    ? ($fila->montoDelEnvase() ?? Monto::de(Decimal::de($fila->precio)->por($presentacion->unidades_por_presentacion)->redondeado(2)))
                    : $fila->monto(),
                'desde' => $fila->vigencia_desde,
            ];
        }

        usort($filas, fn (array $una, array $otra): int => strcmp($una['pagador'], $otra['pagador']));

        return ['filas' => $filas, 'ids' => $ids];
    }

    /**
     * Todo lo que no se cobra hoy acá, agrupado por cambio: las filas que
     * se fijaron juntas —el mismo día, para el mismo pagador y sede, con
     * el mismo porqué— son UN cambio de precio, no tres renglones con el
     * mismo texto repetido.
     *
     * Cada cambio dice por qué no se cobra:
     *
     *   · `cerrado`  — rigió hasta una fecha.
     *   · `tapado`   — sigue abierto para todas las sedes, pero en esta
     *                  le gana el precio propio de la sede.
     *   · `otraSede` — rige en otra sede.
     *   · `proximo`  — empieza más adelante.
     *
     * @param array<int, true> $ganadoras
     *
     * @return list<Cambio>
     */
    private function otros(Item $item, ?int $sedeId, CarbonInterface $hoy, array $ganadoras): array
    {
        $filas = Tarifario::query()
            ->with(['presentacion', 'sede:id,nombre', 'convenio:id,nombre'])
            ->where('item_id', $item->id)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $cambios = [];
        $contenidos = [];
        $dia = $hoy->toDateString();
        $suelta = $this->calculadora->rotuloDeLaSuelta($item);

        foreach ($filas as $fila) {
            if (isset($ganadoras[$fila->id])) {
                continue;
            }

            $llave = implode('|', [
                $fila->vigencia_desde->toDateString(),
                $fila->vigencia_hasta?->toDateString() ?? '',
                $fila->sede_id ?? 0,
                $fila->convenio_id ?? 0,
                $fila->motivo,
            ]);

            if (! isset($cambios[$llave])) {
                $sede = $fila->sede_id === null ? null : $fila->sede;
                $convenio = $fila->convenio_id === null ? null : $fila->convenio;

                $cambios[$llave] = [
                    'desde'   => $fila->vigencia_desde,
                    'hasta'   => $fila->vigencia_hasta,
                    'estado'  => $this->estadoDe($fila, $sedeId, $dia),
                    'donde'   => $sede instanceof Sede ? $sede->nombre : 'Todas las sedes',
                    'pagador' => $convenio instanceof Convenio ? $convenio->nombre : 'Precio de lista',
                    'porQue'  => FijadorDePreciosPorForma::soloElPorque($fila->motivo),
                    'precios' => [],
                ];
                $contenidos[$llave] = [];
            }

            $presentacion = $fila->item_presentacion_id === null ? null : $fila->presentacion;

            $cambios[$llave]['precios'][] = [
                'rotulo' => $presentacion instanceof ItemPresentacion ? $presentacion->envase() : $suelta,
                'monto'  => $presentacion instanceof ItemPresentacion
                    ? ($fila->montoDelEnvase() ?? Monto::de(Decimal::de($fila->precio)->por($presentacion->unidades_por_presentacion)->redondeado(2)))
                    : $fila->monto(),
            ];
            $contenidos[$llave][] = $presentacion instanceof ItemPresentacion ? $presentacion->unidades_por_presentacion : '1';
        }

        $resultado = [];

        foreach ($cambios as $llave => $cambio) {
            $cambio['precios'] = self::delMasGrandeAlMasChico($cambio['precios'], $contenidos[$llave] ?? []);
            $resultado[] = $cambio;
        }

        return $resultado;
    }

    /**
     * La caja antes que el blíster y el blíster antes que la suelta.
     *
     * @param list<array{rotulo: string, monto: Monto}> $precios
     * @param list<string> $contenidos el contenido de cada uno, en el mismo orden
     *
     * @return list<array{rotulo: string, monto: Monto}>
     */
    private static function delMasGrandeAlMasChico(array $precios, array $contenidos): array
    {
        $indices = array_keys($precios);

        usort($indices, fn (int $uno, int $otro): int => Decimal::de($contenidos[$otro] ?? '1')
            ->comparar($contenidos[$uno] ?? '1'));

        return array_map(fn (int $i): array => $precios[$i], $indices);
    }

    private function estadoDe(Tarifario $fila, ?int $sedeId, string $dia): string
    {
        if ($fila->vigencia_desde->toDateString() > $dia) {
            return 'proximo';
        }

        if ($fila->vigencia_hasta !== null && $fila->vigencia_hasta->toDateString() < $dia) {
            return 'cerrado';
        }

        if ($fila->sede_id !== null && $fila->sede_id !== $sedeId) {
            return 'otraSede';
        }

        return 'tapado';
    }
}
