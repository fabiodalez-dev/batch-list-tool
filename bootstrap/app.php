<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Security baseline §4-§6: global HTTP security headers + CSP + honeypot
        $middleware->append([
            \Bepsvpt\SecureHeaders\SecureHeadersMiddleware::class,
            \Spatie\Csp\AddCspHeaders::class,
        ]);
        // Idempotency middleware alias removed — sobhanatar/idempotency not yet
        // installed. Re-add `composer require sobhanatar/idempotency` first,
        // then restore the alias and apply it to the write routes that need it.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
