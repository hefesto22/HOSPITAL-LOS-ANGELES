<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\Cargo;
use App\Models\Presupuesto;
use Illuminate\Support\Collection;

/**
 * Un renglón de la cuenta tal como se lee, que NO es un cargo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DIEZ Y CINCO SON QUINCE, PERO SOLO SI LOS DIO LA MISMA PERSONA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Enfermería no entrega una vez: entrega cuando toca. En un turno, el
 * mismo jarabe sale tres o cuatro veces, y una cuenta con doce renglones
 * de ACETAMINOFEN no se puede revisar — que es justo para lo que existe
 * esa pantalla.
 *
 * Pero sumar TODO tampoco sirve. Si el turno A dio 15 ml y el turno B dio
 * 20, un solo renglón de 35 ml borra la única pregunta que se hace en el
 * cambio de turno: **quién le dio qué a este paciente.** Por eso se agrupa
 * por persona: lo de uno se suma, lo del siguiente abre renglón nuevo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTO NO TOCA LA BASE. AGRUPA AL LEER.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los cargos siguen siendo uno por entrega, cada uno con su movimiento de
 * kardex, su lote y su hora. Fusionarlos de verdad —un `UPDATE cargo SET
 * cantidad = 15`— rompería dos cosas a la vez: el kardex append-only, y
 * la atribución por lote, porque los 10 pueden haber salido de un lote y
 * los 5 del siguiente.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA LLAVE ES LARGA A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se agrupa por texto + quién + precio + descuento por unidad, y no solo
 * por producto. El texto ya trae el lote, así que dos lotes distintos no
 * se mezclan. Y si el precio o el descuento cambiaron a mitad de la
 * cuenta, los dos cargos NO se pueden sumar sin mentir: la multiplicación
 * del renglón dejaría de dar su propio importe. Con esta llave, cantidad
 * × precio − descuento siempre cuadra con lo que se muestra.
 */
final readonly class RenglonDeCuenta
{
    /**
     * @param list<Cargo> $entregas de la más vieja a la más nueva
     */
    public function __construct(
        public string $texto,
        public string $nota,
        public Decimal $cantidad,
        public string $precioUnitario,
        public Decimal $descuento,
        public Decimal $total,
        public ?string $quien,
        public array $entregas,

        /*
         * Cómo se lee la cantidad: «FRASCO 60 ML», «TABLETA». Nulo
         * cuando el ítem no tiene unidad declarada —un honorario, una
         * consulta—, y ahí la cantidad se lee sola.
         */
        public ?string $unidad = null,
    ) {}

    /**
     * @param Collection<int, Cargo> $cargos ya ordenados por id
     */
    public static function de(Collection $cargos): self
    {
        /** @var Cargo $primero */
        $primero = $cargos->first();

        $cantidad = Decimal::cero();
        $envases = Decimal::cero();
        $descuento = Decimal::cero();
        $bruto = Decimal::cero();
        $total = Decimal::cero();

        foreach ($cargos as $cargo) {
            $cantidad = $cantidad->sumar($cargo->cantidad);
            $envases = $envases->sumar($cargo->comoSeCobro()['envases'] ?? Decimal::cero());
            $descuento = $descuento
                ->sumar($cargo->descuento_legal)
                ->sumar($cargo->descuento_comercial);
            $bruto = $bruto->sumar($cargo->bruto);
            $total = $total->sumar($cargo->total);
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 SE LEE COMO SE COBRÓ, NO COMO SALIÓ DEL ESTANTE
         * ─────────────────────────────────────────────────────────────
         *
         * El renglón decía «60 × L 61.11» por UN frasco de 60 ml. Los
         * números eran correctos y la lectura estaba mal: nadie entregó
         * sesenta de nada, y L 61.11 no es un precio de este hospital —es
         * lo que sale de dividir el frasco entre sus mililitros—.
         *
         * Cuando el cargo guardó en qué envase se cobró, se lee de ahí:
         * «1 FRASCO 60 ML a L 3,666.67». El precio unitario se DERIVA del
         * bruto entre los envases y no se guarda por segunda vez: dos
         * copias del mismo número son dos números que alguna vez van a
         * diferir.
         *
         * Sin envase —un honorario, un jarabe vendido por mililitro— la
         * cantidad de dispensación ya es la correcta y no se toca nada.
         */
        $comoSeCobro = $primero->comoSeCobro();
        $porEnvase = $comoSeCobro !== null && ! $envases->esCero();

        return new self(
            texto: $primero->texto,
            nota: $primero->regimen_isv->etiqueta().($primero->es_tardio ? ' · cargo tardío' : ''),
            cantidad: $porEnvase ? $envases : $cantidad,
            precioUnitario: $porEnvase
                ? $bruto->entre($envases)->redondeado(4)
                : $primero->precio_unitario,
            descuento: $descuento,
            total: $total,
            quien: $primero->createdBy?->name,
            /*
             * `array_values` y no `->values()->all()`: los dos devuelven
             * lo mismo en tiempo de ejecución, pero solo el primero le
             * prueba a PHPStan que las llaves quedaron 0,1,2… y el
             * parámetro pide una `list`.
             */
            entregas: array_values($cargos->all()),
            /*
             * Solo se rotula el envase. Sin él la cantidad ya se lee
             * sola —el texto del renglón dice qué es— y agregar «ML» a
             * un renglón y nada al de al lado deja la columna despareja.
             */
            unidad: $porEnvase ? $comoSeCobro['presentacion']->unidad->codigo : null,
        );
    }

    /**
     * El importe lo arma `Monto` y no un `number_format` suelto: la
     * moneda y los dos decimales son suyos, y el día que el hospital
     * facture en otra no hay que buscar el formato en un Blade.
     */
    public function importe(): Monto
    {
        return Monto::de($this->total);
    }

    public function cuantasEntregas(): int
    {
        return count($this->entregas);
    }

    /**
     * La última entrega es la que quita la ✕.
     *
     * Quitar el renglón entero borraría de un clic algo que se cargó bien
     * hace horas; quitar la última es deshacer lo último que se hizo, que
     * es lo que alguien quiere decir cuando aprieta la ✕ sobre una lista
     * que está armando. Dos clics quitan las dos.
     */
    public function ultimaEntrega(): ?Cargo
    {
        /*
         * 🔴 Por índice y NO con `end()`. `end()` recibe el arreglo por
         * referencia porque mueve su puntero interno — o sea que lo
         * modifica — y `entregas` es `readonly`. PHP lo rechaza con
         * «Cannot indirectly modify readonly property», y no en el
         * análisis estático: en tiempo de ejecución, al abrir la cuenta.
         *
         * Con el arreglo vacío, `count() - 1` da -1, ese índice no existe
         * y el `??` devuelve nulo. No hace falta preguntarlo aparte.
         */
        $ultima = $this->entregas[count($this->entregas) - 1] ?? null;

        return $ultima instanceof Cargo ? $ultima : null;
    }

    /**
     * El presupuesto del que este renglón ES el paquete, si lo es.
     *
     * Se reconoce por llevar `presupuesto_id` y NO llevar línea: con las
     * dos cosas sería un consumo previsto, no el paquete (ADR-0009).
     */
    public function presupuestoDelPaquete(): ?Presupuesto
    {
        $primera = $this->entregas[0] ?? null;

        if (! $primera instanceof Cargo) {
            return null;
        }

        if ($primera->presupuesto_id === null || $primera->presupuesto_linea_id !== null) {
            return null;
        }

        return $primera->presupuesto;
    }

    public function sePuedeQuitar(): bool
    {
        return $this->ultimaEntrega()?->admiteAnulacionDirecta() === true;
    }

    /**
     * ¿La ✕ de este renglón tiene que preguntar por qué?
     *
     * La regla vive en `Cargo::pideMotivoParaQuitar()`, que es donde
     * también la consulta el servidor antes de anular. Acá solo se le
     * pregunta para decidir qué dibuja el Blade: la ✕ que quita de una, o
     * la que abre el campo del motivo.
     */
    public function pideMotivoParaQuitar(?int $usuario): bool
    {
        return $this->ultimaEntrega()?->pideMotivoParaQuitar($usuario) === true;
    }
}
