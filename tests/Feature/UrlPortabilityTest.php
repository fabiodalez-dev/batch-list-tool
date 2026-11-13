<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| URL portability — the app must serve from any URL (root / sub-domain /
| sub-folder), including behind a reverse proxy. We assert that the trusted
| X-Forwarded-* headers drive the scheme, host and path-prefix used to build
| absolute URLs (links, assets, redirects). The headers are honoured only
| from a proxy listed in TRUSTED_PROXIES, so these tests declare the test
| client (127.0.0.1) as that proxy. tests/Feature/Security/ForwardedHeadersTest
| covers the untrusted case.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config(['trustedproxy.proxies' => '127.0.0.1']);

    Route::get('/__url_probe__', fn () => response()->json([
        'url' => url('/admin'),
        'root' => url('/'),
        'secure' => request()->isSecure(),
        'host' => request()->getHttpHost(),
    ]))->middleware('web');
});

it('honors X-Forwarded-Proto + Host to build absolute URLs (sub-domain over HTTPS)', function () {
    $res = $this->get('/__url_probe__', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'archive.example.org',
    ]);

    $res->assertOk();
    expect($res->json('secure'))->toBeTrue()
        ->and($res->json('host'))->toBe('archive.example.org')
        ->and($res->json('url'))->toStartWith('https://archive.example.org')
        ->and($res->json('url'))->toEndWith('/admin');
});

it('honors X-Forwarded-Prefix so a sub-folder mount resolves under that prefix', function () {
    $res = $this->get('/__url_probe__', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'example.org',
        'X-Forwarded-Prefix' => '/archive',
    ]);

    $res->assertOk();
    // Generated URLs must carry the sub-folder prefix.
    expect($res->json('root'))->toBe('https://example.org/archive')
        ->and($res->json('url'))->toBe('https://example.org/archive/admin');
});

it('still works at a plain root URL with no forwarded headers', function () {
    $res = $this->get('/__url_probe__');

    $res->assertOk();
    expect($res->json('url'))->toEndWith('/admin');
});
