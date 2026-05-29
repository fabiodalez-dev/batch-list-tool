<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Profile page — default repository field.
 *
 * Covers:
 *   - a user can set their default_repository_id from the profile page
 *   - a repository not belonging to the user is rejected with a form error
 *   - the profile page is accessible (HTTP 200)
 */
uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * Create an active user with the 'editor' role so canAccessPanel() returns
 * true (required for the HTTP smoke test).
 */
function makeProfileUser(): User
{
    bl_seedRoles();

    $u = User::factory()->create([
        'email' => 'profile-test+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('editor');

    return $u;
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('lets a user set their default repository from the profile page', function () {
    $u = makeProfileUser();
    $repo = Repository::factory()->create();
    $u->repositories()->attach($repo);
    $this->actingAs($u);

    Livewire\Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $u->name,
            'email' => $u->email,
            'default_repository_id' => $repo->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($u->fresh()->default_repository_id)->toBe($repo->id);
});

it('rejects a repository the user does not belong to', function () {
    $u = makeProfileUser();
    $other = Repository::factory()->create(); // not attached to $u
    $this->actingAs($u);

    Livewire\Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $u->name,
            'email' => $u->email,
            'default_repository_id' => $other->id,
        ])
        ->call('save')
        ->assertHasFormErrors(['default_repository_id']);

    expect($u->fresh()->default_repository_id)->not->toBe($other->id);
});

it('profile page returns 200 for an authenticated user', function () {
    $u = makeProfileUser();
    $this->actingAs($u)
        ->get('/admin/profile')
        ->assertOk();
});
