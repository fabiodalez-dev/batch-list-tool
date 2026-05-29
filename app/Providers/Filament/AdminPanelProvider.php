<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\TwoFactorLogin;
use App\Filament\Widgets\DocumentsPerBatchChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\PendingDisinfestationTable;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Settings\BrandingSettings;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        [$brandName, $brandLogo, $brandLogoHeight] = $this->resolveBranding();

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName($brandName)
            // Square NAf foundation mark (raster). Square aspect ratio needs a
            // taller height than the previous horizontal wordmark would have
            // tolerated; 2.25rem keeps the mark legible without breaking the
            // 64-px topbar vertical rhythm.
            ->brandLogo($brandLogo)
            ->brandLogoHeight($brandLogoHeight)
            // RFQ §3.1.7 hardening — TwoFactorLogin is a stock Login subclass
            // that re-routes users with a confirmed TOTP secret to Fortify's
            // /two-factor-challenge endpoint after their password is validated.
            // Users without 2FA enrolment authenticate exactly as before.
            ->login(TwoFactorLogin::class)
            ->passwordReset()
            ->profile(EditProfile::class)
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
                FilamentShieldPlugin::make()
                    ->navigationGroup('Administration')
                    ->navigationSort(30),
            ])
            // Brand palette — dusty-sandstone. 500 step (#A5613D) reads
            // "thoughtful spice" not "brand orange". Lifted from the
            // editorial/spa register: paper-white shell + minimal warm
            // accent. Steps mirror --brand-orange-* in
            // resources/css/filament/admin/theme.css.
            ->colors([
                'primary' => [
                    50 => '#FBF3EC',
                    100 => '#F4E2D5',
                    200 => '#E5C2A8',
                    300 => '#D29D7A',
                    400 => '#BF7B55',
                    500 => '#A5613D',
                    600 => '#874C2E',
                    700 => '#663823',
                    800 => '#442416',
                    900 => '#2A170E',
                    950 => '#170B06',
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
                EnsurePasswordChanged::class,
            ]);
    }

    /**
     * Resolve branding values from BrandingSettings, falling back to hard-coded
     * defaults when the settings table does not exist yet (fresh install, test
     * isolation, CLI commands that run before migrations).
     *
     * Returns [$brandName, $brandLogo, $brandLogoHeight].
     *
     * @return array{string, string, string}
     */
    private function resolveBranding(): array
    {
        try {
            $settings = app(BrandingSettings::class);

            $brandName = $settings->brand_name ?: 'Batch List Tool';
            $brandLogoHeight = $settings->logo_height ?: '2.25rem';

            // Use the stored upload path only when it resolves to a locally
            // served asset — never emit an external URL.
            $logoPath = $settings->logo_path ?? '';
            if (
                $logoPath !== ''
                && $logoPath !== 'images/brand-logo.png'
                && Storage::disk('public')->exists($logoPath)
            ) {
                $brandLogo = Storage::disk('public')->url($logoPath);
            } else {
                $brandLogo = asset('images/brand-logo.png');
            }
        } catch (\Throwable) {
            // Settings table missing (fresh install, migration not yet run).
            $brandName = 'Batch List Tool';
            $brandLogo = asset('images/brand-logo.png');
            $brandLogoHeight = '2.25rem';
        }

        return [$brandName, $brandLogo, $brandLogoHeight];
    }
}
