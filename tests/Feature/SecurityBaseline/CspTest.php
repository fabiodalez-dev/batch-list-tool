<?php

declare(strict_types=1);

/**
 * Security Baseline §4 — Content Security Policy
 *
 * Custom strict preset App\Support\Csp\AppPolicy is registered via
 * config/csp.php and the spatie/laravel-csp middleware is appended in
 * bootstrap/app.php.
 *
 * The policy MUST:
 *   - default to self
 *   - block frame-ancestors and object-src
 *   - allow data: URIs for fonts and images (Inter served locally + laravolt SVG)
 *   - never reference an external CDN (RFQ §11 / Security Baseline §15)
 */
beforeEach(function () {
    $response = $this->get('/admin/login');
    $response->assertOk();
    $this->cspHeader = (string) $response->headers->get('Content-Security-Policy');
});

test('response includes Content-Security-Policy header on /admin/login', function () {
    expect($this->cspHeader)->not->toBe('');
});

test("CSP contains default-src 'self'", function () {
    expect($this->cspHeader)->toContain("default-src 'self'");
});

test("CSP contains frame-ancestors 'none'", function () {
    expect($this->cspHeader)->toContain("frame-ancestors 'none'");
});

test("CSP contains object-src 'none'", function () {
    expect($this->cspHeader)->toContain("object-src 'none'");
});

test('CSP does NOT reference any third-party CDN domain', function () {
    $forbiddenHosts = [
        'fonts.bunny.net',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'ui-avatars.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'unpkg.com',
        'gravatar.com',
        'www.gravatar.com',
    ];

    foreach ($forbiddenHosts as $host) {
        expect($this->cspHeader)->not->toContain(
            $host,
            "CSP must not reference third-party host {$host} (security baseline §15)"
        );
    }
});

test("CSP includes font-src 'self' data: and img-src 'self' data:", function () {
    // The Inter woff2 is served from /fonts/inter/ (self), and laravolt avatars
    // are emitted as data:image/svg+xml;base64 URIs — so both directives MUST
    // accept both 'self' and the data: scheme.
    expect($this->cspHeader)
        ->toContain("font-src 'self' data:")
        ->toContain("img-src 'self' data:");
});
