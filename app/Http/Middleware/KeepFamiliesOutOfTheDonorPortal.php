<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-220 — «تقصد حساب عيلة وبدها تتبرع؟ هاد الخيار مرفوض وغير ممكن»,
 * 1 November.
 *
 * T-194 landed a household's account on its own page and took the donor
 * portal out of its menu. That removed the signpost and left the door: an
 * account that typed `/portal` still got the basket, the favourites and the
 * giving history of a donor it is not.
 *
 * So the door is shut. A household asking for a donor screen is sent to its
 * own page rather than refused, because it is not trespassing — it is in the
 * wrong room of a house it belongs in.
 *
 * Every other account passes through untouched, including an account with no
 * role at all: this says who may not pass, and names nobody else.
 */
class KeepFamiliesOutOfTheDonorPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user()?->isFamily()) {
            return redirect()->route('family.portal');
        }

        return $next($request);
    }
}
