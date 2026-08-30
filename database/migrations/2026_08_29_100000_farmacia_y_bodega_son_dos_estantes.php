<?php

declare(strict_types=1);

use App\Domain\Enums\TipoAlmacen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Farmacia y bodega dejan de ser el mismo estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIA Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El hospital arrancó con un solo almacén. Al separarse físicamente en
 * FARMACIA y BODEGA, esta migración reclasifica lo que ya existía:
 *
 *   · El almacén único pasa a `farmacia_venta`. No es una elección
 *     arbitraria: de ahí se venía dispensando y de ahí salen los cargos
 *     de las cuentas abiertas ahora mismo. Convertirlo en bodega central
 *     lo dejaría sin poder dispensar —`dispensaAPaciente()` es falso en
 *     bodega— y los cargos de hoy fallarían.
 *
 *   · Nace la BODEGA de cada sede, vacía. Lo que hay en el estante de
 *     bodega entra por recepción o por traslado, no por migración:
 *     inventar existencias acá sería crear saldo sin kardex, que es
 *     exactamente lo que el ADR-0004 prohíbe.
 *
 * 🔴 EL KARDEX NO SE TOCA. Ni un movimiento cambia de almacén. Lo que
 * salió del estante único salió del que hoy se llama farmacia, y esa
 * sigue siendo la verdad de lo que pasó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS CARRITOS NO SE CREAN ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * «CARRITO ROJO 1» es un almacén de tipo `stock_de_servicio` y lo crea el
 * hospital desde la pantalla, porque cuántos carros hay y de qué servicio
 * son es un dato del hospital, no del código. Sembrarlos acá dejaría
 * carritos inventados en el listado el primer día.
 */
return new class extends Migration
{
    /**
     * El código de la bodega dentro de cada sede.
     */
    private const CODIGO_BODEGA = 'BODEGA';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->reclasificarElAlmacenUnico();
            $this->crearLaBodegaDeCadaSede();
        });
    }

    /**
     * De `almacen_unico` a `farmacia_venta`, conservando nombre y código.
     *
     * El nombre no se toca: el personal ya lo conoce y aparece impreso en
     * conteos y ajustes viejos. Si dice «ALMACÉN», el hospital lo renombra
     * desde la pantalla cuando quiera.
     */
    private function reclasificarElAlmacenUnico(): void
    {
        DB::table('almacenes')
            ->where('tipo', TipoAlmacen::AlmacenUnico->value)
            ->update([
                'tipo'       => TipoAlmacen::FarmaciaVenta->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Una bodega por sede, si esa sede no tiene ya una.
     *
     * ⚠️ `insert` a mano y no el modelo: una migración que instancia
     * modelos se rompe el día que el modelo gana un scope global, un
     * observer o un campo nuevo. Acá interesa la fila, no el objeto.
     */
    private function crearLaBodegaDeCadaSede(): void
    {
        $sedes = DB::table('sedes')->whereNull('deleted_at')->pluck('id');

        foreach ($sedes as $sedeId) {
            $yaTiene = DB::table('almacenes')
                ->where('sede_id', $sedeId)
                ->where(function (Builder $consulta): void {
                    $consulta
                        ->where('tipo', TipoAlmacen::BodegaCentral->value)
                        ->orWhere('codigo', self::CODIGO_BODEGA);
                })
                ->exists();

            if ($yaTiene) {
                continue;
            }

            DB::table('almacenes')->insert([
                'sede_id'     => $sedeId,
                'servicio_id' => null,
                'codigo'      => self::CODIGO_BODEGA,
                'nombre'      => 'BODEGA',
                'tipo'        => TipoAlmacen::BodegaCentral->value,

                /*
                 * Los controlados llegan del proveedor a bodega antes que a
                 * ningún otro lado, así que el libro de ARSA arranca
                 * encendido. Apagarlo es una decisión que alguien tiene que
                 * tomar a mano; que arranque apagado es un libro que nadie
                 * llevó sin que nadie se enterara.
                 */
                'maneja_controlados' => true,

                'vigencia_desde' => now()->toDateString(),
                'vigencia_hasta' => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    /**
     * La vuelta atrás devuelve el tipo, no las existencias.
     *
     * Se borra la bodega solo si nunca se usó. Una bodega con kardex
     * adentro no se borra ni en un rollback: los movimientos quedarían
     * apuntando a un almacén que no existe, y en controlados eso es
     * justamente lo que ARSA audita.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $bodegas = DB::table('almacenes')
                ->where('tipo', TipoAlmacen::BodegaCentral->value)
                ->where('codigo', self::CODIGO_BODEGA)
                ->pluck('id');

            foreach ($bodegas as $id) {
                $seUso = DB::table('movimientos_kardex')->where('almacen_id', $id)->exists()
                    || DB::table('existencias')->where('almacen_id', $id)->exists();

                if (! $seUso) {
                    DB::table('almacenes')->where('id', $id)->delete();
                }
            }

            DB::table('almacenes')
                ->where('tipo', TipoAlmacen::FarmaciaVenta->value)
                ->update([
                    'tipo'       => TipoAlmacen::AlmacenUnico->value,
                    'updated_at' => now(),
                ]);
        });
    }
};
