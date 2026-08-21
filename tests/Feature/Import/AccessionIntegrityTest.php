<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * RFQ Wave 2 — Task 8 (B4 / B5): accession-import integrity.
 *
 * Submission §5.2/§5.5/§5.6 — the bulk import must, in one run, dedup-OR-CREATE
 * the Batch + Box referenced by an incoming document row, then link the
 * document to them; it must validate batch/box consistency before commit; and
 * the legacy `status_*` PERM_OUT signal must be reconciled to the BOX
 * (authoritative since Task 7) instead of being written straight onto the
 * document.
 */
uses(RefreshDatabase::class);

/* ─── helpers (self-contained, mirror BulkImportV2Test) ──────────────────── */

function ait_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function ait_makeAdmin(int $repoId): User
{
    ait_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'ait-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function ait_repo(string $prefix = 'AIT'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function ait_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

/**
 * Drive the real DocumentImporter end-to-end on one row, the same way
 * BulkImportV2Test::bi_runImporter does.
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap null → identity map
 */
function ait_runDocImporter(array $data, int $userId, ?array $columnMap = null): Importer
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new DocumentImporter($row, $columnMap, []);
    $importer($data);

    return $importer;
}

/* ─── B5: create-if-absent for Batch + Box ───────────────────────────────── */

test('B5: importing a document with an unknown Batch and Box CREATES both and links the document', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    // Neither batch 7 nor its box "20" exist yet.
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 7)->exists())->toBeFalse();

    ait_runDocImporter([
        'identifier' => 'DOC-CREATE-1',
        'series' => 'REG',
        'batch_number' => 7,
        'current_box_number' => '20',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-CREATE-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->batch_id)->not->toBeNull()
        ->and($doc->current_box_id)->not->toBeNull();

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 7)->first();
    expect($batch)->not->toBeNull()
        ->and($doc->batch_id)->toBe($batch->id);

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box)->not->toBeNull()
        ->and($box->box_number)->toBe('20')
        ->and($box->batch_id)->toBe($batch->id);
});

test('B5: create-if-absent DEGRADES a forbidden batch number (A1.1) — never creates it, never allocates it', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    // RFQ App.1 #1 forbids ALLOCATION into a forbidden batch. No allocation
    // happens, so the row DEGRADES (bulk-import alignment 2026-08): the document
    // imports with batch_id null + a note, and the forbidden batch is never
    // created. (Previously the whole row failed.)
    ait_runDocImporter([
        'identifier' => 'DOC-FORBID-1',
        'series' => 'REG',
        'batch_number' => 34, // forbidden
        'current_box_number' => '5',
    ], $u->id);

    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 34)->exists())->toBeFalse();

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-FORBID-1')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->batch_id)->toBeNull()                                  // never allocated
        ->and($doc->current_box_id)->toBeNull()                            // no batch → no box resolution
        ->and($doc->ras_batch_1)->toBe('34')
        ->and($doc->extra['batch_import_note'] ?? null)->toBe('reserved batch 34 — not linked');
});

/* ─── B5 consistency: document.batch must match its box's batch ──────────── */

test('B5: a document whose batch differs from its resolved box batch is a FAILED row, not silently saved', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    // Pre-existing box "B9" lives in batch 9 — but the row claims batch 8.
    $batch9 = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 9,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS',
        'box_number' => 'B9',
        'batch_id' => $batch9->id,
        'barcode' => 'BC-MISMATCH-1',
    ]);

    // Row resolves its box by barcode (→ batch 9) but declares batch 8.
    try {
        ait_runDocImporter([
            'identifier' => 'DOC-MISMATCH-1',
            'series' => 'REG',
            'batch_number' => 8,
            'barcode_in' => 'BC-MISMATCH-1',
        ], $u->id, [
            'identifier' => 'identifier',
            'series' => 'series',
            'batch_number' => 'batch_number',
            // map barcode_in to the box-resolution column so the box is found by barcode
            'current_box_barcode' => 'barcode_in',
        ]);
        $this->fail('Expected a RowImportFailedException for the batch/box mismatch.');
    } catch (RowImportFailedException $rowImportFailedException) {
        expect($rowImportFailedException->getMessage())->toContain('batch');
    }

    expect(
        Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-MISMATCH-1')->exists()
    )->toBeFalse();
});

/* ─── B4: legacy status PERM_OUT → BOX authoritative status ──────────────── */

test('B4: a PERM_OUT row sets the BOX to PERM_OUT (box carries disinfestation_date) and the document mirrors it', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    ait_runDocImporter([
        'identifier' => 'DOC-PERMOUT-1',
        'series' => 'REG',
        'batch_number' => 11,
        'current_box_number' => '3',
        'disinfestation_date' => '2024-01-15',
        'status_1' => 'PERM_OUT',
    ], $u->id, [
        'identifier' => 'identifier',
        'series' => 'series',
        'batch_number' => 'batch_number',
        'current_box_number' => 'current_box_number',
        'disinfestation_date' => 'disinfestation_date',
        // status_1 has no ImportColumn — drive it straight onto the model.
        'status_1' => 'status_1',
    ]);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-PERMOUT-1')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->not->toBeNull();

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box)->not->toBeNull()
        ->and($box->barcode_status)->toBe('PERM_OUT')
        ->and($box->disinfestation_date)->not->toBeNull();

    // Task 7 mirror: the document reflects the box's authoritative status.
    $doc->refresh();
    expect($doc->barcode_status)->toBe('PERM_OUT');
});

/* ─── Fix 5 (I2): box-status write must be safe / transactional ───────────── */

test('I2: a PERM_OUT row missing a disinfestation_date is now accepted and persisted (loosened, client feedback 2026-08-01)', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    // RFQ App.1 #5 was loosened after client feedback (Charlene Ellul,
    // 2026-08-01): legacy NAF documents are frequently PERM_OUT with no
    // disinfestation date and must import as-is. Client feedback supersedes the
    // RFQ because it is later. The row no longer fails in afterFill.
    ait_runDocImporter([
        'identifier' => 'DOC-PERMOUT-NODATE',
        'series' => 'REG',
        'batch_number' => 12,
        'current_box_number' => '4',
        'status_1' => 'PERM_OUT',
    ], $u->id, [
        'identifier' => 'identifier',
        'series' => 'series',
        'batch_number' => 'batch_number',
        'current_box_number' => 'current_box_number',
        'status_1' => 'status_1',
    ]);

    // The document is persisted with PERM_OUT and no disinfestation date.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-PERMOUT-NODATE')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->barcode_status)->toBe('PERM_OUT')
        ->and($doc->disinfestation_date)->toBeNull();
});

test('I2: a failing box-status write rolls back the just-saved document (no half-saved row)', function () {
    $repo = ait_repo();
    $u = ait_makeAdmin($repo->id);
    $this->actingAs($u);
    ait_series('REG');

    // Force the BOX save to fail at the moment afterSave() writes its status,
    // simulating any guard/DB failure that fires AFTER the document row is
    // already saved. The per-row savepoint must roll the document back.
    //
    // Swap in a throw-away dispatcher (a clone, so it keeps the real
    // model-event listeners) for the duration, then restore the original in
    // finally{} — a leaked saving() listener would pollute later tests in the
    // same process.
    $originalDispatcher = Box::getEventDispatcher();
    $tempDispatcher = clone $originalDispatcher;
    $tempDispatcher->listen('eloquent.saving: ' . Box::class, function (Box $box): void {
        if ($box->barcode_status === 'OUT') {
            throw new RuntimeException('Simulated box-status write failure');
        }
    });
    Box::setEventDispatcher($tempDispatcher);

    try {
        ait_runDocImporter([
            'identifier' => 'DOC-ROLLBACK-1',
            'series' => 'REG',
            'batch_number' => 13,
            'current_box_number' => '6',
            'status_1' => 'OUT', // triggers the simulated failure on the box save
        ], $u->id, [
            'identifier' => 'identifier',
            'series' => 'series',
            'batch_number' => 'batch_number',
            'current_box_number' => 'current_box_number',
            'status_1' => 'status_1',
        ]);
        $this->fail('Expected the simulated box-status failure to surface.');
    } catch (Throwable $throwable) {
        expect($throwable->getMessage())->toContain('Simulated box-status write failure');
    } finally {
        Box::setEventDispatcher($originalDispatcher);
    }

    // The document must NOT be persisted — the savepoint rolled it back.
    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-ROLLBACK-1')->exists()
    )->toBeFalse();
});
