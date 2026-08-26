<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL CATÁLOGO DE DIAGNÓSTICOS.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ CIE-10 Y NO TEXTO LIBRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Dengue» escrito a mano son DENGUE, dengue, Dengue clásico, D. hemorr.
 * y sindrome febril x dengue. Cinco filas que no se pueden contar, y
 * contar es todo lo que el Art. 180 del Código de Salud pide: la
 * notificación epidemiológica obligatoria. Con texto libre no hay
 * reporte, no hay tendencia y no hay reclamo defendible ante una
 * aseguradora.
 *
 * ─────────────────────────────────────────────────────────────────────
 * VERSIONADO A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Honduras está en preparación para CIE-11 (misión OPS nov-2024) sin
 * fecha. `version` en la llave hace que el día que llegue sea CARGAR
 * DATOS, no migrar esquema — y sobre todo, que un diagnóstico de 2026
 * siga leyéndose con el catálogo de 2026 aunque el hospital ya trabaje
 * con el nuevo. Un expediente que se reinterpreta con el catálogo de
 * hoy no prueba nada de lo que decía ayer (ADR-0004).
 *
 * ⚠️ La carga completa es de OPS y es gratuita. Acá se siembra solo lo
 * que un hospital hondureño ve todas las semanas, para que el sistema
 * sirva desde el primer día sin esperar el archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * La MISMA técnica que `items.nombre_busqueda`: la calcula
         * Postgres, no PHP. El médico escribe «neumonia» sin tilde y
         * «gastroenteritis» mal, y si no aparece a la primera va a
         * elegir cualquier código que sí aparezca — y ahí se arruina
         * justamente la estadística que este catálogo existe para
         * permitir.
         *
         * ⚠️ Las dos cadenas de `translate` tienen que ser idénticas a
         * las de `App\Support\NormalizadorDeTexto`, igual que en items.
         * Hay una prueba que lo compara.
         */
        $expresionBusqueda = <<<'SQL'
            lower(
                regexp_replace(
                    translate(
                        btrim(
                            coalesce(codigo, '') || ' ' || coalesce(descripcion, '')
                        ),
                        'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç',
                        'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc'
                    ),
                    '\s+', ' ', 'g'
                )
            )
            SQL;

        Schema::create('cie10', function (Blueprint $tabla) use ($expresionBusqueda): void {
            $tabla->id();

            $tabla->string('version', 10)->default('CIE-10');
            $tabla->string('codigo', 10);
            $tabla->string('descripcion', 255);

            $tabla->text('descripcion_busqueda')->storedAs($expresionBusqueda);

            /*
             * El capítulo permite agrupar sin pedirle al usuario que sepa
             * que A00–B99 son infecciosas. Nulo mientras no se cargue el
             * archivo completo.
             */
            $tabla->string('capitulo', 120)->nullable();

            /*
             * 🔴 De acá sale el reporte a SESAL. Marcar qué es notificable
             * en el CATÁLOGO y no en cada diagnóstico es lo que hace que
             * la obligación del Art. 180 no dependa de que alguien se
             * acuerde: el sistema sabe solo cuáles hay que reportar.
             */
            $tabla->boolean('es_notificable')->default(false);

            $tabla->date('vigencia_desde')->default('1900-01-01');
            $tabla->date('vigencia_hasta')->nullable();

            $tabla->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX cie10_codigo_por_version
             ON cie10 (version, codigo)'
        );

        /*
         * Búsqueda tolerante por descripción, igual que el catálogo de
         * ítems: quien escribe «neumonia» sin tilde tiene que encontrar
         * «Neumonía», y quien escribe «dengue grave» tiene que llegar
         * aunque el catálogo diga «Dengue hemorrágico».
         */
        DB::statement(
            'CREATE INDEX cie10_descripcion_trgm
             ON cie10 USING gin (descripcion_busqueda gin_trgm_ops)'
        );

        DB::statement(
            'ALTER TABLE cie10
             ADD CONSTRAINT cie10_codigo_canonico
             CHECK (codigo = upper(codigo) AND length(btrim(codigo)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE cie10
             ADD CONSTRAINT cie10_descripcion_no_vacia
             CHECK (length(btrim(descripcion)) >= 3)'
        );

        DB::statement(
            'ALTER TABLE cie10
             ADD CONSTRAINT cie10_vigencia_coherente
             CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cie10');
    }
};
