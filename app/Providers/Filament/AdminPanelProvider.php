<?php

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Widgets\CoverageByRegion;
use App\Filament\Widgets\OverviewStats;
use App\Http\Middleware\RequireTwoFactor;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The internal panel for admin, case officers, supervisors, delegates and
 * council. Arabic and right-to-left; every label comes from lang/ar.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(__('sanabel.app_name'))
            // The name-only version: a panel top bar is not room for the full lockup.
            ->brandLogo(config('brand.logo.wordmark'))
            ->brandLogoHeight('1.75rem')
            ->favicon(config('brand.icons.favicon'))
            ->colors([
                'primary' => Color::hex(config('brand.colors.primary')),
                'warning' => Color::hex(config('brand.colors.gold')),
            ])
            // Self-hosted, so the panels do not depend on an outside font host.
            ->font(
                config('brand.font.family'),
                url: asset('fonts/ibm-plex-sans-arabic.css'),
                provider: LocalFontProvider::class,
            )
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->defaultThemeMode(ThemeMode::System)
            ->navigationGroups([
                NavigationGroup::make(__('sanabel.nav.beneficiaries')),
                NavigationGroup::make(__('sanabel.nav.assessment')),
                NavigationGroup::make(__('sanabel.nav.money')),
                NavigationGroup::make(__('sanabel.nav.modules')),
                NavigationGroup::make(__('sanabel.nav.content')),
                NavigationGroup::make(__('sanabel.nav.reference')),
                NavigationGroup::make(__('sanabel.nav.system')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The numbers first, then the detail behind them.
            ->widgets([
                OverviewStats::class,
                CoverageByRegion::class,
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
                RequireTwoFactor::class,
            ]);
    }
}
