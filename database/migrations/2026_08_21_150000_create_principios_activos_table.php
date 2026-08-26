<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL PRINCIPIO ACTIVO DEJA DE SER TEXTO LIBRE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA ETIQUETA DEL ESTANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se pega un código en la gaveta del acetaminofén, se escanea, y salen
 * los cuatro productos que lo llevan —tableta, jarabe, supositorio,
 * inyectable— sin que nadie tenga que recordar cómo se escribe. Eso con
 * texto libre no se puede: «ACETAMINOFEN», «Acetaminofén» y
 * «acetaminofen ` ` » son tres cosas distintas para la base y ninguna
 * agrupa a las otras.
 *
 * El código es `PA-0001` y el de barras lo codifica igual que en los
 * ítems: no hace falta columna nueva. Y como ningún ítem empieza con
 * `PA-`, el buscador puede distinguir solo si lo escaneado es un
 * producto —y lo carga— o un principio activo —y filtra la lista.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 «TAMBIÉN LLAMADO» NO ES UN ADORNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hoy el campo es libre y entra en `items.nombre_busqueda`: si alguien
 * escribió «PARACETAMOL» en la ficha del acetaminofén, buscar
 * «paracetamol» lo encuentra. Con un catálogo de nombre canónico esa
 * búsqueda SE PIERDE — salvo que el catálogo guarde también cómo más se
 * le dice. El médico prescribe en el nombre que aprendió, y en Honduras
 * conviven los dos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * MUCHOS A MUCHOS, Y NO UNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un antigripal lleva acetaminofén + clorfenamina + fenilefrina.
 * Amoxicilina + ácido clavulánico. Trimetoprima + sulfametoxazol. Los
 * tres están en cualquier farmacia hospitalaria. Con un solo principio
 * por producto, el segundo queda invisible — y el día que alguien
 * pregunte «¿qué tengo con acetaminofén?» para no duplicar dosis, la
 * respuesta sale incompleta sin que se note.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * La MISMA técnica que `items.nombre_busqueda`: la calcula
         * Postgres, no PHP. Las dos cadenas de `translate` tienen que ser
         * idénticas a las de `App\Support\NormalizadorDeTexto`.
         */
        $expresionBusqueda = <<<'SQL'
            lower(
                regexp_replace(
                    translate(
                        btrim(
                            coalesce(codigo, '') || ' ' ||
                            coalesce(nombre, '') || ' ' ||
                            coalesce(tambien_llamado, '')
                        ),
                        'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç',
                        'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc'
                    ),
                    '\s+', ' ', 'g'
                )
            )
            SQL;

        Schema::create('principios_activos', function (Blueprint $tabla) use ($expresionBusqueda): void {
            $tabla->id();

            $tabla->string('codigo', 20);
            $tabla->string('nombre');

            /*
             * Los otros nombres con los que se prescribe, separados por
             * coma: «PARACETAMOL», «VITAMINA C, ÁCIDO ASCÓRBICO».
             */
            $tabla->string('tambien_llamado')->nullable();

            $tabla->text('nombre_busqueda')->storedAs($expresionBusqueda);

            /* Clasificación internacional. El acetaminofén es N02BE01. */
            $tabla->string('codigo_atc', 10)->nullable();

            $tabla->text('notas')->nullable();

            $tabla->date('vigencia_desde')->default('2026-01-01');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestamps();
            $tabla->softDeletes();
        });

        DB::statement(
            'CREATE UNIQUE INDEX principios_activos_codigo_unico
             ON principios_activos (codigo)
             WHERE deleted_at IS NULL'
        );

        /*
         * El NOMBRE también es único: dos filas «ACETAMINOFEN» son el
         * problema que este catálogo vino a resolver, y dejarlas entrar
         * por la puerta de atrás lo volvería otro campo libre con más
         * pasos.
         */
        DB::statement(
            'CREATE UNIQUE INDEX principios_activos_nombre_unico
             ON principios_activos (nombre)
             WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE INDEX principios_activos_busqueda_trgm
             ON principios_activos USING gin (nombre_busqueda gin_trgm_ops)'
        );

        DB::statement(
            "ALTER TABLE principios_activos
             ADD CONSTRAINT principios_codigo_canonico
             CHECK (codigo = upper(codigo) AND codigo !~ '\\s' AND length(btrim(codigo)) >= 3)"
        );

        DB::statement(
            'ALTER TABLE principios_activos
             ADD CONSTRAINT principios_nombre_no_vacio
             CHECK (length(btrim(nombre)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE principios_activos
             ADD CONSTRAINT principios_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );

        // ── Qué lleva cada producto ───────────────────────────────────
        Schema::create('item_principio_activo', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $tabla->foreignId('principio_activo_id')->constrained('principios_activos')->restrictOnDelete();

            /*
             * Cuánto lleva de ese principio: «500 MG», «120 MG/5 ML».
             * Va acá y no en el principio activo porque es del PRODUCTO —
             * el acetaminofén es el mismo en la tableta de 500 y en el
             * jarabe de 120 por cada 5 ml.
             */
            $tabla->string('concentracion', 60)->nullable();

            $tabla->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX item_principio_sin_repetir
             ON item_principio_activo (item_id, principio_activo_id)'
        );

        /*
         * `restrictOnDelete` del lado del principio a propósito: borrar
         * un principio activo que veinte productos declaran dejaría esos
         * veinte sin poder explicar qué llevan. Se retira con fecha de
         * fin de vigencia, como todo catálogo acá.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('item_principio_activo');
        Schema::dropIfExists('principios_activos');
    }
};
