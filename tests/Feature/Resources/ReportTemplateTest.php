<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/* ─── Helpers ──────────────────────────────────────────────────────── */

function rt_user(string $role, ?Repository $repo = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'rt-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo?->getKey(),
    ]);
    $u->assignRole($role);
    if ($repo instanceof Repository) {
        $u->repositories()->syncWithoutDetaching([$repo->getKey() => ['is_default' => true]]);
    }

    return $u;
}

function rt_repo(string $prefix = 'RT'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

/* ─── 1) Create with all required fields ───────────────────────────── */

test('ReportTemplate can be created with all required fields', function () {
    $repo = rt_repo();
    $admin = rt_user('super_admin', $repo);
    $this->actingAs($admin);

    $tpl = ReportTemplate::create([
        'user_id' => $admin->getKey(),
        'repository_id' => $repo->id,
        'name' => 'My batch view',
        'description' => 'Just batches > 30',
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => ['batch_type' => ['value' => 'NOTARY_ACCESSION']],
        'columns' => ['batch_number', 'document_count'],
        'sort' => ['column' => 'document_count', 'direction' => 'desc'],
        'is_shared' => false,
    ]);

    expect($tpl->exists)->toBeTrue()
        ->and($tpl->getAttribute('name'))->toBe('My batch view')
        ->and($tpl->getAttribute('source'))->toBe(ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH)
        ->and($tpl->getAttribute('user_id'))->toBe($admin->getKey());

    // Persisted row exists in DB
    expect(ReportTemplate::query()->whereKey($tpl->getKey())->exists())->toBeTrue();
});

/* ─── 2) Casts to/from JSON correctly ──────────────────────────────── */

test('filters / columns / sort cast to and from JSON correctly', function () {
    $repo = rt_repo();
    $admin = rt_user('super_admin', $repo);
    $this->actingAs($admin);

    $filters = ['batch_type' => ['value' => 'NOTARY_ACCESSION'], 'q' => 'R12'];
    $columns = ['batch_number', 'document_count'];
    $sort = ['column' => 'document_count', 'direction' => 'desc'];

    $tpl = ReportTemplate::create([
        'user_id' => $admin->getKey(),
        'repository_id' => $repo->id,
        'name' => 'JSON roundtrip',
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => $filters,
        'columns' => $columns,
        'sort' => $sort,
    ]);

    /** @var ReportTemplate $fresh */
    $fresh = ReportTemplate::query()->findOrFail($tpl->getKey());

    expect($fresh->getAttribute('filters'))->toBe($filters)
        ->and($fresh->getAttribute('columns'))->toBe($columns)
        ->and($fresh->getAttribute('sort'))->toBe($sort)
        ->and($fresh->getAttribute('is_shared'))->toBeFalse();

    // Raw DB value is JSON
    $raw = DB::table('report_templates')->where('id', $tpl->getKey())->value('filters');
    expect(json_decode((string) $raw, true))->toBe($filters);
});

/* ─── 3) accessibleBy: owner + shared visible ──────────────────────── */

test('accessibleBy returns owner private templates plus shared templates in their repository', function () {
    $repo = rt_repo();
    $alice = rt_user('editor', $repo);
    $bob = rt_user('editor', $repo);

    // Alice's private template (only she sees it)
    $this->actingAs($alice);
    $alicePrivate = ReportTemplate::create([
        'user_id' => $alice->getKey(),
        'repository_id' => $repo->id,
        'name' => "Alice's private",
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => [],
        'is_shared' => false,
    ]);

    // Bob's shared template (Alice should see it too)
    $this->actingAs($bob);
    $bobShared = ReportTemplate::create([
        'user_id' => $bob->getKey(),
        'repository_id' => $repo->id,
        'name' => "Bob's shared",
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES,
        'filters' => [],
        'is_shared' => true,
    ]);

    // Bob's private template (Alice should NOT see it)
    $bobPrivate = ReportTemplate::create([
        'user_id' => $bob->getKey(),
        'repository_id' => $repo->id,
        'name' => "Bob's private",
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => [],
        'is_shared' => false,
    ]);

    $this->actingAs($alice);
    $aliceVisibleIds = ReportTemplate::query()
        ->accessibleBy($alice)
        ->pluck('id')
        ->all();

    expect($aliceVisibleIds)->toContain($alicePrivate->getKey())
        ->and($aliceVisibleIds)->toContain($bobShared->getKey())
        ->and($aliceVisibleIds)->not->toContain($bobPrivate->getKey());
});

/* ─── 4) Cross-tenant isolation ────────────────────────────────────── */

test('cross-tenant: user from repo A cannot see private templates from repo B', function () {
    $repoA = rt_repo('A');
    $repoB = rt_repo('B');

    $aliceA = rt_user('editor', $repoA);
    $bobB = rt_user('editor', $repoB);

    // Bob (repo B) creates a PRIVATE template tagged repo B.
    $this->actingAs($bobB);
    ReportTemplate::create([
        'user_id' => $bobB->getKey(),
        'repository_id' => $repoB->id,
        'name' => 'Bob B private',
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => [],
        'is_shared' => false,
    ]);

    // Bob also creates a SHARED template in repo B — the RepositoryScope
    // on ReportTemplate should hide this from Alice (repo A) regardless
    // of is_shared, because RepositoryScope filters by repository_id BEFORE
    // accessibleBy's OR-clause runs.
    ReportTemplate::create([
        'user_id' => $bobB->getKey(),
        'repository_id' => $repoB->id,
        'name' => 'Bob B shared',
        'source' => ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH,
        'filters' => [],
        'is_shared' => true,
    ]);

    // Alice (repo A, non-privileged editor) lists accessible templates.
    $this->actingAs($aliceA);

    $count = ReportTemplate::query()
        ->accessibleBy($aliceA)
        ->count();

    expect($count)->toBe(0);
});

/* ─── 5) "Save as template" action creates a row with the current state ─ */

test('the Save as template action on a report page creates a row with the current filter state', function () {
    $repo = rt_repo();
    $admin = rt_user('super_admin', $repo);
    $this->actingAs($admin);

    // Drive the Livewire component: set a filter, then call the action.
    Livewire::test(DocumentsByBatchReport::class)
        ->set('tableFilters.batch_type.value', 'NOTARY_ACCESSION')
        ->set('tableSort', 'document_count:desc')
        ->callAction('save_as_template', data: [
            'name' => 'Saved from action',
            'description' => 'My filter preset',
            'is_shared' => true,
        ])
        ->assertHasNoActionErrors();

    $tpl = ReportTemplate::query()->where('name', 'Saved from action')->firstOrFail();

    expect($tpl->getAttribute('source'))->toBe(ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH)
        ->and($tpl->getAttribute('user_id'))->toBe($admin->getKey())
        ->and($tpl->getAttribute('is_shared'))->toBeTrue();

    // Filter state was captured — check it survives the JSON round-trip.
    $filters = $tpl->getAttribute('filters');
    expect($filters)->toBeArray()
        ->and(data_get($filters, 'batch_type.value'))->toBe('NOTARY_ACCESSION');

    // Sort was captured in the canonical {column,direction} shape.
    $sort = $tpl->getAttribute('sort');
    expect($sort)->toBe(['column' => 'document_count', 'direction' => 'desc']);
});
