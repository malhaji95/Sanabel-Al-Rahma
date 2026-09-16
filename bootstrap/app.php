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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
