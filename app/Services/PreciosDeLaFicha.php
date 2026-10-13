<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoConvenio;
use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Tarifario;
use App\Support\NumeroDeFormulario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Los precios de un servicio, escritos en su misma ficha (13-oct-2026).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «debe ser rápido de registrar, y se deben poder editar los
 * precios desde acá también». Antes, la ficha pedía solo el precio de
 * lista al crear, y cada seguro se cargaba después con «Fijar un precio»:
 * un modal de seis campos por pagador, con motivo obligatorio.
 *
 * Ahora la ficha muestra una fila por pagador —el precio de lista y cada
 * convenio vigente— con su precio de hoy escrito. Se cambia el número y
 * se guarda; lo que no se tocó no se toca.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CAMBIAR SIGUE SIENDO FIJAR, CON FECHA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Por abajo es lo mismo de siempre (`FijadorDePrecio`, ADR-0003): el
 * precio vigente de ese pagador se cierra ayer y el nuevo rige desde hoy.
 * Las facturas ya emitidas no cambian —cada cargo guarda su número—.
 *
 * Solo las filas BASE: sin sede y sin envase. Un precio de una sede o de
 * una variante —el del Dr. Fulano— se fija en la lista de precios de
 * abajo, con su modal completo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ERROR DE TECLEO DEL MISMO DÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * El tarifario va por días: un precio que ya arrancó hoy no se puede
 * cerrar «ayer» para abrir otro hoy. Sin esto, quien escribe 3000 en vez
 * de 300 y se da cuenta al minuto no podría corregirlo hasta mañana.
 *
 * Si la fila de hoy todavía no se usó en ningún cargo ni presupuesto, se
 * corrige ahí mismo y el motivo lo dice —«CORREGIDO EL MISMO DÍA, ANTES
 * L 3,000.00»—: no hay factura que explicar con el número viejo. Si ya se
 * cobró con ella, no se toca: el cambio tiene que esperar a mañana.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CENTINELA DE L 10
 * ─────────────────────────────────────────────────────────────────────
 *
 * El precio de lista que nadie fijó vale L 10 con el motivo «PENDIENTE
 * PRECIO DE LISTA» (`ListaPendiente`). La ficha no lo muestra como un
 * precio: deja el campo vacío y dice «pendiente», para que se escriba el
 * de verdad.
 */
final class PreciosDeLaFicha
{
    public const LISTA = 'lista';

    public const MOTIVO_AL_CREAR = 'PRECIO FIJADO AL DAR DE ALTA EL SERVICIO';

    public const MOTIVO_AL_EDITAR = 'PRECIO CAMBIADO DESDE LA FICHA DEL SERVICIO';

    private const PREFIJO = 'c';

    private const MARCA_PENDIENTE = 'PENDIENTE PRECIO DE LISTA';

    public function __construct(
        private readonly FijadorDePrecio $fijador,
    ) {}

    /**
     * Los pagadores de la ficha: el precio de lista primero y después cada
     * convenio vigente. El de contado no va: el paciente particular paga
     * el precio de lista.
     *
     * @return list<array{clave: string, convenio: Convenio|null}>
     */
    public function pagadores(): array
    {
        $pagadores = [['clave' => self::LISTA, 'convenio' => null]];

        $convenios = Convenio::query()
            ->vigentesEn(now())
            ->where('tipo', '!=', TipoConvenio::Contado->value)
            ->orderBy('nombre')
            ->get();

        foreach ($convenios as $convenio) {
            $pagadores[] = ['clave' => self::claveDe((int) $convenio->id), 'convenio' => $convenio];
        }

        return $pagadores;
    }

    /**
     * Lo que rige ese día para cada pagador: la fila base, sin sede ni
     * envase.
     *
     * @return array<string, Tarifario>
     */
    public function vigentes(?Item $item, ?CarbonInterface $dia = null): array
    {
        if (! $item instanceof Item || ! $item->exists) {
            return [];
        }

        $filas = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('sede_id')
            ->whereNull('item_presentacion_id')
            ->vigentesEn($dia ?? now())
            ->get();

        $porClave = [];

        foreach ($filas as $fila) {
            $porClave[$fila->convenio_id === null ? self::LISTA : self::claveDe($fila->convenio_id)] = $fila;
        }

        return $porClave;
    }

    /**
     * Lo que la ficha trae escrito al abrir: el precio de hoy de cada
     * pagador, o vacío si no tiene uno propio —o si el de lista es el
     * centinela—.
     *
     * @return array<string, string>
     */
    public function estadoInicial(?Item $item): array
    {
        $vigentes = $this->vigentes($item);
        $estado = [];

        foreach ($this->pagadores() as $pagador) {
            $fila = $vigentes[$pagador['clave']] ?? null;

            $estado[$pagador['clave']] = $fila instanceof Tarifario && ! self::esPendiente($fila)
                ? ItemPresentacion::sinCerosDeMas(Decimal::de($fila->precio)->redondeado(4))
                : '';
        }

        return $estado;
    }

    /**
     * ¿Es el centinela de L 10 que nadie fijó?
     */
    public static function esPendiente(Tarifario $fila): bool
    {
        return $fila->convenio_id === null
            && str_starts_with(mb_strtoupper(trim($fila->motivo)), self::MARCA_PENDIENTE);
    }

    /**
     * Fija los precios que cambiaron. Devuelve a quién, por nombre.
     *
     *   · Vacío no cambia nada. Para el precio de lista, vacío solo vale
     *     si ya tiene uno: un servicio sin precio de lista no se puede
     *     cobrar a nadie.
     *   · Al crear, rige desde que arranca el servicio; al editar, desde
     *     hoy.
     *
     * Todo o nada: si un precio no se puede fijar, no queda ninguno.
     *
     * @param array<array-key, mixed> $precios clave del pagador => lo escrito
     *
     * @return list<string>
     *
     * @throws PrecioNoFijableException
     */
    public function aplicar(Item $item, array $precios, ?string $porQue, bool $esAlta): array
    {
        $desde = $esAlta ? $item->vigencia_desde : now();
        $motivo = self::motivo($porQue, $esAlta);

        /** @var list<string> $cambiados */
        $cambiados = DB::transaction(function () use ($item, $precios, $desde, $motivo): array {
            $vigentes = $this->vigentes($item, $desde);
            $hechos = [];

            foreach ($this->pagadores() as $pagador) {
                $convenio = $pagador['convenio'];
                $nombre = $convenio instanceof Convenio ? $convenio->nombre : 'el precio de lista';
                $escrito = $precios[$pagador['clave']] ?? null;
                $fila = $vigentes[$pagador['clave']] ?? null;

                if (! is_scalar($escrito) || trim((string) $escrito) === '') {
                    if (! $convenio instanceof Convenio && ! $fila instanceof Tarifario) {
                        throw PrecioNoFijableException::faltaElDeLista($item->nombre);
                    }

                    continue;
                }

                $precio = NumeroDeFormulario::aDecimal($escrito);

                if (! $precio instanceof Decimal || $precio->mayorQue('999999999')) {
                    throw PrecioNoFijableException::noSeEntiende($nombre, trim((string) $escrito));
                }

                if ($fila instanceof Tarifario && ! self::esPendiente($fila) && Decimal::de($fila->precio)->igualA($precio)) {
                    continue;
                }

                if ($fila instanceof Tarifario && $fila->vigencia_desde->isSameDay($desde)) {
                    self::corregirElDeHoy($item, $fila, $precio, $motivo, $nombre);
                } else {
                    $this->fijador->fijar(
                        item: $item,
                        convenio: $convenio,
                        sede: null,
                        precio: Monto::de($precio),
                        motivo: $motivo,
                        desde: $desde,
                    );
                }

                $hechos[] = $convenio instanceof Convenio ? $convenio->nombre : 'Precio de lista';
            }

            return $hechos;
        });

        return $cambiados;
    }

    private static function claveDe(int $convenioId): string
    {
        return self::PREFIJO.$convenioId;
    }

    /**
     * El motivo de lo que se fija: dice de dónde vino, y el porqué que se
     * haya escrito. Siempre pasa el CHECK de diez letras del tarifario.
     */
    private static function motivo(?string $porQue, bool $esAlta): string
    {
        $base = $esAlta ? self::MOTIVO_AL_CREAR : self::MOTIVO_AL_EDITAR;
        $texto = mb_strtoupper(trim($porQue ?? ''));

        return $texto === '' ? $base : mb_substr($base.': '.$texto, 0, 255);
    }

    /**
     * El precio que ya arrancó hoy y está mal tecleado: se corrige ahí
     * mismo si todavía nadie lo usó.
     *
     * @throws PrecioNoFijableException
     */
    private static function corregirElDeHoy(Item $item, Tarifario $fila, Decimal $precio, string $motivo, string $nombre): void
    {
        Tarifario::query()->whereKey($fila->id)->lockForUpdate()->first(['id']);

        $antes = Monto::de($fila->precio)->formateado();

        $usado = DB::table('cargos')->where('tarifario_id', $fila->id)->exists()
            || DB::table('presupuesto_lineas')->where('tarifario_id', $fila->id)->exists();

        if ($usado) {
            throw PrecioNoFijableException::yaSeUsoHoy($item->nombre, $nombre, $antes);
        }

        $fila->forceFill([
            'precio' => $precio->paraBase(4),
            'motivo' => mb_substr('CORREGIDO EL MISMO DÍA, ANTES '.$antes.'. '.$motivo, 0, 255),
        ])->save();
    }
}
