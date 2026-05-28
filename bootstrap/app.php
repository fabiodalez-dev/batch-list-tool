<?php

use Bepsvpt\SecureHeaders\SecureHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // URL portability — the app must serve correctly from ANY URL: root
        // domain, sub-domain, or sub-folder, including behind a reverse proxy
        // (LiteSpeed/cPanel, nginx, a load balancer). Trusting the proxy's
        // X-Forwarded-* headers lets Laravel reconstruct the real external
        // scheme / host / port / path-prefix, so every generated link, asset
        // URL, redirect and cookie matches the address the user actually typed
        // — without hard-coding APP_URL per deployment.
        //
        // X-Forwarded-Prefix is included so a sub-folder mount (e.g.
        // https://example.org/archive/) resolves routes + assets under that
        // prefix. We trust '*' because the application is only reachable
        // through its fronting proxy in every supported deployment (cPanel/
        // LiteSpeed, nginx, a load balancer). To restrict trust to specific
        // proxy IPs/CIDRs, replace '*' with the list here.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // Security baseline §4-§6: global HTTP security headers + CSP + honeypot
        $middleware->append([
            SecureHeadersMiddleware::class,
            AddCspHeaders::class,
        ]);
        // Idempotency middleware alias removed — sobhanatar/idempotency not yet
        // installed. Re-add `composer require sobhanatar/idempotency` first,
        // then restore the alias and apply it to the write routes that need it.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
