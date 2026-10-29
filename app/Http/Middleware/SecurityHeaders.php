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

        if ($this->mustNotStore($request, $config['no_store'])) {
            $this->forbidStoring($response);
        } else {
            $this->allowStoring($response);
        }

        /*
         | T-91 — a demo deployment tells search engines to stay away. The
         | header rather than robots.txt: robots.txt decides whether a crawler
         | fetches a page, this decides whether it keeps it, and the second is
         | the one that matters once the page has been fetched anyway.
         */
        if (! config('sanabel.allow_indexing')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive', false);
        }

        return $response;
    }

    /**
     * T-84 — APP-07. Whether this response may be kept anywhere at all.
     *
     * Any response to a signed-in request, whatever route served it: that is
     * every family file, every panel screen, every donation and every page of
     * the donor's own basket, without a list of them to keep up to date. The
     * configured paths are for what is reachable without signing in and must
     * still never be stored — the signed media route first among them.
     *
     * @param  array<string,mixed>  $config
     */
    private function mustNotStore(Request $request, array $config): bool
    {
        // Read after the response is built, so whichever guard the route uses
        // has already run and this sees the account it authenticated — the
        // session on a panel page, the Sanctum token on an API call.
        if ($config['authenticated'] && $request->user() !== null) {
            return true;
        }

        return $request->is(...$config['paths']);
    }

    /**
     * `no-store` is the one that matters: it tells the browser not to write
     * the response to disk, which is what `no-cache` alone permits. The rest
     * are for the intermediaries that do not read it — Pragma and Expires for
     * an old proxy, and Vary: Cookie so that anything which caches regardless
     * at least cannot serve one person's page to another.
     */
    private function forbidStoring(Response $response): void
    {
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0, private',
        );
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Vary', 'Cookie', false);
    }

    /**
     * T-199 — and a public page stays a public page, whatever rendered it.
     *
     * Livewire marks every response that boots a component `no-store`, so the
     * back button cannot show a component holding yesterday's state. It is a
     * blanket: one counter strip on the front page made the most-visited page
     * on the site unstorable by every browser and every cache in front of it.
     *
     * The decision about what may be kept is `no_store` in config — signed in,
     * or one of the listed paths — and it already says in as many words that
     * the public pages are not in it. This is that decision applied rather
     * than merely stated: a response the rule leaves public is handed back
     * storable, and only `no-store` is lifted. `no-cache` stays, so the
     * browser revalidates before it shows the page again, and `private` keeps
     * it out of any shared cache — which is what `Vary: Cookie` is for too,
     * since the top bar reads the session.
     *
     * A response the application marked itself is left exactly as it is.
     */
    private function allowStoring(Response $response): void
    {
        $control = (string) $response->headers->get('Cache-Control');

        if (! str_contains($control, 'no-store')) {
            return;
        }

        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Vary', 'Cookie', false);

        // Livewire sets both, and an `Expires` in 1990 says `no-store` again
        // to anything too old to read the line above it.
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');
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
