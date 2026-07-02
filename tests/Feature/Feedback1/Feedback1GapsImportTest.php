<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 gaps — AccessionRowImporter (GROUP IMP).
 *
 * Covers the four client-feedback import gaps:
 *   FB1-GAP-1 — Practice column: "Practice updated by Practice. If not an
 *               option - error to create it first." Unknown practice → row
 *               error; an existing repository-scoped practice passes.
 *   FB1-GAP-2 — 'Current Box Type' column: validated against the ACTIVE
 *               current_box_types lookup; bad code → row error, valid code
 *               sets documents.current_box_type (canonical casing).
 *   FB1-GAP-3 — Accession Type column: "Error if type is not in Batch Type
 *               (renamed Accession Type)" — unknown code → row error.
 *   FB1-GAP-4 — batch.description recomputed as the ', '-joined titles of
 *               ALL accessions linked to the batch after each attach.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (mirror WaveCImporterTest)
// ---------------------------------------------------------------------------

function fgi_repo(string $prefix = 'FGI'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr((string) uniqid(), -6)),
    ]);
}

function fgi_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

function fgi_sa(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'fgi-sa+' . uniqid() . '@test.local',
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
 */
function fgi_run(array $data, int $userId): Importer
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

    // The document-id column was renamed 'identifier' -> 'document_identifier'
    // (so the bare "Identifier" header maps to the authority, not the document).
    // These fixtures still pass 'identifier'; translate it for this importer.
    if (array_key_exists('identifier', $data)) {
        $data['document_identifier'] = $data['identifier'];
        unset($data['identifier']);
    }

    $columnMap = array_combine(array_keys($data), array_keys($data));

    $importer = new AccessionRowImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

// ===========================================================================
// FB1-GAP-1 — Practice must already exist
// ===========================================================================

it('GAP1-Unknown: an unknown practice is a row error and nothing is persisted', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    expect(fn () => fgi_run([
        'identifier' => 'DOC-FGI-PRAC-BAD',
        'accession_number' => 'ACC-FGI-PRAC-BAD',
        'batch_number' => 61,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'practice' => 'NO_SUCH_PRACTICE',
    ], $u->id))->toThrow(ValidationException::class);

    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-FGI-PRAC-BAD')
            ->exists()
    )->toBeFalse();
});

it('GAP1-Valid: an existing repository-scoped practice passes and is stored on the document', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    // Practice scoped to the user's default repository (the row carries no
    // Repository column, so the cascade resolves to the user's default repo).
    Practice::withoutGlobalScopes()->create([
        'name' => 'Valletta Office',
        'identifier' => 'PRC-FGI-1',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    fgi_run([
        'identifier' => 'DOC-FGI-PRAC-OK',
        'accession_number' => 'ACC-FGI-PRAC-OK',
        'batch_number' => 62,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'practice' => 'PRC-FGI-1',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-FGI-PRAC-OK')
        ->first();
    expect($doc)->not->toBeNull();
    expect($doc->practice)->toBe('PRC-FGI-1');
});

// ===========================================================================
// FB1-GAP-2 — Current Box Type lookup validation
// ===========================================================================

it('GAP2-Unknown: an unknown Current Box Type is a row error and nothing is persisted', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    expect(fn () => fgi_run([
        'identifier' => 'DOC-FGI-CBT-BAD',
        'accession_number' => 'ACC-FGI-CBT-BAD',
        'batch_number' => 63,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'current_box_type' => 'Cardboard Tube',
    ], $u->id))->toThrow(ValidationException::class);

    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-FGI-CBT-BAD')
            ->exists()
    )->toBeFalse();
});

it('GAP2-Valid: a valid Current Box Type sets documents.current_box_type with canonical casing', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    fgi_run([
        'identifier' => 'DOC-FGI-CBT-OK',
        'accession_number' => 'ACC-FGI-CBT-OK',
        'batch_number' => 64,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        // Mixed case on purpose — the importer must canonicalise to the
        // lookup's const casing ('RAS Box') exactly like the model save gate.
        'current_box_type' => 'ras box',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-FGI-CBT-OK')
        ->first();
    expect($doc)->not->toBeNull();
    expect($doc->current_box_type)->toBe('RAS Box');
});

// ===========================================================================
// FB1-GAP-3 — Accession Type must be in the batch_types lookup
// ===========================================================================

it('GAP3-Unknown: an Accession Type missing from the batch_types lookup is a row error', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    try {
        fgi_run([
            'identifier' => 'DOC-FGI-BT-BAD',
            'accession_number' => 'ACC-FGI-BT-BAD',
            'batch_number' => 65,
            'box_number' => '1',
            'document_type' => 'Original',
            'series' => 'REG',
            'accession_type' => 'TOTALLY_BOGUS_TYPE',
        ], $u->id);
        $this->fail('Expected ValidationException for unknown Accession Type.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('accession_type');
        expect($e->errors()['accession_type'][0])
            ->toBe("Accession Type 'TOTALLY_BOGUS_TYPE' is not in the Accession Types lookup.");
    }

    // Row failed → nothing persisted (the cascade savepoint rolled back).
    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-FGI-BT-BAD')
            ->exists()
    )->toBeFalse();
    expect(
        Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', 65)
            ->exists()
    )->toBeFalse();
});

// ===========================================================================
// FB1-GAP-4 — batch.description = ', '-joined accession titles
// ===========================================================================

it('GAP4-Description: two accessions on one batch concatenate their titles into batch.description', function (): void {
    $repo = fgi_repo();
    $u = fgi_sa($repo->id);
    $this->actingAs($u);
    fgi_series();

    // Row 1 — accession 'Alpha Title' on batch 66.
    fgi_run([
        'identifier' => 'DOC-FGI-DESC-1',
        'accession_number' => 'ACC-FGI-DESC-A',
        'accession_title' => 'Alpha Title',
        'batch_number' => 66,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', 66)
        ->first();
    expect($batch)->not->toBeNull();
    expect($batch->description)->toBe('Alpha Title');

    // Row 2 — a SECOND accession 'Beta Title' on the SAME batch.
    fgi_run([
        'identifier' => 'DOC-FGI-DESC-2',
        'accession_number' => 'ACC-FGI-DESC-B',
        'accession_title' => 'Beta Title',
        'batch_number' => 66,
        'box_number' => '2',
        'document_type' => 'Original',
        'series' => 'REG',
    ], $u->id);

    // Bug #11 — the batch takes the name of the accession linked FIRST:
    // adding a second accession must NOT concatenate; the description stays
    // the first accession's title (same rule as BatchResource's form).
    expect($batch->refresh()->description)->toBe('Alpha Title');
});
