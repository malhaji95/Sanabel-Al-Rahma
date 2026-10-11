<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-56 — the response headers a browser needs in order to defend the page.
 *
 * Answers APP-06 of the security roadmap. Every value is config, not code
 * (CLAUDE.md §5), so the association can tighten a directive without a deploy.
 *
 * On the content policy, honestly: Livewire injects inline script and Alpine
 * compiles expressions with `new Function`, so 'unsafe-inline' and
 * 'unsafe-eval' have to stay on script-src until the front end is built with
 * nonces. What the policy does buy, and it is most of the value, is that no
 * script, style, font, frame or form target from another origin is allowed at
 * all: a successful injection cannot call home, the page cannot be framed, and
 * a form cannot be pointed at somebody else's server.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $config = config('sanabel.security');

        foreach ($config['headers'] as $header => $value) {
            if ($value !== null && $value !== '') {
                $response->headers->set($header, $value, false);
            }
        }

        if ($policy = $this->contentSecurityPolicy($config['csp'])) {
            $response->headers->set(
                $config['csp']['report_only'] ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
                $policy,
                false,
            );
        }

        // Only over TLS. Sent on a plain request it is ignored by the browser,
        // and on a local http deployment it would lock the operator out of
        // their own site for a year.
        if ($request->secure() && $config['hsts']['enabled']) {
            $response->headers->set('Strict-Transport-Security', $this->hsts($config['hsts']), false);
        }

        return $response;
    }

    /** @param  array<string,mixed>  $csp */
    private function contentSecurityPolicy(array $csp): ?string
    {
        if (! $csp['enabled']) {
            return null;
        }

        $directives = [];

        foreach ($csp['directives'] as $directive => $sources) {
            $directives[] = $sources === []
                ? $directive
                : $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $directives);
    }

    /** @param  array<string,mixed>  $hsts */
    private function hsts(array $hsts): string
    {
        $value = 'max-age='.(int) $hsts['max_age'];

        if ($hsts['include_subdomains']) {
            $value .= '; includeSubDomains';
        }

        // Off by default: preloading is submitted once and is painful to undo,
        // so it is the association's call on their own domain.
        if ($hsts['preload']) {
            $value .= '; preload';
        }

        return $value;
    }
}
