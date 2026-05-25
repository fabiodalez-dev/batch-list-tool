<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;

/**
 * Security Baseline §7 / RFQ §3.5.1 — multi-tenant repository scoping
 *
 * Document (and any model using BelongsToRepository) MUST be filtered by the
 * repositories the authenticated user has been assigned to, EXCEPT when the
 * user has the `super_admin` or `admin` role (cross-repo oversight).
 *
 * Uses DatabaseTransactions because the project runs against MySQL and we
 * don't want to wipe the dev seed data.
 */

uses(DatabaseTransactions::class);

/**
 * Helper: create a Document directly via the query builder, bypassing the
 * BelongsToRepository creating-hook (we want full control of repository_id
 * for the assertions).
 */
function createDocumentInRepository(int $repositoryId, int $seriesId, string $identifier): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier'    => $identifier,
        'document_type' => 'TEST',
        'series_id'     => $seriesId,
        'repository_id' => $repositoryId,
    ]);
}

function attachUserToRepository(User $user, Repository $repository, bool $isDefault = false): void
{
    $user->repositories()->attach($repository->id, ['is_default' => $isDefault]);
    if ($isDefault) {
        $user->default_repository_id = $repository->id;
        $user->save();
    }
}

beforeEach(function () {
    // Pin to existing seeded role names — RepositoryScope checks them by name.
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'editor',      'guard_name' => 'web']);

    // Two isolated test repositories. Permission/auditing make these heavy, so
    // we set is_active=true to mimic production rows.
    $this->repoA = Repository::factory()->create(['code' => 'TST_A_' . substr(uniqid(), -6)]);
    $this->repoB = Repository::factory()->create(['code' => 'TST_B_' . substr(uniqid(), -6)]);

    $this->series = Series::query()->first()
        ?? Series::create(['code' => 'TEST', 'title' => 'Test series', 'is_active' => true]);

    // 2 docs in repo A, 3 docs in repo B — assertions key on these counts.
    $this->docA1 = createDocumentInRepository($this->repoA->id, $this->series->id, 'TST-A-1');
    $this->docA2 = createDocumentInRepository($this->repoA->id, $this->series->id, 'TST-A-2');
    $this->docB1 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-1');
    $this->docB2 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-2');
    $this->docB3 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-3');
});

test('editor scoped to repo A sees only repo A documents', function () {
    $editor = User::factory()->create(['email' => 'editor.a+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    $docs = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->get();

    // Exactly 2 visible: both repo A docs
    expect($docs)->toHaveCount(2);
    expect($docs->pluck('repository_id')->unique()->values()->all())
        ->toBe([$this->repoA->id]);
});

test('super_admin sees documents across all repositories', function () {
    $superAdmin = User::factory()->create(['email' => 'super+' . uniqid() . '@test.local']);
    $superAdmin->assignRole('super_admin');
    // Intentionally NOT attached to repoA or repoB — global view must still work.

    $this->actingAs($superAdmin);

    $visibleIds = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->pluck('id')->sort()->values()->all();

    $expectedIds = collect([
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->sort()->values()->all();

    expect($visibleIds)->toBe($expectedIds);
});

test('admin role sees documents across all repositories (RFQ §3.5.1 oversight)', function () {
    $admin = User::factory()->create(['email' => 'admin+' . uniqid() . '@test.local']);
    $admin->assignRole('admin');
    // Attached to repoA only — yet `admin` MUST still see repoB docs.
    attachUserToRepository($admin, $this->repoA, isDefault: true);

    $this->actingAs($admin);

    $docs = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->get();

    expect($docs)->toHaveCount(5);
    expect($docs->pluck('repository_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->repoA->id, $this->repoB->id])->sort()->values()->all());
});

test('editor in repo A cannot read repo B documents even via explicit find', function () {
    $editor = User::factory()->create(['email' => 'editor.b+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    // Explicit find for a repo B document — global scope MUST filter it out.
    $shouldBeNull = Document::query()->find($this->docB1->id);
    expect($shouldBeNull)->toBeNull();

    // Bonus: a repo A document IS still findable
    $shouldBeFound = Document::query()->find($this->docA1->id);
    expect($shouldBeFound)->not->toBeNull();
    expect($shouldBeFound->id)->toBe($this->docA1->id);
});
