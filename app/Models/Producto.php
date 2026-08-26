<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AmbitoCatalogo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que se guarda en el estante — la puerta de farmacia al catálogo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ES EL MISMO `items`, NO UNA TABLA NUEVA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `Producto` hereda de `Item` y usa su misma tabla. No agrega columnas
 * ni relaciones: agrega una PUERTA. Lo que gana el sistema con esto son
 * dos cosas que no se consiguen filtrando la consulta a mano:
 *
 *   · **Permisos propios.** Shield nombra los permisos por modelo, así
 *     que farmacia recibe `Create:Producto` sin recibir `Create:Item`.
 *     Con un solo modelo, quien puede cargar una jeringa puede cargar
 *     también el precio de una cesárea.
 *   · **Una pantalla que no miente.** El listado, el buscador y la ruta
 *     de edición directa por URL ven lo mismo, porque el filtro es un
 *     global scope del modelo y no una condición del Resource (§9.L5:
 *     abrir por ID el registro de otro es el agujero típico de Filament).
 *
 * ⚠️ El global scope va acá y NO en `Item`. `Item` es el modelo que usan
 * cargos, kardex, tarifarios y recepciones; ponerle un scope global lo
 * partiría en dos por debajo y la factura de un ingreso dejaría de poder
 * listar sus propias líneas.
 */
class Producto extends Item
{
    protected static function booted(): void
    {
        static::addGlobalScope('almacenables', function (Builder $consulta): void {
            $consulta->where('items.se_almacena', true);
        });

        /*
         * ⚠️ ESTE `saving` SE REGISTRA ANTES QUE EL DE `Item`, Y NO ES
         * CASUALIDAD.
         *
         * Los listeners corren en orden de registro, y el de `Item`
         * deriva `categoria_ambito` a partir de `se_almacena`. Si esto
         * fuera un `creating` —que se dispara DESPUÉS de `saving`— un
         * producto cuya bandera no viniera escrita en los atributos se
         * guardaría con ámbito «servicios» y la FK compuesta lo
         * rechazaría, o peor: pasaría clasificado del lado equivocado.
         *
         * Se fija en toda escritura hecha a través de este modelo, que
         * es la pantalla de farmacia. Mover un ítem al otro lado se hace
         * con `MoverDeAmbitoAction`, que trabaja sobre `Item` y por eso
         * no pasa por acá.
         */
        static::saving(function (Item $item): void {
            $item->se_almacena = true;
        });

        /*
         * `Item::booted()` deriva `categoria_ambito`, la categoría legal
         * del Art. 30 y la coherencia de las banderas de farmacia. Sin
         * esta llamada, un producto creado desde acá entraría sin nada
         * de eso y reventaría contra los CHECK.
         */
        parent::booted();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LA LLAVE FORÁNEA SIGUE SIENDO `item_id`
     * ─────────────────────────────────────────────────────────────────
     *
     * Eloquent arma la llave de las relaciones `hasMany`/`belongsToMany`
     * a partir del NOMBRE DE LA CLASE, no de la tabla. `Producto` hereda
     * `existencias()`, `lotes()`, `presentaciones()`, `precios()` y
     * `descuentos()` de `Item`, pero desde acá las buscaba por
     * `producto_id` — una columna que no existe en ningún lado.
     *
     * El síntoma es feo y tardío: la pantalla de farmacia entera revienta
     * con «column existencias.producto_id does not exist» la primera vez
     * que alguien la abre, y ni los tests del modelo ni PHPStan lo ven,
     * porque el nombre se resuelve en tiempo de ejecución.
     *
     * Se arregla en un solo lugar: todas esas relaciones piden la llave
     * acá. Y así sigue valiendo para las relaciones que se agreguen a
     * `Item` mañana, sin que nadie tenga que acordarse de este archivo.
     */
    public function getForeignKey(): string
    {
        return 'item_id';
    }

    /**
     * Lo mismo para el nombre de las tablas pivote que Eloquent deduce.
     *
     * Hoy `descuentos()` nombra su tabla a mano (`descuento_item`), así
     * que esto no cambia nada todavía. Está por la relación many-to-many
     * que se agregue después sin nombrarla: sin esto buscaría
     * `descuento_producto`.
     */
    public function joiningTableSegment(): string
    {
        return 'item';
    }

    /**
     * Un producto ES un ítem también para lo polimórfico.
     *
     * La bitácora de actividad guarda el nombre de la clase en
     * `subject_type`. Sin esto, el historial de un mismo registro
     * quedaría partido en dos según por qué pantalla se lo editó, y la
     * auditoría de un medicamento mostraría la mitad de su vida.
     */
    public function getMorphClass(): string
    {
        return Item::class;
    }

    public static function ambito(): AmbitoCatalogo
    {
        return AmbitoCatalogo::Productos;
    }
}
