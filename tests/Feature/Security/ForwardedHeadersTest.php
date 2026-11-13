<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * Security audit 2026-11-13, lead 2: trustProxies('*') honoured the
 * X-Forwarded-* headers from any client. With X-Forwarded-Host: evil.test the
 * password-reset e-mail carried a link to http://evil.test/... with a valid
 * token, and X-Forwarded-For let a client pick the IP the login throttle and
 * the audit log see. The headers are now honoured only from the proxies in
 * TRUSTED_PROXIES (empty by default). The test client is 127.0.0.1.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();

    Route::get('/__forwarded_probe__', fn () => response()->json([
        'ip' => request()->ip(),
        'host' => request()->getHost(),
        'secure' => request()->isSecure(),
        'url' => url('/admin'),
    ]))->middleware('web');
});

function sa_resetLinkFor(string $email, array $headers): string
{
    Notification::fake();
    /** @var User $user */
    $user = User::factory()->create(['email' => $email, 'is_active' => true]);
    $user->assignRole('admin');

    Livewire::withHeaders($headers)
        ->test(RequestPasswordReset::class)
        ->fillForm(['email' => $email])
        ->call('request');

    $url = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$url): bool {
        $url = $n->url;

        return true;
    });

    return (string) $url;
}

it('trusts no proxy by default', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();
});

it('ignores X-Forwarded-Host in the password-reset link when no proxy is trusted', function () {
    $link = sa_resetLinkFor('victim@example.test', ['X-Forwarded-Host' => 'evil.test']);

    expect(parse_url($link, PHP_URL_HOST))->not->toBe('evil.test')
        ->and(parse_url($link, PHP_URL_HOST))->toBe(parse_url(config('app.url'), PHP_URL_HOST));
});

it('ignores every X-Forwarded-* header from a client that is not a trusted proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.5']);

    $this->get('/__forwarded_probe__', [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Host' => 'evil.test',
        'X-Forwarded-Proto' => 'https',
    ])->assertJson(['ip' => '127.0.0.1', 'secure' => false])
        ->assertJsonMissing(['host' => 'evil.test']);
});

it('honours the headers from a listed proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.5, 127.0.0.1']);

    $this->get('/__forwarded_probe__', [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Host' => 'archive.example.org',
        'X-Forwarded-Proto' => 'https',
    ])->assertJson([
        'ip' => '203.0.113.9',
        'host' => 'archive.example.org',
        'secure' => true,
        'url' => 'https://archive.example.org/admin',
    ]);
});
