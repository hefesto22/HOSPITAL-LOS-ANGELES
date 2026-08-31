<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El logo que va impreso en la factura de esta sede.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES EL LOGO DEL PANEL
 * ─────────────────────────────────────────────────────────────────────
 *
 * `branding_settings.logo_path` ya guarda una imagen, pero esa es la
 * marca del SISTEMA: la que se ve al entrar y en la pestaña del
 * navegador. Esta es otra cosa —el membrete del establecimiento que
 * emite el documento fiscal— y por eso vive en la sede: el día que el
 * hospital abra una segunda, cada una imprime la suya con su propio RTN
 * y su propio código de establecimiento al lado.
 *
 * Nulo se acepta y no rompe nada: la factura sigue saliendo con el
 * nombre en texto, que es como salía hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $tabla): void {
            /*
             * La RUTA dentro del disco público, no la imagen. Guardar el
             * binario en la fila haría que cada consulta de sede
             * —y son muchas: la sede se lee en cada cargo— arrastre
             * cientos de kilobytes que nadie va a mirar.
             */
            $tabla->string('logo_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $tabla): void {
            $tabla->dropColumn('logo_path');
        });
    }
};
