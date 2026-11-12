<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Pages\ConsumoDeProductos;
use App\Models\Almacen;
use App\Models\Cargo;
use App\Models\Item;
use App\Models\Sede;
use App\Models\User;
use App\Services\ConsumoDelPeriodo;
use App\Support\BitacoraDeLectura;
use App\Support\ContextoSede;
use App\Support\Periodo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * El consumo de productos, para papel (Mauricio, 11-nov-2026: «que se
 * pueda imprimir»). Lo mismo que la pantalla —cuántos usos, qué productos
 * y de qué estantes— y, si se pide, cada uso uno por uno con el paciente.
 *
 * Como el Reporte del mes: una página que se imprime con Ctrl+P, nada de
 * PDF armado en el servidor (§13.4). Los números salen de
 * `ConsumoDelPeriodo`, igual que la pantalla.
 *
 * 🔴 Con el mismo candado que la pantalla —ver almacenes y ver cargos— y
 * a la bitácora de lectura cada vez: «con cada uso» lleva nombres de
 * pacientes (§9.E8, §9.L12).
 */
final class ConsumoDeProductosController extends Controller
{
    public function __construct(private readonly ConsumoDelPeriodo $consumo) {}

    public function __invoke(Request $peticion): View
    {
        /* El permiso, ANTES de leer nada. */
        abort_unless(ConsumoDeProductos::canAccess(), 403);

        [$desde, $hasta] = Periodo::de($peticion->query('desde'), $peticion->query('hasta'));

        abort_if(
            Periodo::dias($desde, $hasta) > ConsumoDelPeriodo::DIAS_MAXIMOS,
            422,
            'El consumo impreso cubre como máximo un año. Elegí un período más corto.',
        );

        $almacen = self::entero($peticion->query('almacen'));
        $producto = self::entero($peticion->query('producto'));
        $origen = in_array($peticion->query('origen'), ['sin_inventario', 'inventario'], true)
            ? (string) $peticion->query('origen')
            : null;
        $conDetalle = $peticion->boolean('detalle');

        $etiqueta = Periodo::etiqueta($desde, $hasta);
        $nombreDelAlmacen = self::nombreDelAlmacen($almacen);
        $nombreDelProducto = $producto === null ? null : Item::query()->whereKey($producto)->value('nombre');
        $usuario = $peticion->user();

        BitacoraDeLectura::registrar(
            recursoTipo: Cargo::class,
            accion: AccionDeLectura::Imprimir,
            motivoTexto: 'CONSUMO DE PRODUCTOS · '.mb_strtoupper($etiqueta)
                .($nombreDelAlmacen === null ? '' : ' · '.mb_strtoupper($nombreDelAlmacen))
                .($conDetalle ? ' · CON CADA USO' : ''),
        );

        $parametros = array_filter([
            'desde'    => $desde,
            'hasta'    => $hasta,
            'almacen'  => $almacen,
            'producto' => $producto,
            'origen'   => $origen,
        ], fn (mixed $valor): bool => $valor !== null);

        $usos = $conDetalle
            ? $this->consumo->unoPorUno($desde, $hasta, $almacen, $producto, $origen, ConsumoDelPeriodo::USOS_EN_EL_PAPEL)
            : null;

        return view('reportes.consumo', [
            'productos'         => $this->consumo->porProducto($desde, $hasta, $almacen),
            'usos'              => $usos,
            'etiqueta'          => $etiqueta,
            'nombreDelAlmacen'  => $nombreDelAlmacen,
            'nombreDelProducto' => is_string($nombreDelProducto) ? $nombreDelProducto : null,
            'origen'            => $origen,
            'sede'              => $this->membrete(),
            'incrustada'        => $peticion->boolean('incrustada'),
            'impresoPor'        => $usuario instanceof User ? (string) $usuario->name : '',
            'impresoEl'         => now()->format('d/m/Y H:i'),
            'volver'            => ConsumoDeProductos::getUrl(array_intersect_key($parametros, array_flip(['desde', 'hasta', 'almacen', 'producto']))),
            'otraVersion'       => route('reportes.consumo', $parametros + ($conDetalle ? [] : ['detalle' => 1])),
        ]);
    }

    /**
     * «Sin estante» es el 0: los cargos de antes del 13-oct, sin almacén.
     */
    private static function nombreDelAlmacen(?int $almacen): ?string
    {
        if ($almacen === null) {
            return null;
        }

        if ($almacen === ConsumoDelPeriodo::SIN_ALMACEN) {
            return 'Sin estante';
        }

        $nombre = Almacen::query()->deTodasLasSedes()->whereKey($almacen)->value('nombre');

        return is_string($nombre) ? $nombre : null;
    }

    private static function entero(mixed $valor): ?int
    {
        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }

    /**
     * El membrete: la sede de quien imprime o, sin ninguna, la primera; y
     * si no hay, el papel sale con el nombre de la aplicación.
     */
    private function membrete(): ?Sede
    {
        $id = ContextoSede::actualId();

        return $id === null ? Sede::query()->orderBy('id')->first() : Sede::query()->find($id);
    }
}
