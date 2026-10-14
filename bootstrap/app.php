<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         | The delegate field app and the donor portal call the API routes from
         | the browser, carrying the session cookie and a CSRF token rather than
         | a bearer token. Without this, auth:sanctum only looks for a token and
         | answers 401, so a queued visit could never sync off the device.
         | Service accounts (T-37) keep working: a token still authenticates.
         */
        $middleware->statefulApi();

        /*
         | T-56 — the defensive response headers, on every route including the
         | API and the signed media route, so nothing is served without them.
         */
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        /*
         | T-93 — IAM-02, the half of it that was missing: a password that
         | changes, and an account that is switched off, must end the sessions
         | already open.
         |
         | AuthenticateSession ties a session to the password it was opened
         | with, so changing a password — the one thing an administrator can do
         | for an account they suspect — logs that account out everywhere else
         | at once. The panels carried it already; the donor portal, the family
         | page and the session-authenticated API did not, which is to say it
         | was missing exactly where the families and the money are.
         |
         | And deactivation is checked on every request rather than at the
         | panel door alone, so switching an account off takes effect on the
         | next click instead of at the end of the session's two hours.
         */
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\StopDeactivatedAccounts::class,
        ]);

        $middleware->api(append: [\App\Http\Middleware\StopDeactivatedAccounts::class]);

        /*
         | Render terminates TLS at its edge and forwards over http, so without
         | this Laravel reads the request as insecure: it builds http:// URLs,
         | never sets a Secure cookie and never sends HSTS. The proxy is the
         | platform's own, in front of the container.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
