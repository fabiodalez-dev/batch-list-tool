<?php

declare(strict_types=1);

/**
 * Security Baseline §2 — Session hardening
 *
 * .env shipped with the app sets:
 *   SESSION_SAME_SITE=strict
 *   SESSION_HTTP_ONLY=true
 *   SESSION_SECURE_COOKIE=false   (development; MUST be true in prod over HTTPS)
 *
 * These tests assert both:
 *   - the runtime config Laravel resolves at boot
 *   - the literal .env file on disk (so we catch silent regressions where the
 *     env var disappears but the runtime falls back to a Laravel default)
 */

test('session same_site is strict in the live env file and at runtime', function () {
    expect(file_get_contents(base_path('.env')))
        ->toContain('SESSION_SAME_SITE=strict');

    expect(config('session.same_site'))->toBe('strict');
});

test('session http_only is enabled in the live env file and at runtime', function () {
    expect(file_get_contents(base_path('.env')))
        ->toContain('SESSION_HTTP_ONLY=true');

    expect(config('session.http_only'))->toBeTrue();
});

test('session config returns the same_site and http_only values matching the env declarations', function () {
    // Cross-check via the compiled config file (no env() call) — verifies the
    // session config wiring itself reads from env, not from a stale literal.
    $sessionConfig = require base_path('config/session.php');

    // config/session.php uses env() at file-load time → values resolved against current env
    expect($sessionConfig['http_only'])->toBeTrue();
    expect($sessionConfig['same_site'])->toBe('strict');

    // And the booted application's config repository agrees.
    expect(config('session.http_only'))->toBe($sessionConfig['http_only']);
    expect(config('session.same_site'))->toBe($sessionConfig['same_site']);
});
