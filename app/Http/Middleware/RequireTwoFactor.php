<?php

namespace App\Http\Middleware;

use App\Filament\Pages\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-38 — admin and council must clear a second factor before any panel screen.
 * Everyone else passes straight through.
 *
 * T-179 — and the gate can be lifted from the server's environment, for a
 * training afternoon or a demonstration. Enforced unless `SANABEL_TWO_FACTOR`
 * says otherwise; see `config/sanabel.php` for why it is read from there and
 * not from the settings screen.
 */
class RequireTwoFactor
{
    public const SESSION_KEY = 'sanabel.two_factor_passed_at';

    /**
     * Whether the gate is being enforced at all.
     *
     * Asked here rather than inside `User::requiresTwoFactor()`, which answers
     * a different question — whether this role is meant to carry a second
     * factor — and is what keeps the enrolment page reachable while the gate
     * is lifted, and what the red band reads to know it should be drawn.
     */
    public static function enforced(): bool
    {
        return (bool) config('sanabel.two_factor_enforced', true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enforced()) {
            return $next($request);
        }

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

        return redirect()->to(TwoFactor::getUrl(panel: filament()->getCurrentPanel()?->getId()));
    }
}
