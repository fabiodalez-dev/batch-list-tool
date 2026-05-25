<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Avatars\LocalAvatarProvider;

/**
 * Security Baseline §15 — No third-party assets at runtime
 *
 * The Inter variable font (rsms/inter v4.1, OFL-1.1) is bundled under
 * public/fonts/inter/ and the Filament admin panel is configured to load
 * it from /fonts/inter/InterVariable.woff2.
 *
 * User avatars are generated server-side by laravolt/avatar as inline SVG
 * data: URIs — no ui-avatars.com round-trip.
 */

test('public/fonts/inter/InterVariable.woff2 exists and is at least 100 KB', function () {
    $path = public_path('fonts/inter/InterVariable.woff2');

    expect(file_exists($path))->toBeTrue("missing font file at {$path}");
    expect(filesize($path))->toBeGreaterThan(100 * 1024,
        'InterVariable.woff2 must be the full variable font (> 100 KB), not an empty stub');
});

test('public/fonts/inter/InterVariable-Italic.woff2 exists and is at least 100 KB', function () {
    $path = public_path('fonts/inter/InterVariable-Italic.woff2');

    expect(file_exists($path))->toBeTrue("missing font file at {$path}");
    expect(filesize($path))->toBeGreaterThan(100 * 1024,
        'InterVariable-Italic.woff2 must be the full italic variable font (> 100 KB)');
});

test('LocalAvatarProvider returns a data:image/svg+xml;base64 URI with no external call', function () {
    // Build the user in-memory — no DB call, no HTTP call expected from the provider.
    $user = new User(['name' => 'Jane Notary', 'email' => 'jane@nra.local']);

    $provider = new LocalAvatarProvider();
    $uri = $provider->get($user);

    expect($uri)
        ->toBeString()
        ->toStartWith('data:image/svg+xml;base64,');

    // Decode the payload and assert it is valid inline SVG (not a redirect to
    // a third-party endpoint, not an empty string).
    $payload = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
    expect($payload)->not->toBeFalse('avatar payload must be valid base64');
    expect($payload)->toContain('<svg')->toContain('</svg>');

    // Defensive guard: the inline SVG must NOT reference any external avatar host.
    // (The `xmlns="http://www.w3.org/2000/svg"` namespace URI is part of the SVG
    // spec, declared inert by browsers, so we only blacklist real hostnames.)
    expect($payload)
        ->not->toContain('ui-avatars.com')
        ->not->toContain('gravatar.com')
        ->not->toContain('googleusercontent.com')
        ->not->toContain('xlink:href="http');
});
