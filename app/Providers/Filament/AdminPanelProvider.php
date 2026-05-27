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
            // Brand palette — warm orange (h≈50 OKLCH) converted to hex for
            // Filament's color() pipeline. Steps mirror the --brand-orange-*
            // CSS variables in resources/css/filament/admin/theme.css.
            ->colors([
                'primary' => [
                    50  => '#fff2e4',
                    100 => '#ffe1c7',
                    200 => '#ffc091',
                    300 => '#f79f63',
                    400 => '#ef853b',
                    500 => '#e36a00',
                    600 => '#cc4b00',
                    700 => '#ac3600',
                    800 => '#802200',
                    900 => '#541500',
                    950 => '#2e0600',
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
