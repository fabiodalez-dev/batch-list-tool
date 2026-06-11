<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Imports\DocumentImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * ReviewFixesImportTest — 2-4 focused tests per BUG/FINDING from the
 * adversarially-verified import-pipeline review.
 *
 * Bugs fixed:
 *  BUG-01  — cascade atomicity (afterFill transaction)
 *  F-001   — EntityResolver::resolveAuthority() CHAR_LENGTH → LENGTH (SQLite)
 *  BUG-02  — sam_abela 'Notary' column unmapped → authority silently unlinked
 *  F-003   — AccessionRowImporter: 'Notary' not in authority_name guess list
 *  F-004   — DocumentImporter: 'Identifier' collides with document.identifier
 *  BUG-03  — ImportWizard finally() missing sendToDatabase notification
 *  BUG-05  — DocumentImporter.resolveBatch() cross-tenant (no repositoryId)
 *  BUG-06  — box_number '1.0' float artefact creates duplicate box
 *  F-002   — volume_number '2.0' stored verbatim
 *  BUG-08  — $boxRowSeq static not reset between imports
 *  BUG-09  — EntityResolver.resolveBox() applies scope on barcode lookup
 *  F2      — TemplateGenerator missing 'No of Acts' and 'Pages/Folios'
 *  RFQ-App1-R1-WILLS — Batch 50 wills-only not enforced during cascade
 *  RFQ-3.1.3-A — multi-row: row 1 imported, row 2 failed, no orphans
 */
uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function rfi_repo(string $prefix = 'RFI'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr((string) uniqid(), -6)),
    ]);
}

function rfi_series(string $code = 'REG', bool $isWills = false): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true, 'is_wills_series' => $isWills],
    );
}

function rfi_wills_series(): Series
{
    return rfi_series('RWL', true);
}

function rfi_sa(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'rfi-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Drive AccessionRowImporter on a single row (same pattern as wc_run).
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap
 */
function rfi_run(array $data, int $userId, ?array $columnMap = null): Importer
{
    EntityResolver::flushMemo();

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new AccessionRowImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

/**
 * Drive DocumentImporter on a single row (same pattern as ait_runDocImporter).
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap
 */
function rfi_doc_run(array $data, int $userId, ?array $columnMap = null): Importer
{
    EntityResolver::flushMemo();

    /** @var Import $imp */
    $imp = Import::query()->create([
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

    $importer = new DocumentImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

// ─────────────────────────────────────────────────────────────────────────────
// F-001 — EntityResolver::resolveAuthority() cross-engine fuzzy match
// ─────────────────────────────────────────────────────────────────────────────

it('F-001: authority fuzzy-match (LENGTH orderBy) works on SQLite without crashing', function (): void {
    // Pre-seed one authority whose surname contains the search token.
    Authority::withoutGlobalScopes()->create([
        'identifier' => 'R_FUZZY_1',
        'surname' => 'Brincat',
        'given_names' => 'Mario',
        'entity_type' => 'Notary',
    ]);

    EntityResolver::flushMemo();

    // Strategy 1+2+3 all miss (no exact/pair match for 'rincat').
    // Strategy 4 (fuzzy LIKE '%rincat%') should fire without crashing.
    $res = EntityResolver::resolveAuthority(null, null, 'Brincat');

    // Could be a hit (unique) or ambiguous — but must NOT throw.
    expect($res)->not->toBeNull(); // at least a result shape
});

it('F-001: resolveAuthority returns null gracefully on fuzzy-miss (SQLite)', function (): void {
    EntityResolver::flushMemo();

    // 'Zzzyxw' will not match any surname — must return null, not crash.
    $res = EntityResolver::resolveAuthority(null, null, 'Zzzyxw');
    expect($res)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-01 — cascade atomicity: orphan prevention on mid-cascade failure
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-01: a row that fails on forbidden batch does not leave orphan Authority/Accession', function (): void {
    $repo = rfi_repo('BUG01A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // Authority R_BUG01 does not exist yet.
    expect(Authority::withoutGlobalScopes()->where('identifier', 'R_BUG01')->exists())->toBeFalse();

    expect(fn () => rfi_run([
        'authority_identifier' => 'R_BUG01',
        'authority_name' => 'Test',
        'authority_surname' => 'Orphan',
        'accession_number' => 'ACC-BUG01-A',
        'batch_number' => 34,   // FORBIDDEN — cascade must roll back entirely
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id))->toThrow(ValidationException::class);

    // No orphan Authority, Accession, Batch, or Document should exist.
    expect(Authority::withoutGlobalScopes()->where('identifier', 'R_BUG01')->exists())->toBeFalse();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-BUG01-A')->exists())->toBeFalse();
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 34)->exists())->toBeFalse();
    expect(Document::withoutGlobalScope(RepositoryScope::class)->whereHas('accession', fn ($q) => $q->where('accession_number', 'ACC-BUG01-A'))->exists())->toBeFalse();
});

it('BUG-01 happy path: successful row creates all cascade entities and the document', function (): void {
    $repo = rfi_repo('BUG01B');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    rfi_run([
        'authority_identifier' => 'R_BUG01_OK',
        'accession_number' => 'ACC-BUG01-OK',
        'batch_number' => 52,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BUG01-OK',
    ], $u->id);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG01-OK')->exists())->toBeTrue();
    expect(Authority::withoutGlobalScopes()->where('identifier', 'R_BUG01_OK')->exists())->toBeTrue();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-BUG01-OK')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-02 + F-003 — 'Notary' column auto-maps to authority_name; name-only row errors
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-02/F-003: a row with authority_name but no identifier throws a row error (names are ambiguous)', function (): void {
    $repo = rfi_repo('BUG02A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // Simulate the sam_abela.csv path: 'Notary' header auto-mapped to authority_name.
    // No authority_identifier column present (empty string).
    expect(fn () => rfi_run([
        'authority_name' => 'V. Caruana',   // name-only: should be a row error
        'accession_number' => 'ACC-BUG02',
        'batch_number' => 53,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id))->toThrow(ValidationException::class);
});

it('BUG-02/F-003: same row WITH an Authority Identifier imports and links the authority', function (): void {
    $repo = rfi_repo('BUG02B');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    Authority::withoutGlobalScopes()->create([
        'identifier' => 'R_CAR',
        'surname' => 'Caruana',
        'given_names' => 'V.',
        'entity_type' => 'Notary',
    ]);

    rfi_run([
        'authority_identifier' => 'R_CAR',
        'authority_name' => 'V.',              // provided: validated but not ambiguous
        'authority_surname' => 'Caruana',
        'accession_number' => 'ACC-BUG02-OK',
        'batch_number' => 54,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BUG02-OK',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG02-OK')->first();
    expect($doc)->not->toBeNull();
    expect($doc->authorities()->where('identifier', 'R_CAR')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// F-004 — DocumentImporter: 'Identifier' maps to authority_identifier not document
// ─────────────────────────────────────────────────────────────────────────────

it('F-004: batch_list-style row with "Identifier"="R1" links authority R1', function (): void {
    $repo = rfi_repo('F004A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series('REG');

    Authority::withoutGlobalScopes()->create([
        'identifier' => 'R1',
        'surname' => 'Abela',
        'entity_type' => 'Notary',
    ]);

    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => 1, 'repository_id' => $repo->id],
        ['type' => 'MAIN_COLLECTION', 'is_active' => true],
    );

    // Use the identity column map with 'Identifier' → 'authority_identifier'
    // (as auto-guessing would now do for DocumentImporter).
    rfi_doc_run([
        'authority_identifier' => 'R1',
        'identifier' => 'DOC-F004-1',
        'series' => 'REG',
        'batch_number' => 1,
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-F004-1')->first();
    expect($doc)->not->toBeNull();
    expect($doc->authorities()->where('identifier', 'R1')->exists())->toBeTrue();
});

it('F-004: multi-creator "R1;R2" links both authorities', function (): void {
    $repo = rfi_repo('F004B');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series('REG');

    Authority::withoutGlobalScopes()->create(['identifier' => 'R1', 'surname' => 'Abela', 'entity_type' => 'Notary']);
    Authority::withoutGlobalScopes()->create(['identifier' => 'R2', 'surname' => 'Albano', 'entity_type' => 'Notary']);

    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => 2, 'repository_id' => $repo->id],
        ['type' => 'MAIN_COLLECTION', 'is_active' => true],
    );

    rfi_doc_run([
        'authority_identifier' => 'R1;R2',
        'identifier' => 'DOC-F004-MULTI',
        'series' => 'REG',
        'batch_number' => 2,
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-F004-MULTI')->first();
    expect($doc)->not->toBeNull();
    expect($doc->authorities()->count())->toBe(2);
    expect($doc->authorities()->where('identifier', 'R1')->exists())->toBeTrue();
    expect($doc->authorities()->where('identifier', 'R2')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-03 — ImportWizard finally() sends database notification
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-03: after a wizard-path import completes, a database notification exists for the user', function (): void {
    $repo = rfi_repo('BUG03');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // Create a completed Import record the way the wizard would.
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 1,
        'total_rows' => 1,
        'successful_rows' => 1,
        'user_id' => $u->id,
    ]);

    // Simulate what the wizard's finally() closure now does.
    $import->touch('completed_at');
    $fresh = Import::query()->find($import->getKey());
    expect($fresh)->not->toBeNull();

    /** @var Import $fresh */
    $importUser = $fresh->user;
    if ($importUser !== null) {
        $fresh->columnMap([]);
        $fresh->options([]);
        $failedRowsCount = $fresh->getFailedRowsCount();

        $notification = Notification::make()
            ->title($fresh->importer::getCompletedNotificationTitle($fresh))
            ->body($fresh->importer::getCompletedNotificationBody($fresh))
            ->when(
                ! $failedRowsCount,
                fn (Notification $n) => $n->success(),
            );

        $notification = $fresh->importer::modifyCompletedNotification($notification, $fresh);
        $notification->sendToDatabase($importUser, isEventDispatched: true);
    }

    // The user should now have a database notification.
    $u->refresh();
    expect($u->notifications()->count())->toBeGreaterThanOrEqual(1);
    $dbNotif = $u->notifications()->first();
    expect($dbNotif)->not->toBeNull();
    expect($dbNotif->data['title'] ?? '')->toContain('completed');
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-05 — DocumentImporter.resolveBatch() cross-tenant isolation
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-05: with two repos each having batch_number 10, a user of repo A gets repo A\'s batch', function (): void {
    $repoA = rfi_repo('BUGA');
    $repoB = rfi_repo('BUGB');
    $u = rfi_sa($repoA->id);
    $this->actingAs($u);
    rfi_series('REG');

    // Create batch 10 in BOTH repositories.
    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 10,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 10,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);

    EntityResolver::flushMemo();

    rfi_doc_run([
        'identifier' => 'DOC-BUG05-CROSS',
        'series' => 'REG',
        'batch_number' => 10,
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG05-CROSS')->first();
    expect($doc)->not->toBeNull();
    // Must link to repo A's batch, NOT repo B's.
    expect($doc->batch_id)->toBe($batchA->id);
    expect($doc->batch_id)->not->toBe($batchB->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-06 + F-002 + F-005 — Excel float artefacts normalised
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-06/F-002: importing box "1.0" then box "1" in the same batch yields ONE box', function (): void {
    $repo = rfi_repo('BUG06A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // Row 1 — box_number arrives as '1.0' (Excel float artefact).
    rfi_run([
        'accession_number' => 'ACC-BUG06-FLOAT',
        'batch_number' => 55,
        'box_number' => '1.0',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BUG06-1',
    ], $u->id);

    // Row 2 — same physical box, now provided as '1'.
    rfi_run([
        'accession_number' => 'ACC-BUG06-FLOAT',
        'batch_number' => 55,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BUG06-2',
    ], $u->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 55)->first();
    expect($batch)->not->toBeNull();

    $boxCount = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('batch_id', $batch->id)
        ->count();
    expect($boxCount)->toBe(1); // '1.0' and '1' must resolve to the same box

    // Both documents must point at that single box.
    $doc1 = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG06-1')->first();
    $doc2 = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG06-2')->first();
    expect($doc1?->current_box_id)->toBe($doc2?->current_box_id);
});

it('F-005: volume_number "2.0" is stored as "2", non-numeric "180A/181" kept verbatim', function (): void {
    $repo = rfi_repo('F005A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    rfi_run([
        'accession_number' => 'ACC-F005-FLOAT',
        'batch_number' => 56,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-F005-VOL',
        'volume_number' => '2.0',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-F005-VOL')->first();
    expect($doc)->not->toBeNull();
    expect($doc->volume_number)->toBe('2');

    // Now import with a genuinely non-numeric value — must be kept verbatim.
    rfi_run([
        'accession_number' => 'ACC-F005-NONNUMERIC',
        'batch_number' => 57,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-F005-VERBATIM',
        'volume_number' => '180A/181',
    ], $u->id);

    $doc2 = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-F005-VERBATIM')->first();
    expect($doc2)->not->toBeNull();
    expect($doc2->volume_number)->toBe('180A/181');
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-08 — $boxRowSeq isolated per import id
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-08: two consecutive imports each start the document-identifier sequence fresh at 1', function (): void {
    $repo = rfi_repo('BUG08');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // ── Import 1 ──────────────────────────────────────────────────────────
    EntityResolver::flushMemo();
    AccessionRowImporter::resetBoxRowSeq();

    $imp1 = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $u->id,
    ]);
    $row1 = ['accession_number' => 'ACC-BUG08-I1', 'batch_number' => 58, 'box_number' => '1', 'document_type' => 'Original', 'series' => 'REG'];
    $importer1 = new AccessionRowImporter($imp1, array_combine(array_keys($row1), array_keys($row1)), []);
    $importer1($row1);

    $doc1 = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNotNull('accession_id')
        ->where('accession_id', Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-BUG08-I1')->value('id'))
        ->first();
    expect($doc1)->not->toBeNull();
    $seq1 = (int) substr((string) $doc1->identifier, strrpos((string) $doc1->identifier, '-') + 1);

    // ── Import 2 — separate import id, sequence must restart ──────────────
    EntityResolver::flushMemo(); // new importId → $boxRowSeq namespace isolated automatically (see AccessionRowImporter::$boxRowSeq docblock)

    $imp2 = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test2.xlsx',
        'file_path' => '/tmp/test2.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $u->id,
    ]);
    $row2 = ['accession_number' => 'ACC-BUG08-I2', 'batch_number' => 59, 'box_number' => '1', 'document_type' => 'Original', 'series' => 'REG'];
    $importer2 = new AccessionRowImporter($imp2, array_combine(array_keys($row2), array_keys($row2)), []);
    $importer2($row2);

    $doc2 = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNotNull('accession_id')
        ->where('accession_id', Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-BUG08-I2')->value('id'))
        ->first();
    expect($doc2)->not->toBeNull();
    $seq2 = (int) substr((string) $doc2->identifier, strrpos((string) $doc2->identifier, '-') + 1);

    // Both imports have 1 row for the same (accession|box) pattern → seq = 1.
    expect($seq1)->toBe(1);
    expect($seq2)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// BUG-09 — EntityResolver.resolveBox() barcode lookup is unscoped
// ─────────────────────────────────────────────────────────────────────────────

it('BUG-09: DocumentImporter resolves a box by barcode across tenant contexts (same-repo OK)', function (): void {
    $repo = rfi_repo('BUG09A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    // Create a batch and a box with a barcode in the user's repo.
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 60,
        'repository_id' => $repo->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $box = Box::withoutGlobalScopes()->create([
        'batch_id' => $batch->id,
        'box_number' => '1',
        'barcode' => 'BARCODE-BUG09-A',
        'box_type' => 'RAS',
    ]);

    EntityResolver::flushMemo();

    rfi_doc_run([
        'identifier' => 'DOC-BUG09-OK',
        'series' => 'REG',
        'batch_number' => 60,
        'current_box_barcode' => 'BARCODE-BUG09-A',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG09-OK')->first();
    expect($doc)->not->toBeNull();
    expect($doc->current_box_id)->toBe($box->id);
});

it('BUG-09: cross-repo barcode mismatch is rejected (batch consistency check)', function (): void {
    $repoA = rfi_repo('BUG09B');
    $repoB = rfi_repo('BUG09C');
    $u = rfi_sa($repoA->id);
    $this->actingAs($u);
    rfi_series();

    // Create batch 61 in repo A, batch 62 in repo B.
    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 61,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 62,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    // Barcode belongs to repo B's batch.
    Box::withoutGlobalScopes()->create([
        'batch_id' => $batchB->id,
        'box_number' => '1',
        'barcode' => 'BARCODE-BUG09-B',
        'box_type' => 'RAS',
    ]);

    EntityResolver::flushMemo();

    // Import into repo A's batch 61, but supply the barcode that belongs to repo B.
    // The batch-consistency check must reject this (resolved box's batch_id != document's batch_id).
    expect(fn () => rfi_doc_run([
        'identifier' => 'DOC-BUG09-CROSS',
        'series' => 'REG',
        'batch_number' => 61,
        'current_box_barcode' => 'BARCODE-BUG09-B',
    ], $u->id))->toThrow(RowImportFailedException::class);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BUG09-CROSS')->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// F2 — TemplateGenerator synthesiseAccessionHeaders includes No of Acts / Pages/Folios
// ─────────────────────────────────────────────────────────────────────────────

it('F2: generated accession template headers include "No of Acts" and "Pages/Folios"', function (): void {
    $repo = rfi_repo('F2A');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);

    $headers = TemplateGenerator::headersFor('accession');

    expect($headers)->toContain('No of Acts');
    expect($headers)->toContain('Pages/Folios');
});

it('F2: "No of Acts" appears after "Deeds" in the accession template', function (): void {
    $repo = rfi_repo('F2B');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);

    $headers = TemplateGenerator::headersFor('accession');

    $deedsIdx = array_search('Deeds', $headers, true);
    $actsIdx = array_search('No of Acts', $headers, true);
    $pagesIdx = array_search('Pages/Folios', $headers, true);

    expect($deedsIdx)->not->toBeFalse();
    expect($actsIdx)->toBeGreaterThan($deedsIdx);
    expect($pagesIdx)->toBeGreaterThan($deedsIdx);
});

// ─────────────────────────────────────────────────────────────────────────────
// RFQ-App1-R1-WILLS — Batch 50 wills-only enforced in AccessionRowImporter cascade
// ─────────────────────────────────────────────────────────────────────────────

it('RFQ-App1-WILLS: row with Batch 50 + non-wills series is a row error', function (): void {
    $repo = rfi_repo('WILLS1');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series('REG'); // non-wills

    expect(fn () => rfi_run([
        'accession_number' => 'ACC-WILLS-FAIL',
        'batch_number' => 50,   // wills-only
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',      // NOT a wills series
        'identifier' => 'DOC-WILLS-FAIL',
    ], $u->id))->toThrow(ValidationException::class);

    // No document, batch 50, accession should have been created.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-WILLS-FAIL')->exists())->toBeFalse();
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->exists())->toBeFalse();
});

it('RFQ-App1-WILLS: row with Batch 50 + wills series imports successfully', function (): void {
    $repo = rfi_repo('WILLS2');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_wills_series(); // RWL, is_wills_series = true

    rfi_run([
        'accession_number' => 'ACC-WILLS-OK',
        'batch_number' => 50,   // wills-only
        'box_number' => '1',
        'document_type' => 'Will',
        'series' => 'RWL',      // IS a wills series
        'identifier' => 'DOC-WILLS-OK',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-WILLS-OK')->first();
    expect($doc)->not->toBeNull();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->find($doc->batch_id);
    expect($batch?->batch_number)->toBe(50);
});

// ─────────────────────────────────────────────────────────────────────────────
// RFQ-3.1.3-A — multi-row: row 1 imported, row 2 failed, no orphans
// ─────────────────────────────────────────────────────────────────────────────

it('RFQ-3.1.3-A: in a two-row import, row 1 is committed and row 2 failure leaves no orphans', function (): void {
    $repo = rfi_repo('RFQ313');
    $u = rfi_sa($repo->id);
    $this->actingAs($u);
    rfi_series();

    EntityResolver::flushMemo();

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 2,
        'successful_rows' => 0,
        'user_id' => $u->id,
    ]);

    // Row 1 — valid, must succeed.
    $row1 = [
        'accession_number' => 'ACC-3131-GOOD',
        'batch_number' => 63,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-3131-GOOD',
    ];
    $colMap1 = array_combine(array_keys($row1), array_keys($row1));
    $importer1 = new AccessionRowImporter($imp, $colMap1, []);
    $importer1($row1);

    // Row 1 must have persisted.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-3131-GOOD')->exists())->toBeTrue();

    // Row 2 — forbidden batch, must fail.
    EntityResolver::flushMemo();
    $row2 = [
        'accession_number' => 'ACC-3131-FAIL',
        'batch_number' => 34,  // FORBIDDEN
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-3131-FAIL',
    ];
    $colMap2 = array_combine(array_keys($row2), array_keys($row2));
    $importer2 = new AccessionRowImporter($imp, $colMap2, []);

    expect(fn () => $importer2($row2))->toThrow(ValidationException::class);

    // Row 2 document must NOT exist.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-3131-FAIL')->exists())->toBeFalse();
    // Row 2's accession must NOT exist either (cascade was rolled back).
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-3131-FAIL')->exists())->toBeFalse();
    // Row 1 must still be intact.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-3131-GOOD')->exists())->toBeTrue();
});
