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
        // The inbound-email webhook is authenticated by a shared secret, not a
        // session CSRF token, so exempt it from CSRF verification.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // 3b — baseline security response headers on every response.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Public API v1 — key auth + scope enforcement (see routes/api.php).
        $middleware->alias([
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
