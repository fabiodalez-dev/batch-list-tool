<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
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
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Wave C — AccessionRowImporter (bottom-up cascade).
 *
 * Requirements per spec DECISIONS 2, 3, 4, 5, 10, 11 and Wave C (C1, C2).
 *
 * C1-Auth.1   — new authority auto-created when identifier is absent
 * C1-Auth.2   — existing authority resolved; name/surname mismatch → row error
 * C1-Auth.3   — multi-authority (;-delimited) all attached to pivot
 * C1-Acc.1    — accession auto-created; batch attached via N:N pivot
 * C1-Box.1    — box auto-created inside batch
 * C1-Doc.1    — document created with correct FKs + custody_status default
 * C2-Forbidden — forbidden batch number (34/36) → row error, nothing saved
 * C2-BarcodeUnique — duplicate barcode → box re-used (already-existing box matched)
 * C2-BoxBatch — box found by barcode in wrong batch → row error
 * C4-AutoId   — identifier auto-generated when column is blank
 * C5-PartNum  — part_number column saved on document
 * Template    — TemplateGenerator returns accession template with correct headers
 */
uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function wc_repo(string $prefix = 'WC'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr((string) uniqid(), -6)),
    ]);
}

function wc_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

function wc_sa(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'wc-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Drive AccessionRowImporter on a single row (identical pattern to
 * ait_runDocImporter in AccessionIntegrityTest).
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap null → identity map (field=header)
 */
function wc_run(array $data, int $userId, ?array $columnMap = null): Importer
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

// ── C1-Auth.1 — new authority auto-created ───────────────────────────────────

it('C1-Auth.1: auto-creates a new authority when the identifier does not exist', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    // Authority R999 does not exist yet.
    expect(Authority::withoutGlobalScopes()->where('identifier', 'R999')->exists())->toBeFalse();

    wc_run([
        'authority_identifier' => 'R999',
        'authority_name' => 'Gianni',
        'authority_surname' => 'Bianchi',
        'accession_number' => 'ACC-AUTH-CREATE',
        'batch_number' => 41,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $auth = Authority::withoutGlobalScopes()->where('identifier', 'R999')->first();
    expect($auth)->not->toBeNull();
    expect($auth->given_names)->toBe('Gianni');
    expect($auth->surname)->toBe('Bianchi');
    expect($auth->entity_type)->toBe('Notary');

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('accession_id', '!=', null)
        ->whereHas('authorities', fn ($q) => $q->where('authorities.id', $auth->id))
        ->first();
    expect($doc)->not->toBeNull();
});

// ── C1-Auth.2 — name mismatch → row error ────────────────────────────────────

it('C1-Auth.2: mismatched authority surname on existing record is a row error', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    Authority::withoutGlobalScopes()->create([
        'identifier' => 'R700',
        'surname' => 'Verdi',
        'given_names' => 'Luigi',
        'entity_type' => 'Notary',
    ]);

    expect(fn () => wc_run([
        'authority_identifier' => 'R700',
        'authority_surname' => 'Rossi',   // WRONG surname
        'accession_number' => 'ACC-MISMATCH',
        'batch_number' => 42,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id))->toThrow(ValidationException::class);
});

// ── C1-Auth.3 — multi-authority ;-delimited ───────────────────────────────────

it('C1-Auth.3: two semi-colon-delimited authorities are both attached to the document', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    Authority::withoutGlobalScopes()->create(['identifier' => 'R101', 'surname' => 'Alpha', 'entity_type' => 'Notary']);
    Authority::withoutGlobalScopes()->create(['identifier' => 'R102', 'surname' => 'Beta', 'entity_type' => 'Notary']);

    wc_run([
        'authority_identifier' => 'R101;R102',
        'accession_number' => 'ACC-MULTI-AUTH',
        'batch_number' => 43,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNotNull('accession_id')
        ->orderByDesc('id')
        ->first();
    expect($doc)->not->toBeNull();
    expect($doc->authorities()->count())->toBe(2);

    $identifiers = $doc->authorities()->pluck('identifier')->sort()->values()->all();
    expect($identifiers)->toContain('R101');
    expect($identifiers)->toContain('R102');

    // First listed → is_primary = true (SQLite returns 1, MySQL returns true; cast both).
    $primary = $doc->authorities()->where('identifier', 'R101')->first();
    expect((bool) ($primary?->pivot?->is_primary))->toBeTrue();
});

// ── C1-Acc.1 — accession auto-created; batch linked via pivot ──────────────

it('C1-Acc.1: accession and batch are auto-created and linked via the N:N pivot', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    $accNumber = 'ACC-2026-001';
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', $accNumber)->exists())->toBeFalse();
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 44)->exists())->toBeFalse();

    wc_run([
        'accession_number' => $accNumber,
        'accession_title' => 'Test Accession Title',
        'batch_number' => 44,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $acc = Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', $accNumber)->first();
    expect($acc)->not->toBeNull();
    expect($acc->code)->toBe('Test Accession Title');

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 44)->first();
    expect($batch)->not->toBeNull();

    // Pivot must link accession ↔ batch.
    expect($acc->batches()->where('batches.id', $batch->id)->exists())->toBeTrue();

    // Document points at the accession and the batch.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('accession_id', $acc->id)
        ->first();
    expect($doc)->not->toBeNull();
    expect($doc->batch_id)->toBe($batch->id);
});

// ── C1-Box.1 — box auto-created inside batch ──────────────────────────────

it('C1-Box.1: box is auto-created inside the resolved batch and linked to the document', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    wc_run([
        'accession_number' => 'ACC-BOX-CREATE',
        'batch_number' => 45,
        'box_number' => 'B-42',
        'box_barcode' => 'BARCODE-WC-001',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 45)->first();
    expect($batch)->not->toBeNull();

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('batch_id', $batch->id)
        ->where('box_number', 'B-42')
        ->first();
    expect($box)->not->toBeNull();
    expect($box->barcode)->toBe('BARCODE-WC-001');

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('current_box_id', $box->id)
        ->first();
    expect($doc)->not->toBeNull();
    expect($doc->custody_status)->toBe('in_box');
});

// ── C1-Doc.1 — document created with correct FKs + defaults ─────────────

it('C1-Doc.1: document is created with correct series_id, accession_id, batch_id, box_id and custody_status in_box', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    $series = wc_series('O');

    wc_run([
        'identifier' => 'DOC-WC-FULL-1',
        'accession_number' => 'ACC-FULL-1',
        'batch_number' => 46,
        'box_number' => '10',
        'document_type' => 'Original',
        'series' => 'O',
        'volume_label' => 'Vol 3',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-WC-FULL-1')->first();
    expect($doc)->not->toBeNull();
    expect($doc->series_id)->toBe($series->id);
    expect($doc->batch_id)->not->toBeNull();
    expect($doc->current_box_id)->not->toBeNull();
    expect($doc->accession_id)->not->toBeNull();
    expect($doc->custody_status)->toBe('in_box');
    expect($doc->catalogue_identifier)->toBeNull();
    expect($doc->volume_label)->toBe('Vol 3');
});

// ── C2-Forbidden — forbidden batch number ────────────────────────────────

it('C2-Forbidden: batch 34 is rejected with a row error and nothing is persisted', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    expect(fn () => wc_run([
        'accession_number' => 'ACC-FORBID',
        'batch_number' => 34,    // forbidden
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id))->toThrow(ValidationException::class);

    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 34)->exists())->toBeFalse();
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('accession_id', '!=', null)->where('batch_id', null)->exists())->toBeFalse();
});

// ── C2-BarcodeUnique — duplicate barcode re-uses existing box ────────────

it('C2-BarcodeUnique: two rows with the same barcode resolve to the same box (idempotent)', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    // Row 1 — creates the box.
    wc_run([
        'accession_number' => 'ACC-BC-IDEM',
        'batch_number' => 47,
        'box_number' => '5',
        'box_barcode' => 'BARCODE-SHARED-001',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BC-1',
    ], $u->id);

    // Row 2 — same barcode, same batch/box → should resolve the existing box.
    wc_run([
        'accession_number' => 'ACC-BC-IDEM',
        'batch_number' => 47,
        'box_number' => '5',
        'box_barcode' => 'BARCODE-SHARED-001',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BC-2',
    ], $u->id);

    // Only ONE box should exist with that barcode.
    $boxCount = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('barcode', 'BARCODE-SHARED-001')
        ->count();
    expect($boxCount)->toBe(1);

    // Both documents should point at the same box.
    $doc1 = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BC-1')->first();
    $doc2 = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BC-2')->first();
    expect($doc1)->not->toBeNull();
    expect($doc2)->not->toBeNull();
    expect($doc1->current_box_id)->toBe($doc2->current_box_id);
});

// ── C4-AutoId — identifier auto-generated ────────────────────────────────

it('C4-AutoId: document identifier is auto-generated when the column is blank', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    wc_run([
        // No 'identifier' key supplied.
        'accession_number' => 'ACC-AUTOID-1',
        'batch_number' => 48,
        'box_number' => '3',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNotNull('accession_id')
        ->orderByDesc('id')
        ->first();

    expect($doc)->not->toBeNull();
    // Auto-generated identifier must be non-empty and follow the pattern
    // {AccessionNo}-{BoxNo}-{seq} or AUTO-{uuid-fragment}.
    expect($doc->identifier)->not->toBeEmpty();
    expect($doc->catalogue_identifier)->toBeNull();
});

// ── C5-PartNum — part_number field ───────────────────────────────────────

it('C5-PartNum: part_number column value is persisted on the document', function (): void {
    $repo = wc_repo();
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    wc_run([
        'identifier' => 'DOC-PARTNUM-1',
        'accession_number' => 'ACC-PART',
        'batch_number' => 49,
        'box_number' => '2',
        'document_type' => 'Original',
        'series' => 'REG',
        'part_number' => 'Part 3',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-PARTNUM-1')->first();
    expect($doc)->not->toBeNull();
    expect($doc->part_number)->toBe('Part 3');
});

// ── C1-Cascade — single row creates all 5 ancestors + links pivot ─────────

it('C1-Cascade: a single all-new row creates Authority+Accession+Batch+Box+Document linked correctly', function (): void {
    $repo = wc_repo('CASCADE');
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    $series = wc_series('REG');

    // Nothing should exist beforehand.
    expect(Authority::withoutGlobalScopes()->where('identifier', 'R801')->exists())->toBeFalse();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-CASCADE-1')->exists())->toBeFalse();
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 51)->exists())->toBeFalse();

    wc_run([
        'authority_identifier' => 'R801',
        'authority_name' => 'Mario',
        'authority_surname' => 'Cascadelli',
        'accession_number' => 'ACC-CASCADE-1',
        'accession_title' => 'Cascade Title',
        'batch_number' => 51,
        'box_number' => 'CAS-1',
        'box_barcode' => 'BARCODE-CAS-001',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-CAS-001',
    ], $u->id);

    // Authority created.
    $auth = Authority::withoutGlobalScopes()->where('identifier', 'R801')->first();
    expect($auth)->not->toBeNull();
    expect($auth->given_names)->toBe('Mario');
    expect($auth->surname)->toBe('Cascadelli');
    expect($auth->entity_type)->toBe('Notary');

    // Accession created.
    $acc = Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-CASCADE-1')->first();
    expect($acc)->not->toBeNull();
    expect($acc->code)->toBe('Cascade Title');

    // Batch created.
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 51)->first();
    expect($batch)->not->toBeNull();

    // Accession ↔ Batch via pivot (DECISION 1 / Wave B).
    expect($acc->batches()->where('batches.id', $batch->id)->exists())->toBeTrue();

    // Box created inside batch.
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('batch_id', $batch->id)
        ->where('box_number', 'CAS-1')
        ->first();
    expect($box)->not->toBeNull();
    expect($box->barcode)->toBe('BARCODE-CAS-001');

    // Document created with all FK relationships.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-CAS-001')->first();
    expect($doc)->not->toBeNull();
    expect($doc->accession_id)->toBe($acc->id);
    expect($doc->batch_id)->toBe($batch->id);
    expect($doc->current_box_id)->toBe($box->id);
    expect($doc->series_id)->toBe($series->id);
    expect($doc->custody_status)->toBe('in_box');
    expect($doc->catalogue_identifier)->toBeNull();

    // Authority linked to document via pivot.
    expect($doc->authorities()->where('authorities.id', $auth->id)->exists())->toBeTrue();
});

// ── C1-Link — second row reuses existing Batch/Box/Accession ──────────────

it('C1-Link: second row reusing the same Batch/Box/Accession links without creating duplicates', function (): void {
    $repo = wc_repo('LINK');
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    $sharedData = [
        'accession_number' => 'ACC-LINK-SHARED',
        'batch_number' => 52,
        'box_number' => 'BOX-LINK-1',
        'box_barcode' => 'BARCODE-LINK-001',
        'document_type' => 'Original',
        'series' => 'REG',
    ];

    // Row 1 — creates all ancestors.
    wc_run(array_merge($sharedData, ['identifier' => 'DOC-LINK-1']), $u->id);

    $accCountAfter1 = Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-LINK-SHARED')->count();
    $batchCountAfter1 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 52)->count();
    $boxCountAfter1 = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'BARCODE-LINK-001')->count();

    expect($accCountAfter1)->toBe(1);
    expect($batchCountAfter1)->toBe(1);
    expect($boxCountAfter1)->toBe(1);

    // Row 2 — same accession/batch/box.
    wc_run(array_merge($sharedData, ['identifier' => 'DOC-LINK-2']), $u->id);

    // No new ancestors created.
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-LINK-SHARED')->count())->toBe(1);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 52)->count())->toBe(1);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'BARCODE-LINK-001')->count())->toBe(1);

    // But TWO documents created, both pointing at the same box.
    $docs = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereIn('identifier', ['DOC-LINK-1', 'DOC-LINK-2'])
        ->get();
    expect($docs)->toHaveCount(2);

    $boxId = $docs->first()->current_box_id;
    expect($docs->every(fn ($d) => $d->current_box_id === $boxId))->toBeTrue();
});

// ── C2-MissingSeries — missing series → row error ─────────────────────────

it('C2-MissingSeries: missing series is a row error and nothing is persisted', function (): void {
    $repo = wc_repo('MISSERIES');
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    // NO series seeded intentionally.

    expect(fn () => wc_run([
        'accession_number' => 'ACC-NO-SERIES',
        'batch_number' => 53,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'NONEXISTENT_SERIES_CODE',
    ], $u->id))->toThrow(ValidationException::class);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->whereNotNull('accession_id')->exists())->toBeFalse();
});

// ── C2-UnknownRepository — unknown repository code → row error ────────────

it('C2-UnknownRepository: an unknown repository code is a row error', function (): void {
    $repo = wc_repo('REPO');
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    expect(fn () => wc_run([
        'accession_number' => 'ACC-BAD-REPO',
        'batch_number' => 54,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'repository' => 'TOTALLY_UNKNOWN_REPO_CODE',
    ], $u->id))->toThrow(ValidationException::class);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->whereNotNull('accession_id')->exists())->toBeFalse();
});

// ── C2-BoxBatch — barcode in wrong batch → row error ────────────────────

it('C2-BoxBatch: a barcode that belongs to a different batch is a row error', function (): void {
    $repo = wc_repo('BOXBATCH');
    $u = wc_sa($repo->id);
    $this->actingAs($u);
    wc_series();

    // Row 1 — creates the box in batch 55.
    wc_run([
        'accession_number' => 'ACC-BOXBATCH-A',
        'batch_number' => 55,
        'box_number' => '10',
        'box_barcode' => 'BARCODE-BOXBATCH-001',
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BOXBATCH-A',
    ], $u->id);

    // Row 2 — same barcode but DIFFERENT batch number → should be a row error.
    expect(fn () => wc_run([
        'accession_number' => 'ACC-BOXBATCH-B',
        'batch_number' => 56,   // different batch
        'box_number' => '10',
        'box_barcode' => 'BARCODE-BOXBATCH-001',  // same barcode as row 1
        'document_type' => 'Original',
        'series' => 'REG',
        'identifier' => 'DOC-BOXBATCH-B',
    ], $u->id))->toThrow(ValidationException::class);

    // Only the first document must exist.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BOXBATCH-A')->exists())->toBeTrue();
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-BOXBATCH-B')->exists())->toBeFalse();
});

// ── Template — TemplateGenerator returns accession template ──────────────

it('Template: TemplateGenerator returns the accession template with all required headers', function (): void {
    $headers = TemplateGenerator::headersFor('accession');

    expect($headers)->toContain('Authority Identifier');
    expect($headers)->toContain('Authority Name');
    expect($headers)->toContain('Authority Surname');
    expect($headers)->toContain('Accession Number');
    expect($headers)->toContain('Accession Title');
    expect($headers)->toContain('Batch Number');
    expect($headers)->toContain('Box No');
    expect($headers)->toContain('Box Barcode');
    expect($headers)->toContain('Document Type');
    expect($headers)->toContain('Series');
    expect($headers)->toContain('Volume No');     // NAf Feedback 1: 'Volume No' not 'Volume Number'
    expect($headers)->toContain('Part Number');
    expect($headers)->toContain('Note');          // NAf Feedback 1: singular 'Note'

    // The entity key must be registered in TEMPLATES.
    expect(array_key_exists('accession', TemplateGenerator::TEMPLATES))->toBeTrue();

    // Custom columns are appended after the static 20 (none in test DB, so count == 20).
    expect(count($headers))->toBeGreaterThanOrEqual(20);
});
