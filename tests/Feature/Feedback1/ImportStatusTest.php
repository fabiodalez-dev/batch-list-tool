<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Filament\Pages\ImportStatus;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A1 (Wave A) — ImportStatus page tests.
 *
 * 1. Page loads (200 OK) for an admin user.
 * 2. canAccess() returns false for editor and viewer roles.
 * 3. A seeded import row appears in the page's getImports() list.
 * 4. Processed/total counts are correctly surfaced.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Helper: make a user with a given role
// ---------------------------------------------------------------------------

function is_makeUser(string $role): User
{
    $u = User::factory()->create([
        'email' => "is-{$role}+" . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

// ---------------------------------------------------------------------------
// Helper: create an Import record belonging to a user
// ---------------------------------------------------------------------------

function is_makeImport(User $user, array $overrides = []): Import
{
    /** @var Import $import */
    $import = Import::query()->create(array_merge([
        'completed_at' => null,
        'file_name' => 'test_import.csv',
        'file_path' => 'imports/test_import.csv',
        'importer' => BatchImporter::class,
        'processed_rows' => 0,
        'total_rows' => 10,
        'successful_rows' => 0,
        'user_id' => $user->id,
    ], $overrides));

    return $import;
}

// ---------------------------------------------------------------------------
// 1. Page loads for admin
// ---------------------------------------------------------------------------

it('ImportStatus page mounts OK for admin', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    Livewire::test(ImportStatus::class)->assertOk();
});

// ---------------------------------------------------------------------------
// 2. Forbidden for editor and viewer
// ---------------------------------------------------------------------------

it('canAccess returns false for editor', function () {
    $editor = is_makeUser('editor');
    $this->actingAs($editor);

    expect(ImportStatus::canAccess())->toBeFalse();
});

it('canAccess returns false for viewer', function () {
    $viewer = is_makeUser('viewer');
    $this->actingAs($viewer);

    expect(ImportStatus::canAccess())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 3. A seeded import row appears in getImports()
// ---------------------------------------------------------------------------

it('shows a seeded import row in getImports()', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    $import = is_makeImport($admin, [
        'file_name' => 'batch_import_2026.csv',
        'total_rows' => 25,
        'completed_at' => now()->timestamp,
    ]);

    $page = new ImportStatus;
    $imports = $page->getImports();

    $found = collect($imports)->firstWhere('id', $import->id);
    expect($found)->not->toBeNull()
        ->and($found['file_name'])->toBe('batch_import_2026.csv');
});

// ---------------------------------------------------------------------------
// 4. Processed / total counts are surfaced correctly
// ---------------------------------------------------------------------------

it('surfaces processed and total row counts', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    $import = is_makeImport($admin, [
        'processed_rows' => 18,
        'total_rows' => 20,
        'successful_rows' => 16,
        'completed_at' => now()->timestamp,
    ]);

    $page = new ImportStatus;
    $imports = $page->getImports();

    $found = collect($imports)->firstWhere('id', $import->id);

    expect($found)->not->toBeNull()
        ->and($found['processed_rows'])->toBe(18)
        ->and($found['total_rows'])->toBe(20)
        ->and($found['successful_rows'])->toBe(16)
        ->and($found['failed_rows'])->toBe(4); // total - successful
});

// ---------------------------------------------------------------------------
// 5. Stalled detection — a long-pending import flags the dead queue worker
//    (NAF Feedback-1 comment #3: "did not import at all / not shown")
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// 6. Every failure carries a visible reason, grouped by message
//    (Charlene 2026-08-10: "there must be an error for every failure")
// ---------------------------------------------------------------------------

it('surfaces the reason for every failed row, grouped by message', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    $import = is_makeImport($admin, [
        'total_rows' => 3,
        'successful_rows' => 1, // → 2 failed
        'completed_at' => now()->timestamp,
    ]);

    // Two identical skips + one JSON-encoded validation error.
    DB::table('failed_import_rows')->insert([
        [
            'import_id' => $import->id,
            'data' => json_encode(['box_number' => '3']),
            'validation_error' => 'Skipped — row already exists (skip duplicates enabled).',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'import_id' => $import->id,
            'data' => json_encode(['box_number' => '4']),
            'validation_error' => 'Skipped — row already exists (skip duplicates enabled).',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'import_id' => $import->id,
            'data' => json_encode(['box_number' => '5']),
            'validation_error' => json_encode(['box_number' => ['The box number field is required.']]),
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $found = collect((new ImportStatus)->getImports())->firstWhere('id', $import->id);

    expect($found['failure_reasons'])->toHaveCount(2); // two DISTINCT reasons

    // The duplicated skip is collapsed to one line with count 2.
    $skip = collect($found['failure_reasons'])
        ->firstWhere('reason', 'Skipped — row already exists (skip duplicates enabled).');
    expect($skip)->not->toBeNull()
        ->and($skip['count'])->toBe(2);

    // A JSON field=>messages error is flattened to a readable sentence.
    $req = collect($found['failure_reasons'])->firstWhere('count', 1);
    expect($req['reason'])->toContain('The box number field is required.');
});

it('exposes no failure_reasons for a clean import', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    $import = is_makeImport($admin, [
        'total_rows' => 5,
        'successful_rows' => 5,
        'completed_at' => now()->timestamp,
    ]);

    $found = collect((new ImportStatus)->getImports())->firstWhere('id', $import->id);
    expect($found['failed_rows'])->toBe(0)
        ->and($found['failure_reasons'])->toBe([]);
});

it('flags a long-pending import as stalled but not a fresh one', function () {
    $admin = is_makeUser('admin');
    $this->actingAs($admin);

    $fresh = is_makeImport($admin, ['file_name' => 'fresh.csv']); // created now, pending
    $old = is_makeImport($admin, ['file_name' => 'old.csv']);     // pending, but aged below
    $old->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    $done = is_makeImport($admin, ['file_name' => 'done.csv', 'completed_at' => now()->timestamp]);

    $imports = collect((new ImportStatus)->getImports())->keyBy('id');

    expect($imports[$fresh->id]['is_stalled'])->toBeFalse()
        ->and($imports[$old->id]['is_stalled'])->toBeTrue()
        ->and($imports[$done->id]['is_stalled'])->toBeFalse();
});
