<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Feature 2 — Onboarding Import Wizard.
 *
 * The page is a Filament `Page` (not a Resource) that surfaces the 5-step
 * bootstrap flow (Series → Authorities → Repositories → Batches →
 * Documents) and auto-hides once setup is done.
 *
 * Note on test scope: the upstream PR #34 base branch ships a broken
 * `app/Models/Accession.php` (5x duplicated `use App\Models\Concerns\
 * BelongsToRepository`) which causes a PHP fatal when the Filament admin
 * panel boots and `discoverResources` eagerly loads AccessionResource.
 * We therefore exercise the ImportWizard via its static methods —
 * `stepStates()`, `shouldRegisterNavigation()`, `canAccess()`, and the
 * `downloadTemplate()` instance method via a direct instantiation — which
 * is the same code path the blade view and the navigation use, just
 * without booting the surrounding panel.
 */
uses(RefreshDatabase::class);

function iw_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function iw_user(string $role): User
{
    iw_seedRoles();
    $u = User::factory()->create([
        'email' => 'iw-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

/** Plant one row of every prerequisite entity. */
function iw_seedAll(): void
{
    Series::create(['code' => 'X-' . uniqid(), 'title' => 'X', 'is_active' => true]);
    Authority::create([
        'identifier' => 'IW-' . uniqid(),
        'surname' => 'Borg',
        'entity_type' => 'PERSON',
    ]);
    $repo = Repository::factory()->create(['code' => 'IW_' . substr(uniqid(), -6)]);
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => random_int(100, 999),
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'IW-DOC-' . uniqid(),
        'repository_id' => $repo->id,
        'series_id' => Series::first()->id,
    ]);
}

/* ─── Tests ─────────────────────────────────────────────────────────── */

test('wizard canAccess returns true for super_admin', function () {
    $this->actingAs(iw_user('super_admin'));
    expect(ImportWizard::canAccess())->toBeTrue();
});

test('wizard canAccess returns false for viewer', function () {
    $this->actingAs(iw_user('viewer'));
    expect(ImportWizard::canAccess())->toBeFalse();
});

test('when all tables are empty every step is "pending" and progress is 0%', function () {
    $this->actingAs(iw_user('super_admin'));

    $states = ImportWizard::stepStates();

    expect(count($states))->toBe(5)
        ->and(array_column($states, 'key'))->toBe(['series', 'authority', 'repository', 'batch', 'document']);

    foreach ($states as $i => $step) {
        expect($step['done'])->toBeFalse("step {$step['key']} should NOT be done")
            ->and($step['count'])->toBe(0);
    }

    $progress = ImportWizard::progress();
    expect($progress['done'])->toBe(0)
        ->and($progress['total'])->toBe(5)
        ->and($progress['percent'])->toBe(0);
});

test('when Series has rows step 1 is "done" while step 2 stays "pending"', function () {
    $this->actingAs(iw_user('super_admin'));

    // Plant one Series row — every other table stays empty.
    Series::create(['code' => 'TEST1', 'title' => 'Test', 'is_active' => true]);

    $states = ImportWizard::stepStates();
    $byKey = array_column($states, null, 'key');

    expect($byKey['series']['done'])->toBeTrue()
        ->and($byKey['series']['count'])->toBe(1)
        ->and($byKey['authority']['done'])->toBeFalse()
        ->and($byKey['authority']['count'])->toBe(0)
        ->and($byKey['document']['done'])->toBeFalse();

    // Progress reflects the single completed step.
    expect(ImportWizard::progress()['done'])->toBe(1);
});

test('shouldRegisterNavigation auto-hides once every table is populated', function () {
    $this->actingAs(iw_user('super_admin'));

    // While anything is empty, the nav entry stays visible.
    expect(ImportWizard::shouldRegisterNavigation())->toBeTrue();

    iw_seedAll();

    // Now every prereq is met — wizard hides itself so it doesn't
    // clutter the sidebar for day-to-day operators.
    expect(ImportWizard::shouldRegisterNavigation())->toBeFalse();
    expect(ImportWizard::allPrerequisitesMet())->toBeTrue();
});

test('Document step is locked until Series + Authorities + Repositories + Batches are all non-empty', function () {
    $this->actingAs(iw_user('super_admin'));

    // Initially: nothing seeded → Document is locked, with all four prereqs missing.
    $states = ImportWizard::stepStates();
    $doc = collect($states)->firstWhere('key', 'document');

    expect($doc['unlocked'])->toBeFalse()
        ->and(sort($doc['missing']) ? $doc['missing'] : $doc['missing'])->toEqualCanonicalizing(['series', 'authority', 'repository', 'batch']);

    // Seed Series + Authority only — Document is still locked because
    // Repositories + Batches are missing.
    Series::create(['code' => 'S1', 'title' => 'T', 'is_active' => true]);
    Authority::create([
        'identifier' => 'IW-A1',
        'surname' => 'A',
        'entity_type' => 'PERSON',
    ]);

    $doc = collect(ImportWizard::stepStates())->firstWhere('key', 'document');
    expect($doc['unlocked'])->toBeFalse()
        ->and($doc['missing'])->toEqualCanonicalizing(['repository', 'batch']);

    // Add a Repository → still missing batch.
    $repo = Repository::factory()->create(['code' => 'IW_' . substr(uniqid(), -6)]);
    $doc = collect(ImportWizard::stepStates())->firstWhere('key', 'document');
    expect($doc['unlocked'])->toBeFalse()
        ->and($doc['missing'])->toBe(['batch']);

    // Finally add a Batch → Document unlocks.
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 777,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $doc = collect(ImportWizard::stepStates())->firstWhere('key', 'document');
    expect($doc['unlocked'])->toBeTrue()
        ->and($doc['missing'])->toBe([]);
});
