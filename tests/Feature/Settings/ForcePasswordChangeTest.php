<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Task 6 — Force password change on first login.
 *
 * Users with `must_change_password = true` must be redirected to the Filament
 * profile page on every admin request until they change their own password.
 * Once they change their own password the flag clears automatically.
 *
 * Key rules:
 *   - Dashboard and all other admin pages → redirect to profile.
 *   - Profile page itself → allowed (HTTP 200).
 *   - Livewire requests → allowed (path starts with livewire/).
 *   - Logout → allowed.
 *   - After self-password change → flag cleared.
 *   - Admin resetting ANOTHER user's password → flag NOT cleared on that admin.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function rolesExist_fpwc(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function makeMustChangeUser_fpwc(): User
{
    rolesExist_fpwc();
    $u = User::factory()->create([
        'email' => 'fpwc-must+' . uniqid() . '@test.local',
        'is_active' => true,
        'must_change_password' => true,
    ]);
    $u->assignRole('editor');

    return $u;
}

function makeSuperAdmin_fpwc(): User
{
    rolesExist_fpwc();
    $u = User::factory()->create([
        'email' => 'fpwc-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

// ---------------------------------------------------------------------------
// Redirect tests
// ---------------------------------------------------------------------------

it('redirects a must-change user away from the dashboard', function () {
    $u = makeMustChangeUser_fpwc();

    $this->actingAs($u)
        ->get('/admin')
        ->assertRedirect(route('filament.admin.auth.profile'));
});

it('allows the profile page for a must-change user', function () {
    $u = makeMustChangeUser_fpwc();

    $this->actingAs($u)
        ->get('/admin/profile')
        ->assertSuccessful();
});

it('allows livewire requests for a must-change user', function () {
    $u = makeMustChangeUser_fpwc();

    // Livewire AJAX requests go to livewire/* — they must not be redirected
    // or the profile form submit will break in a redirect loop.
    $response = $this->actingAs($u)->post('/livewire/update', [], [
        'X-Livewire' => 'true',
        'Accept' => 'application/json',
    ]);

    // The important assertion: NOT a redirect to profile.
    expect($response->getStatusCode())->not->toBe(302);
});

it('does not redirect a normal user away from the dashboard', function () {
    rolesExist_fpwc();
    $u = User::factory()->create([
        'email' => 'fpwc-normal+' . uniqid() . '@test.local',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    $u->assignRole('editor');

    $this->actingAs($u)
        ->get('/admin')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Flag-clearing tests
// ---------------------------------------------------------------------------

it('clears the flag when a user changes their own password', function () {
    $u = makeMustChangeUser_fpwc();
    expect($u->must_change_password)->toBeTrue();

    $this->actingAs($u);

    // Simulate self password change via model (direct path the Filament profile
    // form exercises underneath: sets password, calls save()).
    $u->password = Hash::make('NewSecurePass!234');
    $u->save();

    expect($u->fresh()->must_change_password)->toBeFalse();
});

it('does NOT clear the flag when an admin resets another user password', function () {
    $admin = makeSuperAdmin_fpwc();
    $target = makeMustChangeUser_fpwc();

    // Admin is authenticated — target is the OTHER user being saved.
    $this->actingAs($admin);

    // Simulate the reset-password action: forceFill password + must_change_password
    // on the target and save, exactly as the Filament action does.
    $target->forceFill([
        'password' => Hash::make('TemporaryPass!789'),
        'must_change_password' => true,
    ])->save();

    expect($target->fresh()->must_change_password)->toBeTrue();
});

it('does NOT clear the flag for an unauthenticated password save', function () {
    // Guard: no auth session — should not clear.
    $u = makeMustChangeUser_fpwc();
    expect($u->must_change_password)->toBeTrue();

    // No actingAs() — unauthenticated context.
    $u->password = Hash::make('AnotherPass!456');
    $u->save();

    expect($u->fresh()->must_change_password)->toBeTrue();
});
