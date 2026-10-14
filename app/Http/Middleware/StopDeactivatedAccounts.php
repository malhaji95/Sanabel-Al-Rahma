<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-93 — IAM-02. An account that is switched off stops working now, not when
 * its session happens to expire.
 *
 * Deactivating an account closed the panel door and nothing else: the person
 * was already signed in, and their session stayed valid for its whole lifetime
 * — on the donor portal, on the family page, on the API with a session cookie.
 * So the one control the association has for a leaving employee or a suspected
 * account took up to two hours to bite, and nobody could see that it had not.
 *
 * The session is destroyed rather than merely refused, so nothing of the
 * previous sign-in survives in it, and the token is rotated for whoever uses
 * the browser next.
 */
class StopDeactivatedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        /*
         | Only a session can be signed out of. A request carrying a bearer
         | token has no session to destroy — the token is refused here and
         | revoked by whoever switched the account off.
         */
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Rule 10 — the message names no account and no person.
        return $request->expectsJson()
            ? response()->json(['message' => __('sanabel.auth.inactive')], 401)
            : redirect()->guest(route('login'))->with('status', __('sanabel.auth.inactive'));
    }
}
