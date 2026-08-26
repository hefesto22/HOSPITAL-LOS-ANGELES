<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Almacen;
use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Models\Existencia;
use App\Support\AlmacenesDelUsuario;
use App\Support\UsuarioAutenticado;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abrir un conteo físico: la planilla, no el ajuste.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EN UN CONTEO TOTAL LAS LÍNEAS SE CARGAN AL ABRIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se crea una línea por cada existencia con saldo del almacén, todas
 * **pendientes** —sin cantidad contada, sin saldo congelado—. Así el que
 * cuenta sabe cuántas le faltan y el sistema sabe qué exigir antes de
 * cerrar.
 *
 * ⚠️ Cargarlas al abrir NO congela nada. El corte va por línea, en el
 * instante en que se teclea cada conteo: si congeláramos acá, todo lo que
 * se despache durante las tres horas siguientes aparecería como faltante.
 *
 * En un conteo **parcial** no se carga ninguna línea: se agregan a medida
 * que se escanea. Es como se cuenta de verdad un anaquel de controlados
 * por turno, o el único producto que no cuadra.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNO SOLO ABIERTO POR ALMACÉN, Y LO DECIDE LA BASE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se consulta antes por cortesía —para dar un mensaje que se entienda—
 * pero el que manda es el índice único parcial: entre la consulta y el
 * `INSERT` hay una ventana, y en esa ventana entra el segundo bodeguero.
 */
final class AbridorDeConteo
{
    /**
     * @throws ConteoException
     */
    public function abrir(
        Almacen $almacen,
        AlcanceDeConteo $alcance,
        ?string $descripcion = null,
        ?Decimal $tolerancia = null,
        ?string $notas = null,
    ): Conteo {
        if (UsuarioAutenticado::id() === null) {
            throw ConteoException::faltaQuienCuenta();
        }

        /*
         * Que la consulta de la pantalla ya filtre los almacenes no
         * alcanza: un comando o una pantalla futura pueden llamar acá con
         * cualquiera, y lo que protege el inventario tiene que estar
         * donde se escribe (§9.L5).
         */
        AlmacenesDelUsuario::exigirAcceso($almacen);

        $this->exigirQueNoHayaOtroAbierto($almacen);

        $tolerancia ??= self::toleranciaPorDefecto();

        try {
            /** @var Conteo $conteo */
            $conteo = DB::transaction(function () use (
                $almacen,
                $alcance,
                $descripcion,
                $tolerancia,
                $notas,
            ): Conteo {
                $conteo = Conteo::query()->create([
                    'almacen_id'          => $almacen->id,
                    'estado'              => EstadoConteo::Abierto,
                    'alcance'             => $alcance,
                    'descripcion'         => $descripcion,
                    'tolerancia_recuento' => $tolerancia->paraBase(4),
                    'abierto_en'          => now(),
                    'notas'               => $notas,
                ]);

                if ($alcance->exigeContarTodo()) {
                    $this->cargarLasExistencias($conteo, $almacen);
                }

                return $conteo;
            });
        } catch (QueryException $e) {
            /*
             * El índice único parcial ganó la carrera. Se traduce a un
             * mensaje del dominio en vez de dejar salir un error de SQL:
             * quien lo lee está parado en la bodega, no en un IDE.
             */
            if ($this->hayOtroAbierto($almacen)) {
                throw ConteoException::yaHayUnoAbierto($almacen->nombre);
            }

            throw $e;
        }

        return $conteo->refresh();
    }

    /**
     * Una línea pendiente por cada (ítem, lote) con saldo.
     *
     * Se insertan en bloque y no una por una con Eloquent: un almacén con
     * trescientos productos serían trescientos `INSERT` y trescientos
     * eventos de modelo para escribir filas que no tienen ninguna lógica
     * adentro.
     *
     * @throws ConteoException
     */
    private function cargarLasExistencias(Conteo $conteo, Almacen $almacen): void
    {
        $ahora = now();

        $filas = Existencia::query()
            ->where('existencias.almacen_id', $almacen->id)
            ->conSaldo()
            ->orderBy('existencias.item_id')
            ->get(['existencias.item_id', 'existencias.lote_id'])
            ->map(fn (Existencia $existencia): array => [
                'conteo_id'  => $conteo->id,
                'item_id'    => $existencia->item_id,
                'lote_id'    => $existencia->lote_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])
            ->all();

        if ($filas === []) {
            throw ConteoException::elAlmacenNoTieneNadaQueContar($almacen->nombre);
        }

        $maximo = self::maximoDeLineas();

        if (count($filas) > $maximo) {
            throw ConteoException::demasiadasLineas(count($filas), $maximo);
        }

        ConteoLinea::query()->insert($filas);
    }

    /**
     * @throws ConteoException
     */
    private function exigirQueNoHayaOtroAbierto(Almacen $almacen): void
    {
        if ($this->hayOtroAbierto($almacen)) {
            throw ConteoException::yaHayUnoAbierto($almacen->nombre);
        }
    }

    private function hayOtroAbierto(Almacen $almacen): bool
    {
        return Conteo::query()->abiertos()->delAlmacen($almacen->id)->exists();
    }

    /**
     * A partir de qué diferencia se exige volver a contar.
     *
     * Cero significa «cualquier diferencia exige recuento». Es
     * configuración por instalación y se puede subir por conteo desde la
     * pantalla: contar gasas con tolerancia cero es perder la tarde.
     */
    public static function toleranciaPorDefecto(): Decimal
    {
        $valor = config('sihla.inventario.tolerancia_recuento_por_defecto', '0');

        return Decimal::de(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    /**
     * Cuántas líneas admite un conteo.
     *
     * No es un capricho: cerrar bloquea la fila de costo y la de
     * existencia de cada producto que toca, y una transacción larga sobre
     * el inventario frena la farmacia entera (§13.5).
     */
    public static function maximoDeLineas(): int
    {
        $valor = config('sihla.inventario.maximo_lineas_por_conteo', 300);

        return is_int($valor) ? $valor : 300;
    }
}
