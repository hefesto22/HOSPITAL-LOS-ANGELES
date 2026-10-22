<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordUserLogin;
use App\Policies\ActivityPolicy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ─── Localización global ────────────────────────────────────────
        // Carbon usa el locale para diffForHumans, translatedFormat, etc.
        // Sin esto, las fechas mostrarán "Monday April 26 2026" en vez
        // de "lunes 26 de abril de 2026".
        $locale = (string) config('app.locale', 'es');
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        // setlocale() afecta a strftime() y formatos del sistema PHP.
        // Útil cuando código legacy usa estas funciones.
        @setlocale(LC_TIME, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');
        @setlocale(LC_MONETARY, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');

        // ─── Filament: forzar locale español al renderizar el panel ─────
        // Garantiza que mensajes, validaciones y acciones de Filament
        // siempre estén en español, sin importar el header Accept-Language
        // del browser del usuario.
        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => '',
        );
        // El locale se setea automáticamente al servir Filament.
        Filament::serving(function (): void {
            app()->setLocale((string) config('app.locale', 'es'));
        });

        // ─── Eventos ────────────────────────────────────────────────────
        Event::listen(Login::class, RecordUserLogin::class);

        // ─── Policies que Laravel no encuentra solo ─────────────────────
        // 🔴 Laravel busca la policy de un modelo en el namespace del
        // modelo, y la bitácora es `Spatie\Activitylog\Models\Activity`:
        // nunca encontraba `App\Policies\ActivityPolicy`. Sin policy,
        // Filament PERMITE, así que «Registros de actividad» —quién cambió
        // qué paciente, qué precio, qué factura— lo veía cualquiera con
        // usuario, aunque la matriz solo le diera `ViewAny:Activity` a
        // auditoría y dirección (Mauricio lo vio con consulta externa,
        // 21-oct-2026). Todo modelo de otro paquete con Resource va acá;
        // `MenuPorRolTest` falla si falta uno.
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
