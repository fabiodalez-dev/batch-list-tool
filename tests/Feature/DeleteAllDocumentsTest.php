<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Charlene UX — "Delete all documents" super-admin header action on the
 * Documents list. Efficiently clears every document in the acting user's
 * current repository scope, and is invisible to non-super-admins.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function dda_user(string $role): User
{
    $u = User::factory()->create([
        'email' => 'dda-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function dda_seedDocuments(int $n): Repository
{
    $repo = Repository::factory()->create([
        'code' => 'DDA_' . substr(uniqid(), -6),
    ]);
    $series = Series::create([
        'code' => 'DDA_' . substr(uniqid(), -4),
        'title' => 'DDA series',
        'is_active' => true,
    ]);

    for ($i = 0; $i < $n; $i++) {
        Document::withoutGlobalScope(RepositoryScope::class)->create([
            'identifier' => 'DDA-' . strtoupper(substr(uniqid(), -8)) . '-' . $i,
            'document_type' => 'TEST',
            'series_id' => $series->id,
            'repository_id' => $repo->id,
        ]);
    }

    return $repo;
}

it('deletes every document when a super admin confirms', function () {
    dda_seedDocuments(12);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(12);

    $this->actingAs(dda_user('super_admin'));

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'DELETE'])
        ->assertHasNoActionErrors();

    expect(Document::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(0);
});

it('rejects the confirmation form when the wrong word is typed', function () {
    dda_seedDocuments(5);

    $this->actingAs(dda_user('super_admin'));

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'nope'])
        ->assertHasActionErrors(['confirmation']);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(5);
});

it('hides the action from a non-super-admin', function () {
    dda_seedDocuments(3);

    $this->actingAs(dda_user('editor'));

    Livewire::test(ListDocuments::class)
        ->assertOk()
        ->assertActionHidden('deleteAll');

    // Data is untouched.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(3);
});

it('also hides the action from an ordinary admin', function () {
    dda_seedDocuments(2);

    $this->actingAs(dda_user('admin'));

    Livewire::test(ListDocuments::class)
        ->assertOk()
        ->assertActionHidden('deleteAll');
});
