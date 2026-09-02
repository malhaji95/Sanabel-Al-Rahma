<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-38 — admin and council must clear a second factor before any panel screen.
 * Everyone else passes straight through.
 */
class RequireTwoFactor
{
    public const SESSION_KEY = 'sanabel.two_factor_passed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        // Enrolling and challenging both live on the same page, so it must stay reachable.
        if ($request->routeIs('filament.*.pages.two-factor') || $request->routeIs('filament.*.auth.logout')) {
            return $next($request);
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $next($request);
        }

        return redirect()->to(\App\Filament\Pages\TwoFactor::getUrl(panel: filament()->getCurrentPanel()?->getId()));
    }
}
