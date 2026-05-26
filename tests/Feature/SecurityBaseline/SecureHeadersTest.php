<?php

declare(strict_types=1);

/**
 * Security Baseline §3 — secure HTTP headers
 *
 * bepsvpt/secure-headers is registered globally in bootstrap/app.php and
 * applies to every response that flows through the `web` middleware group.
 *
 * We hit `/admin/login` because:
 *   - `/` is now a 302 redirect to `/admin/login` (the bundled welcome page
 *     was pulled because it linked to fonts.bunny.net — see commit d18c002
 *     "fix(security): kill remaining CDN refs"), so it cannot be asserted
 *     with assertOk() any longer;
 *   - `/admin/login` is the actual user-facing entrypoint, returns 200, and
 *     traverses the same `web` middleware stack — the headers asserted below
 *     are exactly the ones a real user sees in the browser.
 */
test('response includes X-Content-Type-Options: nosniff', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('response includes X-Frame-Options', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();
    expect($response->headers->get('X-Frame-Options'))
        ->not->toBeNull()
        ->and(strtolower((string) $response->headers->get('X-Frame-Options')))
        ->toBeIn(['deny', 'sameorigin']);
});

test('response includes Referrer-Policy', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();
    // Whitelist of privacy-preserving values (RFQ §11 security baseline)
    expect($response->headers->get('Referrer-Policy'))
        ->not->toBeNull()
        ->toBeIn([
            'no-referrer',
            'no-referrer-when-downgrade',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
        ]);
});

test('response does NOT leak the Server / X-Powered-By identification headers', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();

    // bepsvpt/secure-headers config sets 'server' => '' which strips the header.
    // The Server header may still arrive at the edge from nginx/Apache, but it
    // must NOT contain version strings (e.g. "Apache/2.4.41 (Ubuntu)"); for the
    // testing kernel response the header must be entirely absent.
    $server = $response->headers->get('Server');
    expect($server === null || $server === '')->toBeTrue(
        "Server header should be empty/absent, got: {$server}"
    );

    // X-Powered-By must also be stripped (PHP version disclosure).
    $poweredBy = $response->headers->get('X-Powered-By');
    expect($poweredBy === null || $poweredBy === '')->toBeTrue(
        "X-Powered-By header should be empty/absent, got: {$poweredBy}"
    );
});
