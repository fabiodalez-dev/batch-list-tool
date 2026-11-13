<?php

use App\Http\Middleware\EnsureUserIsActive;
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
        // (nginx, a load balancer, an HTTPS terminator). The X-Forwarded-*
        // headers below let Laravel rebuild the real external scheme / host /
        // port / path prefix, so links, redirects and cookies match the address
        // the user typed. X-Forwarded-Prefix covers a sub-folder mount.
        //
        // They are honoured ONLY from the proxies listed in TRUSTED_PROXIES
        // (config/trustedproxy.php, read by the middleware on each request).
        // Empty, the default, trusts nobody: trusting every client let anyone
        // forge the host of password-reset links and the IP used by the login
        // throttle and the audit log (security audit 2026-11-13).
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // A deactivated account is signed out on every web request, including
        // the plain-`auth` routes outside the panel (security audit 2026-11-13).
        $middleware->appendToGroup('web', EnsureUserIsActive::class);

        // Fortify registers no `login` route (views = false), so a guest on a
        // plain-`auth` route crashed with "Route [login] not defined" instead
        // of being sent to the panel login.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

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
