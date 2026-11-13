<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Security audit 2026-11-13, lead 1: a deactivated account (is_active=false)
 * must not sign in through Fortify's POST /login, and an existing session or
 * remember cookie of an account deactivated afterwards must not reach the
 * plain-`auth` routes outside the panel (backup download, attachments).
 * The panel itself already refuses it through User::canAccessPanel().
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();

    $appName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));
    Storage::fake('local');
    Storage::disk('local')->put(trim($appName, '/') . '/2026-01-01-02-30-05.zip', 'PK-dummy');
});

function sa_deactivatedAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create(['is_active' => false, 'email' => 'gone@example.test']);
    $user->assignRole('admin');

    return $user;
}

it('refuses a Fortify password login for a deactivated account', function () {
    sa_deactivatedAdmin();

    $this->post('/login', ['email' => 'gone@example.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->get('/admin/backups/download?disk=local&path=2026-01-01-02-30-05.zip')
        ->assertRedirect('/admin/login');
});

it('still lets an active account sign in through Fortify', function () {
    /** @var User $user */
    $user = User::factory()->create(['is_active' => true, 'email' => 'here@example.test']);
    $user->assignRole('admin');

    $this->post('/login', ['email' => 'here@example.test', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('ends the session of an account deactivated after it signed in', function () {
    $user = sa_deactivatedAdmin();

    $response = $this->actingAs($user)
        ->get('/admin/backups/download?disk=local&path=2026-01-01-02-30-05.zip');

    expect($response->isSuccessful())->toBeFalse();
    $this->assertGuest();
});
