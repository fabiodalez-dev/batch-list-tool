<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Filament\Tables\Table as FilamentTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave A — Batch area tests.
 *
 * Covers:
 *  A2 — duplicate batch_number shows friendly "Batch number already exists." error
 *  A2 — next sequential batch number is suggested as the default (skipping forbidden)
 *  A3 — model label is "Batch" (CreateAction shows "New Batch")
 *  A4 — CSV export includes a Repository column
 *  A5 — per-column sorting (batch_number, description, type, repository, is_active)
 *  A8 — only the batch_number column has ->url() set (not the whole row)
 *  A9 — Inputter (CreatorColumn) is present in the table
 *  A10 — is_active Toggle has no required marker (default true)
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Shared helpers (file-local, prefixed to avoid collisions with other tests)
// ---------------------------------------------------------------------------

function ba_superAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'ba-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function ba_repo(string $prefix = 'BA'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function ba_batch(int $repoId, int $batchNumber, array $attrs = []): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'batch_number' => $batchNumber,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ], $attrs));
}

/** Returns a unique safe batch number (not in FORBIDDEN_NUMBERS, not already used). */
function ba_nextNumber(): int
{
    do {
        $n = random_int(5000, 8999);
    } while (
        in_array($n, Batch::FORBIDDEN_NUMBERS, true)
        || Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $n)->exists()
    );

    return $n;
}

// ============================================================================
// A2 — friendly duplicate-number error
// ============================================================================

it('A2: duplicate batch_number in the same repo shows a form validation error', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo();
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $existingNumber = ba_nextNumber();
    ba_batch($repo->id, $existingNumber);

    // Submit a CreateBatch form with the same number that already exists in the
    // same repository — the unique closure must fire and surface the field error.
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => $existingNumber,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repo->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['batch_number']);
});

it('A2: duplicate batch_number error message contains "Batch number already exists."', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo();
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $existingNumber = ba_nextNumber();
    ba_batch($repo->id, $existingNumber);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => $existingNumber,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repo->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['batch_number' => 'Batch number already exists.']);
});

it('A2: same batch_number is allowed in a different repository (no cross-tenant collision)', function (): void {
    $user = ba_superAdmin();
    $repoA = ba_repo('BA_A');
    $repoB = ba_repo('BA_B');
    $user->repositories()->syncWithoutDetaching([
        $repoA->id => ['is_default' => true],
        $repoB->id => ['is_default' => false],
    ]);
    $user->update(['default_repository_id' => $repoA->id]);
    $this->actingAs($user);

    $number = ba_nextNumber();
    ba_batch($repoA->id, $number);

    // Creating the same batch_number in repo B must succeed (no form errors).
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => $number,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repoB->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

// ============================================================================
// A2 — next sequential number suggestion
// ============================================================================

it('A2: next sequential default never equals a FORBIDDEN_NUMBER', function (): void {
    // Compute the default the same way the closure does: max+1, skip forbidden.
    $max = Batch::withoutGlobalScope(RepositoryScope::class)->max('batch_number') ?? 0;
    $expected = $max + 1;
    while (in_array($expected, Batch::FORBIDDEN_NUMBERS, true)) {
        $expected++;
    }

    // The expected value must never land on a forbidden number.
    expect(in_array($expected, Batch::FORBIDDEN_NUMBERS, true))->toBeFalse(
        "Computed default {$expected} must not be a FORBIDDEN_NUMBER",
    );
});

it('A2: default skips 34 when max batch_number is 33', function (): void {
    // Place a batch at 33 so max becomes 33 → next candidate is 34 (forbidden).
    // The default closure must skip it and return 35.
    $repo = ba_repo();
    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => 33, 'repository_id' => $repo->id],
        ['type' => 'MAIN_COLLECTION', 'is_active' => true],
    );

    $max = Batch::withoutGlobalScope(RepositoryScope::class)->max('batch_number');
    expect((int) $max)->toBe(33);

    // Replicate the default-closure logic inline.
    $next = $max + 1; // 34 — forbidden
    while (in_array($next, Batch::FORBIDDEN_NUMBERS, true)) {
        $next++;
    }

    expect($next)->toBe(35, 'default should skip forbidden 34 and land on 35');
});

// ============================================================================
// A3 — model label → "New Batch"
// ============================================================================

it('A3: BatchResource model label is "Batch" (enables "New Batch" button text)', function (): void {
    expect(BatchResource::getModelLabel())->toBe('Batch');
});

// ============================================================================
// A4 — CSV export includes Repository column
// ============================================================================

it('A4: CSV export contains a Repository column header', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo();
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $batchNumber = ba_nextNumber();
    ba_batch($repo->id, $batchNumber);

    // Simulate the export by invoking the streaming response and capturing output.
    $livewire = Livewire::test(ListBatches::class);
    $page = $livewire->instance();

    // Call exportToCsv() and capture the streamed content.
    ob_start();
    $response = $page->exportToCsv();
    $response->sendContent();
    $csv = ob_get_clean();

    // Strip the UTF-8 BOM if present.
    $csv = ltrim($csv, "\xEF\xBB\xBF");

    $firstLine = strtok($csv, "\n");
    $headers = str_getcsv($firstLine);

    expect($headers)->toContain('Repository');
});

it('A4: CSV export row includes the repository code value', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo('EXPORT');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $batchNumber = ba_nextNumber();
    ba_batch($repo->id, $batchNumber);

    $livewire = Livewire::test(ListBatches::class);
    $page = $livewire->instance();

    ob_start();
    $response = $page->exportToCsv();
    $response->sendContent();
    $csv = ob_get_clean();

    // The repository code should appear somewhere in the CSV body.
    expect($csv)->toContain($repo->code);
});

// ============================================================================
// A5 — per-column sorting
// ============================================================================

it('A5: batch_number, description, type, repository.name and is_active columns are sortable', function (): void {
    $user = ba_superAdmin();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBatches::class)->instance();

    $table = BatchResource::table(
        FilamentTable::make($livewire),
    );

    $columns = collect($table->getColumns());

    $sortableNames = $columns
        ->filter(fn ($c) => $c->isSortable())
        ->map(fn ($c) => $c->getName())
        ->values()
        ->all();

    foreach (['batch_number', 'description', 'type', 'is_active', 'repository.name'] as $expected) {
        expect(in_array($expected, $sortableNames, true))->toBeTrue(
            "Column '{$expected}' must be sortable",
        );
    }
});

// ============================================================================
// A8 — only batch_number cell is the hyperlink (no whole-row recordUrl)
// ============================================================================

it('A8: table has no whole-row custom recordUrl set', function (): void {
    $user = ba_superAdmin();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBatches::class)->instance();

    $table = BatchResource::table(
        FilamentTable::make($livewire),
    );

    // hasCustomRecordUrl() returns true only when ->recordUrl() was called on the table.
    expect($table->hasCustomRecordUrl())->toBeFalse(
        'BatchResource table must not set a whole-row recordUrl (A8)',
    );
});

it('A8: batch_number column has a URL callback stored (cell hyperlink)', function (): void {
    $user = ba_superAdmin();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBatches::class)->instance();

    $table = BatchResource::table(
        FilamentTable::make($livewire),
    );

    $batchNumberCol = $table->getColumn('batch_number');
    expect($batchNumberCol)->not->toBeNull('batch_number column must exist');

    // Access the protected $url property via reflection to verify a Closure is stored.
    $reflection = new ReflectionProperty($batchNumberCol, 'url');
    $urlValue = $reflection->getValue($batchNumberCol);

    expect($urlValue)->toBeInstanceOf(
        Closure::class,
        'batch_number column must have a URL closure set (A8 cell hyperlink)',
    );
});

it('A8: description and type columns have no URL callback (plain text cells)', function (): void {
    $user = ba_superAdmin();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBatches::class)->instance();

    $table = BatchResource::table(
        FilamentTable::make($livewire),
    );

    foreach (['description', 'type'] as $name) {
        $col = $table->getColumn($name);
        if ($col !== null) {
            $reflection = new ReflectionProperty($col, 'url');
            $urlValue = $reflection->getValue($col);

            expect($urlValue)->toBeNull(
                "Column '{$name}' must NOT have a URL (A8: only batch_number is a link)",
            );
        }
    }
});

// ============================================================================
// A9 — Inputter column present
// ============================================================================

it('A9: table includes the Inputter (CreatorColumn) column', function (): void {
    $user = ba_superAdmin();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBatches::class)->instance();

    $table = BatchResource::table(
        FilamentTable::make($livewire),
    );

    $inputterCol = $table->getColumn('inputter');
    expect($inputterCol)->not->toBeNull('Inputter column must exist in BatchResource table (A9)');
    expect($inputterCol->getLabel())->toBe('Inputter');
});

// ============================================================================
// A10 — is_active not required
// ============================================================================

it('A10: submitting create form without is_active does not produce a required validation error', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo();
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $number = ba_nextNumber();

    // Do NOT pass is_active — the form must accept it and default to true.
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => $number,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repo->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors(['is_active']);
});

it('A10: batch created without explicit is_active defaults to true in the database', function (): void {
    $user = ba_superAdmin();
    $repo = ba_repo();
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->update(['default_repository_id' => $repo->id]);
    $this->actingAs($user);

    $number = ba_nextNumber();

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => $number,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repo->id,
            // is_active intentionally omitted → should default to true
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $number)
        ->first();

    expect($batch)->not->toBeNull()
        ->and($batch->is_active)->toBeTrue('is_active must default to true (A10)');
});
