<?php

declare(strict_types=1);

use App\Filament\Pages\Account\PreferencesPage;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Task 14 — My account › Preferences page.
 *
 * Covers:
 *   - a user can set their default repository to one they belong to
 *   - a user cannot set a repository they do not belong to
 *   - canAccess() returns true for any authenticated user
 */
uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

function makeActiveUser(): User
{
    return User::factory()->create([
        'email' => 'pref-user+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('lets a user set their default repository to one they belong to', function () {
    $u = makeActiveUser();
    $repo = Repository::factory()->create();
    $u->repositories()->attach($repo);
    $this->actingAs($u);

    Livewire\Livewire::test(PreferencesPage::class)
        ->fillForm(['default_repository_id' => $repo->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($u->fresh()->default_repository_id)->toBe($repo->id);
});

it('rejects a repository the user does not belong to', function () {
    $u = makeActiveUser();
    $other = Repository::factory()->create(); // not attached
    $this->actingAs($u);

    Livewire\Livewire::test(PreferencesPage::class)
        ->fillForm(['default_repository_id' => $other->id])
        ->call('save')
        ->assertHasFormErrors(['default_repository_id']);

    expect($u->fresh()->default_repository_id)->not->toBe($other->id);
});

it('canAccess returns true for any authenticated user', function () {
    $u = makeActiveUser();
    $this->actingAs($u);

    expect(PreferencesPage::canAccess())->toBeTrue();
});

it('pre-fills the form with the current default repository', function () {
    $u = makeActiveUser();
    $repo = Repository::factory()->create();
    $u->repositories()->attach($repo);
    $u->update(['default_repository_id' => $repo->id]);
    $this->actingAs($u);

    Livewire\Livewire::test(PreferencesPage::class)
        ->assertFormSet(['default_repository_id' => $repo->id]);
});
