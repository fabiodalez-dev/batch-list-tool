<?php

declare(strict_types=1);

use App\Models\ImportProfile;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * {@see ImportProfile} model tests — ownership, sharing, casts and the
 * `markUsed()` telemetry hook used by the Import Wizard "starting
 * profile" dropdown.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/* ─── Helpers ──────────────────────────────────────────────────────── */

function ip_user(string $role, ?Repository $repo = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'ip-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo?->getKey(),
    ]);
    $u->assignRole($role);
    if ($repo instanceof Repository) {
        $u->repositories()->syncWithoutDetaching([$repo->getKey() => ['is_default' => true]]);
    }

    return $u;
}

function ip_repo(string $prefix = 'IP'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

/* ─── 1) Create with column_map + synonyms array casts ─────────────── */

test('ImportProfile can be created and array fields round-trip via casts', function () {
    $repo = ip_repo();
    $admin = ip_user('super_admin', $repo);
    $this->actingAs($admin);

    $columnMap = [
        'identifier' => 'Identifier',
        'surname' => 'Creator Surname',
        'given_names' => 'Creator Name',
        'entity_type' => null, // skipped column
    ];
    $synonyms = [
        'inputter' => ['inputter', 'created_by'],
    ];

    /** @var ImportProfile $profile */
    $profile = ImportProfile::query()->create([
        'user_id' => $admin->getKey(),
        'repository_id' => $repo->id,
        'name' => 'NRA legacy mapping',
        'description' => 'Sample mapping for the NAF legacy spreadsheet',
        'import_type' => ImportProfile::TYPE_AUTHORITIES,
        'column_map' => $columnMap,
        'synonyms' => $synonyms,
        'is_shared' => false,
    ]);

    expect($profile->exists)->toBeTrue()
        ->and($profile->getAttribute('name'))->toBe('NRA legacy mapping')
        ->and($profile->getAttribute('import_type'))->toBe(ImportProfile::TYPE_AUTHORITIES);

    /** @var ImportProfile $fresh */
    $fresh = ImportProfile::query()->findOrFail($profile->getKey());

    expect($fresh->getAttribute('column_map'))->toBe($columnMap)
        ->and($fresh->getAttribute('synonyms'))->toBe($synonyms)
        ->and($fresh->getAttribute('is_shared'))->toBeFalse()
        ->and($fresh->getAttribute('use_count'))->toBe(0)
        ->and($fresh->getAttribute('last_used_at'))->toBeNull();

    // Raw DB value is JSON, not a serialized blob.
    $raw = DB::table('import_profiles')->where('id', $profile->getKey())->value('column_map');
    expect(json_decode((string) $raw, true))->toBe($columnMap);
});

/* ─── 2) accessibleBy: owner + shared visible in repo ──────────────── */

test('accessibleBy returns owner private profiles plus shared profiles in their repository', function () {
    $repo = ip_repo();
    $alice = ip_user('editor', $repo);
    $bob = ip_user('editor', $repo);

    // Alice's private profile (only she sees it)
    $this->actingAs($alice);
    $alicePrivate = ImportProfile::query()->create([
        'user_id' => $alice->getKey(),
        'repository_id' => $repo->id,
        'name' => "Alice's private",
        'import_type' => ImportProfile::TYPE_DOCUMENTS,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => false,
    ]);

    // Bob's shared profile (Alice should see it too)
    $this->actingAs($bob);
    $bobShared = ImportProfile::query()->create([
        'user_id' => $bob->getKey(),
        'repository_id' => $repo->id,
        'name' => "Bob's shared",
        'import_type' => ImportProfile::TYPE_AUTHORITIES,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => true,
    ]);

    // Bob's private profile (Alice should NOT see it)
    $bobPrivate = ImportProfile::query()->create([
        'user_id' => $bob->getKey(),
        'repository_id' => $repo->id,
        'name' => "Bob's private",
        'import_type' => ImportProfile::TYPE_AUTHORITIES,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => false,
    ]);

    $this->actingAs($alice);
    $aliceVisibleIds = ImportProfile::query()
        ->accessibleBy($alice)
        ->pluck('id')
        ->all();

    expect($aliceVisibleIds)->toContain($alicePrivate->getKey())
        ->and($aliceVisibleIds)->toContain($bobShared->getKey())
        ->and($aliceVisibleIds)->not->toContain($bobPrivate->getKey());
});

/* ─── 3) Cross-tenant isolation ─────────────────────────────────────── */

test('cross-tenant: user from repo A cannot see private profiles from repo B', function () {
    $repoA = ip_repo('A');
    $repoB = ip_repo('B');

    $aliceA = ip_user('editor', $repoA);
    $bobB = ip_user('editor', $repoB);

    // Bob (repo B) creates a PRIVATE profile tagged repo B.
    $this->actingAs($bobB);
    ImportProfile::query()->create([
        'user_id' => $bobB->getKey(),
        'repository_id' => $repoB->id,
        'name' => 'Bob B private',
        'import_type' => ImportProfile::TYPE_DOCUMENTS,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => false,
    ]);

    // Bob also creates a SHARED profile in repo B. The RepositoryScope on
    // ImportProfile must hide BOTH from Alice (repo A) regardless of
    // is_shared, because RepositoryScope filters by repository_id BEFORE
    // accessibleBy's OR-clause runs.
    ImportProfile::query()->create([
        'user_id' => $bobB->getKey(),
        'repository_id' => $repoB->id,
        'name' => 'Bob B shared',
        'import_type' => ImportProfile::TYPE_DOCUMENTS,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => true,
    ]);

    // Alice (repo A, non-privileged editor) lists accessible profiles.
    $this->actingAs($aliceA);

    $count = ImportProfile::query()
        ->accessibleBy($aliceA)
        ->count();

    expect($count)->toBe(0);
});

/* ─── 4) markUsed() bumps last_used_at and use_count ───────────────── */

test('markUsed bumps last_used_at and increments use_count', function () {
    $repo = ip_repo();
    $admin = ip_user('super_admin', $repo);
    $this->actingAs($admin);

    /** @var ImportProfile $profile */
    $profile = ImportProfile::query()->create([
        'user_id' => $admin->getKey(),
        'repository_id' => $repo->id,
        'name' => 'Test profile',
        'import_type' => ImportProfile::TYPE_DOCUMENTS,
        'column_map' => ['identifier' => 'Identifier'],
        'is_shared' => false,
    ]);

    expect($profile->use_count)->toBe(0)
        ->and($profile->last_used_at)->toBeNull();

    $before = now()->subSecond();
    $profile->markUsed();

    /** @var ImportProfile $fresh */
    $fresh = ImportProfile::query()->findOrFail($profile->getKey());
    expect($fresh->use_count)->toBe(1)
        ->and($fresh->last_used_at)->not->toBeNull()
        ->and($fresh->last_used_at->greaterThanOrEqualTo($before))->toBeTrue();

    // Second call → count bumps to 2.
    $fresh->markUsed();
    $second = ImportProfile::query()->findOrFail($profile->getKey());
    expect($second->use_count)->toBe(2);
});
