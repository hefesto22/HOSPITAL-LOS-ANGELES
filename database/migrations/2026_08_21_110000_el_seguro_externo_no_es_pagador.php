<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EL SEGURO EXTERNO SE ANOTA, NO SE LE COBRA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PREGUNTA QUE SEPARA LOS DOS CASOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * **¿El hospital le factura a la aseguradora?**
 *
 * Si SÍ, es un convenio: hay tarifario pactado, hay crédito, y queda una
 * cuenta por cobrar contra la aseguradora. El paciente paga su deducible
 * y se va.
 *
 * Si NO —la aseguradora con la que no hay convenio—, el paciente paga
 * TODO en caja al precio de lista y después reclama él. El hospital no
 * tiene nada que cobrarle a nadie más.
 *
 * 🔴 Meter el segundo caso como pagador es el error caro: el sistema
 * cree que hay algo que cobrarle y queda una cuenta por cobrar contra
 * una aseguradora que nunca recibió una factura y que no sabe que
 * existe. Nadie entiende después de dónde salió ese saldo.
 *
 * `reembolso` existe para que la aseguradora se dé de alta UNA vez —con
 * su nombre, su RTN y su contacto— en vez de escribirse de veinte formas
 * distintas en un campo de texto libre, y para que salga impresa junto a
 * `cuentas.numero_poliza`, que es lo que el paciente necesita para
 * reclamar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS DOS CHECK QUE DECÍAN «CONTADO» AHORA DICEN «NO PAGA UN TERCERO»
 * ─────────────────────────────────────────────────────────────────────
 *
 * No fían crédito y no cubren nada. Eran reglas del contado y siguen
 * siendo las mismas: lo que cambió es que ahora hay DOS tipos que no
 * pagan, y dejar los CHECK atados al nombre del tipo viejo permitiría
 * darle treinta días de crédito a una aseguradora a la que jamás se le
 * va a mandar una factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⚠️ El nombre es `convenios_tipo_conocido`, el que le puso la
         * migración que creó la tabla. La primera versión de esto
         * inventó `convenios_tipo_valido`: el DROP no encontró nada, el
         * ADD creó un segundo CHECK al lado, y el viejo —con los cuatro
         * tipos de antes— siguió rechazando `reembolso`. Postgres no
         * avisa de un DROP IF EXISTS que no borra nada.
         *
         * El DROP del nombre inventado queda para limpiar las bases donde
         * esa versión alcanzó a correr.
         */
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_tipo_valido');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_tipo_conocido');
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_tipo_conocido
             CHECK (tipo IN ('contado', 'aseguradora_privada', 'seguridad_social', 'institucional', 'reembolso'))"
        );

        /*
         * Estos dos SÍ cambian de nombre a propósito: la regla dejó de
         * ser «el contado no» y pasó a ser «quien no paga un tercero no».
         * Un CHECK que se llama distinto de lo que exige es una trampa
         * para el que lo lea en un `\d convenios` dentro de dos años.
         */
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_contado_sin_credito');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_sin_credito_si_no_paga_un_tercero');
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_sin_credito_si_no_paga_un_tercero
             CHECK (tipo NOT IN ('contado', 'reembolso') OR dias_credito IS NULL)"
        );

        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_contado_no_cubre');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_no_cubre_si_no_paga_un_tercero');
        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_no_cubre_si_no_paga_un_tercero
             CHECK (tipo NOT IN ('contado', 'reembolso') OR (cobertura_fraccion = 0 AND cubre_por_defecto = false))"
        );
    }

    /**
     * ⚠️ Volver atrás con filas de tipo `reembolso` cargadas hace fallar
     * el CHECK, y está bien que falle: primero hay que decidir qué pasa
     * con esas aseguradoras. Postgres valida las filas existentes al
     * agregar la restricción, así que el error sale con nombre y todo.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_no_cubre_si_no_paga_un_tercero');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_sin_credito_si_no_paga_un_tercero');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_tipo_conocido');
        DB::statement('ALTER TABLE convenios DROP CONSTRAINT IF EXISTS convenios_tipo_valido');

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_contado_no_cubre
             CHECK (tipo <> 'contado' OR (cobertura_fraccion = 0 AND cubre_por_defecto = false))"
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_contado_sin_credito
             CHECK (tipo <> 'contado' OR dias_credito IS NULL)"
        );

        DB::statement(
            "ALTER TABLE convenios
             ADD CONSTRAINT convenios_tipo_conocido
             CHECK (tipo IN ('contado', 'aseguradora_privada', 'seguridad_social', 'institucional'))"
        );
    }
};
