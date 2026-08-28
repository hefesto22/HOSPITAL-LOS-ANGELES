<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué turno pertenece cada persona.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES EL TURNO DE CAJA: ES A CUÁL PERTENECE
 * ─────────────────────────────────────────────────────────────────────
 *
 * `turnos_de_caja` es un HECHO —esta persona abrió la gaveta a las 8 y
 * la cerró a las 4—. Esto es una asignación: «Rosa es del turno B».
 * Cambia cuando dirección rota al personal, no cuando alguien abre caja.
 *
 * Sirve para dos cosas, y las dos son de mostrador:
 *
 *   · Al abrir la caja, el nombre del turno ya viene puesto. Un campo
 *     menos que teclear cuando el primer paciente ya está enfrente.
 *   · En el listado de usuarios se cambia en la propia fila. Cuando
 *     rotan turnos —que pasa seguido— es un clic por persona en vez de
 *     entrar a editar cada ficha.
 *
 * Texto libre y no un enum a propósito: los turnos de un hospital se
 * llaman como el hospital quiera —«A», «Nocturno», «Fin de semana»— y
 * eso no merece una migración cada vez que cambien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->string('turno', 40)->nullable()->after('sede_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('turno');
        });
    }
};
