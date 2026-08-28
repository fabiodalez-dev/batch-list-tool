<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\ActiveRepository;
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
    $repo = dda_seedDocuments(12);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(12);

    $this->actingAs(dda_user('super_admin'));
    app(ActiveRepository::class)->set($repo->id); // a specific repository is selected

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'DELETE'])
        ->assertHasNoActionErrors();

    expect(Document::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(0);
});

it('EDGE: only deletes the ACTIVE repository, never across repositories', function () {
    $repoA = dda_seedDocuments(6);
    $repoB = dda_seedDocuments(4);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(10);

    $this->actingAs(dda_user('super_admin'));
    app(ActiveRepository::class)->set($repoA->id); // viewing repo A only

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'DELETE'])
        ->assertHasNoActionErrors();

    // Repo A wiped, repo B fully intact — no cross-tenant wipe.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $repoA->id)->withTrashed()->count())->toBe(0)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $repoB->id)->count())->toBe(4);
});

it('EDGE: refuses (deletes nothing) when no repository is selected (viewing All)', function () {
    dda_seedDocuments(7);

    $this->actingAs(dda_user('super_admin'));
    app(ActiveRepository::class)->set(null); // "All repositories"

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'DELETE'])
        ->assertHasNoActionErrors(); // no form error — it just refuses via notification

    // Nothing deleted — the destructive action never runs without a repo scope.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(7);
});

it('EDGE: cascades to a document\'s dependent rows (movements)', function () {
    $repo = dda_seedDocuments(1);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->firstOrFail();
    BoxMovement::query()->create([
        'document_id' => $doc->id, 'repository_id' => $repo->id,
        'from_box_id' => null, 'to_box_id' => null,
        'movement_date' => null, 'date_source' => 'legacy_import', 'sequence' => 1,
        'reason' => 'test', 'user_id' => null,
    ]);
    expect(BoxMovement::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs(dda_user('super_admin'));
    app(ActiveRepository::class)->set($repo->id);

    Livewire::test(ListDocuments::class)
        ->callAction('deleteAll', data: ['confirmation' => 'DELETE'])
        ->assertHasNoActionErrors();

    expect(Document::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(0)
        ->and(BoxMovement::withoutGlobalScopes()->count())->toBe(0); // cascade-deleted
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
