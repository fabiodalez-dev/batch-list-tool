<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\TwoFactorLogin;
use App\Filament\Widgets\DocumentsPerBatchChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\PendingDisinfestationTable;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Support\Avatars\LocalAvatarProvider;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Batch List Tool')
            // Solid-color wordmark in Fraunces (no gradient text per project rules).
            ->brandLogo(asset('images/brand-logo.svg'))
            ->brandLogoHeight('1.75rem')
            // RFQ §3.1.7 hardening — TwoFactorLogin is a stock Login subclass
            // that re-routes users with a confirmed TOTP secret to Fortify's
            // /two-factor-challenge endpoint after their password is validated.
            // Users without 2FA enrolment authenticate exactly as before.
            ->login(TwoFactorLogin::class)
            ->passwordReset()
            ->profile()
            // Security Baseline §15: NO external CDNs at runtime —
            // Inter font is served from /fonts/inter/ (rsms/inter v4.1, OFL-1.1)
            ->font(
                family: 'Inter',
                url: '/fonts/inter/InterVariable.woff2',
                provider: LocalFontProvider::class,
            )
            // ui-avatars.com replaced by laravolt/avatar (server-side SVG)
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            // Compile the warm cream/coffee/orange admin theme through Vite.
            // Source: resources/css/filament/admin/theme.css (Tailwind v4).
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            // Brand palette — pinned to the exact menubello.com terracotta
            // brick #D46039 at the 500 step. Steps mirror the
            // --brand-orange-* CSS variables in
            // resources/css/filament/admin/theme.css.
            ->colors([
                'primary' => [
                    50  => '#FBEEE8',
                    100 => '#F5D9CC',
                    200 => '#ECB59C',
                    300 => '#E18E6A',
                    400 => '#D87148',
                    500 => '#D46039',
                    600 => '#B14C2A',
                    700 => '#8D3B20',
                    800 => '#682B17',
                    900 => '#451B0E',
                    950 => '#270E07',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // Dashboard widgets — order matters: highest-value above the fold.
            ->widgets([
                StatsOverviewWidget::class,
                PendingDisinfestationTable::class,
                DocumentsPerSeriesChart::class,
                DocumentsPerBatchChart::class,
                RecentActivityWidget::class,
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
            ]);
    }
}
