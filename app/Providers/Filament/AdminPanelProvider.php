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
            // Brand palette — dialed-down terracotta. The 500 step
            // (#B25434) reads "thoughtful brand accent" not "shouty
            // restaurant menu". Chroma cut ~40% from the previous
            // #D46039 brick. Steps mirror the --brand-orange-* CSS
            // variables in resources/css/filament/admin/theme.css.
            ->colors([
                'primary' => [
                    50  => '#FAF1E8',
                    100 => '#F1E0D5',
                    200 => '#E3C0AB',
                    300 => '#D29B7E',
                    400 => '#C57658',
                    500 => '#B25434',
                    600 => '#93432A',
                    700 => '#6D3220',
                    800 => '#4A2317',
                    900 => '#2D160E',
                    950 => '#180B06',
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
