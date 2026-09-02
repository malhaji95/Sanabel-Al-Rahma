<?php

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\RequireTwoFactor;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * T-17 — the association panel. An association sees its own and referred cases
 * only; anything outside that scope is reachable only through the four-value
 * coordination lookup.
 */
class AssociationPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('association')
            ->path('association')
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
            ->discoverResources(in: app_path('Filament/Association/Resources'), for: 'App\\Filament\\Association\\Resources')
            ->discoverPages(in: app_path('Filament/Association/Pages'), for: 'App\\Filament\\Association\\Pages')
            ->pages([Pages\Dashboard::class])
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
