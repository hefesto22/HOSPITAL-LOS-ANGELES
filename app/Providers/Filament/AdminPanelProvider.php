<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Models\BrandingSetting;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Path '/' = panel en la raíz. URLs limpias: /, /login, /dashboard, /users.
            // El sistema es 100% panel admin, sin frontend público (decisión de la plantilla).
            ->path('/')
            ->login()
            ->profile()
            // ── Branding dinámico desde BrandingSetting ─────────────────────
            // Cada proyecto que herede la plantilla configura su logo,
            // favicon y color desde el panel sin tocar código.
            ->brandName(fn (): string => env('APP_BRAND_NAME', config('app.name', 'Olympo')))
            ->brandLogo(fn (): ?string => self::brandingValue('logoUrl'))
            ->darkModeBrandLogo(fn (): ?string => self::brandingValue('logoUrl'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): ?string => self::brandingValue('faviconUrl'))
            ->colors([
                'primary' => self::primaryColorPalette(),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => self::hojaDeEstilos())
            /*
             * ─────────────────────────────────────────────────────────
             * EL MENÚ VA EN ORDEN DE USO, NO ALFABÉTICO
             * ─────────────────────────────────────────────────────────
             *
             * Sin esta lista Filament ordena los grupos como se le da la
             * gana —por el primer recurso que descubre— y el menú salía
             * con «Filament Shield» arriba de todo y «Seguridad y
             * auditoría» metida entre «Atención» y «Farmacia».
             *
             * El orden es el de la frecuencia con que se abre cada cosa:
             * primero lo de todos los días en la ventanilla, al final lo
             * que se toca una vez y se deja quieto.
             *
             * ⚠️ Un grupo que no esté en esta lista NO desaparece: cae al
             * final. Pero conviene agregarlo acá, porque el final es
             * donde nadie mira.
             */
            ->navigationGroups([
                'Atención',
                'Consultas',
                'Farmacia',
                'Inventario',
                'Catálogos y precios',
                'Configuración del hospital',
                'Seguridad y auditoría',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->sidebarCollapsibleOnDesktop();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LO QUE SE TECLEA EN UN BUSCADOR SE VE EN MAYÚSCULAS
     * ─────────────────────────────────────────────────────────────────
     *
     * Todo el catálogo del hospital se guarda en mayúsculas —§10.4, y
     * `CampoMayusculas` ya hace que se vean así mientras se escriben—.
     * El hueco era el buscador de los desplegables: ahí se tecleaba
     * «proporfol» en minúscula contra una lista de PROPOFOL, y la
     * pantalla parecía estar hablando de otra cosa.
     *
     * Es SOLO visual, igual que en `CampoMayusculas`: lo que viaja al
     * servidor es lo tecleado, y la búsqueda ignora mayúsculas y tildes.
     * Nadie deja de encontrar nada por esto.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ACÁ Y NO EN `resources/css/app.css`
     * ─────────────────────────────────────────────────────────────────
     *
     * Porque el CSS del proyecto hay que compilarlo, y una regla de tres
     * líneas que exige acordarse de correr el build es una regla que un
     * día no está. Esto viaja con el panel y no depende de ningún paso.
     *
     * ⚠️ La clase la pone Filament, no nosotros: `fi-select-input-search-ctn`
     * es el contenedor del buscador del desplegable (v5.7). Si una
     * actualización la renombra, esto deja de aplicar —se ve minúscula
     * otra vez— y no rompe nada más.
     */
    private static function hojaDeEstilos(): HtmlString
    {
        return new HtmlString(
            '<style>.fi-select-input-search-ctn input{text-transform:uppercase}</style>'
        );
    }

    /**
     * Lee un atributo del singleton BrandingSetting con tolerancia a errores.
     *
     * Si la migración aún no se ha corrido (por ejemplo, durante el primer
     * `migrate` del setup), evitamos que Filament muera intentando leer
     * la tabla. En ese caso retornamos null y Filament usa su default.
     */
    private static function brandingValue(string $atributo): ?string
    {
        try {
            $valor = BrandingSetting::current()->{$atributo} ?? null;

            return is_string($valor) && $valor !== '' ? $valor : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Genera la paleta de colores para Filament a partir del color
     * primario configurado en BrandingSetting. Si falla, usa Amber.
     *
     * @return array<int|string, string>
     */
    private static function primaryColorPalette(): array
    {
        try {
            $hex = BrandingSetting::current()->primary_color;

            if (is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1) {
                return Color::hex($hex);
            }
        } catch (Throwable) {
            // Tabla aún no migrada; usamos default.
        }

        return Color::Amber;
    }
}
