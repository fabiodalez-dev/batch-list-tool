<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * The `nra:import-batch-list` bulk command now DELEGATES every row through
 * {@see DocumentImporter}, so a bulk import must apply the
 * SAME wizard side-effects: box_movements, box_barcode_history, the
 * current_box_id repoint, accession/authority pivots and identifier history.
 *
 * These tests run the REAL command against a synthetic NAF-shaped fixture (the
 * duplicated barcode blocks, a leading-space " RAS Box 1" header, In-Situ chains,
 * a reserved batch, a blank-identifier row and an 'Unknown' series) and assert
 * every side effect lands — then re-run it to prove idempotency.
 */
uses(RefreshDatabase::class);

const IBL_FIXTURE = __DIR__ . '/../../Fixtures/batch_list_command_sample.csv';

function ibl_seed(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $admin */
    $admin = User::factory()->create([
        'email' => 'ibl-admin@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $admin->assignRole('super_admin');

    // Authority linked match-only by the "Authority Identifier" column (R100).
    Authority::create(['identifier' => 'R100', 'surname' => 'Test', 'entity_type' => 'PERSON']);

    return [$repo, $admin];
}

function ibl_run(User $admin): void
{
    test()->artisan('nra:import-batch-list', [
        '--file' => IBL_FIXTURE,
        '--user' => $admin->email,
        '--repo' => 'NRA',
        '--limit' => 20,
        '--truncate-data' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
}

function ibl_doc(string $identifier): Document
{
    return Document::withoutGlobalScopes()->where('identifier', $identifier)->firstOrFail();
}

test('bulk command delegates through DocumentImporter and applies every wizard side-effect', function () {
    [$repo, $admin] = ibl_seed();

    ibl_run($admin);

    // ── Series bootstrap (incl. the literal 'Unknown') ──────────────────
    $reg = Series::where('code', 'REG')->first();
    $unknown = Series::where('code', 'Unknown')->first();
    expect($reg)->not->toBeNull()
        ->and($unknown)->not->toBeNull();

    $batch1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $batch2 = Batch::withoutGlobalScopes()->where('batch_number', 2)->firstOrFail();
    $box1 = Box::withoutGlobalScopes()->where('batch_id', $batch1->id)->where('box_number', '1')->firstOrFail();
    $box5 = Box::withoutGlobalScopes()->where('batch_id', $batch2->id)->where('box_number', '5')->firstOrFail();

    // ── DOC-1: current_box_id repointed to the NEWEST box; batch_id archival ─
    $doc1 = ibl_doc('DOC-1');
    expect($doc1->current_box_id)->toBe($box5->id)   // repointed to RAS Box 2 (newest)
        ->and($doc1->batch_id)->toBe($batch1->id);    // archival RAS batch stays

    // ── box_movements: legacy chain null→box1 (seq1), box1→box5 (seq2) ──
    $moves = BoxMovement::withoutGlobalScopes()
        ->where('document_id', $doc1->getKey())
        ->orderBy('sequence')->get();
    expect($moves)->toHaveCount(2)
        ->and($moves[0]->from_box_id)->toBeNull()
        ->and($moves[0]->to_box_id)->toBe($box1->id)
        ->and($moves[1]->from_box_id)->toBe($box1->id)
        ->and($moves[1]->to_box_id)->toBe($box5->id)
        ->and($moves[0]->date_source)->toBe(BoxMovement::DATE_SOURCE_LEGACY);

    // ── box_barcode_history: n-1 transition rows per block ──────────────
    $b1hist = BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $box1->id)->where('source', BoxBarcodeHistory::SOURCE_LEGACY)->get();
    expect($b1hist)->toHaveCount(1)
        ->and($b1hist[0]->previous_barcode)->toBe('AA1')
        ->and($b1hist[0]->new_barcode)->toBe('AA2');

    $b2hist = BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $box5->id)->where('source', BoxBarcodeHistory::SOURCE_LEGACY)->get();
    expect($b2hist)->toHaveCount(1)
        ->and($b2hist[0]->previous_barcode)->toBe('BB1')  // the block-2 'Barcode RAS 1 (2)' value
        ->and($b2hist[0]->new_barcode)->toBe('BB2');

    // ── box terminal barcode + status (PERM_OUT stays PERM_OUT; IN is IN) ─
    expect($box1->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($box1->fresh()->barcode)->toBe('AA2')
        ->and($box5->fresh()->barcode_status)->toBe('IN')   // NOT IN-by-default: it carries Barcode (IN) (2)
        ->and($box5->fresh()->barcode)->toBe('BB2');

    // ── document_authority pivot (R100 primary) ────────────────────────
    $r100 = Authority::where('identifier', 'R100')->firstOrFail();
    expect($doc1->authorities()->where('authorities.id', $r100->id)->wherePivot('is_primary', true)->exists())->toBeTrue();

    // ── accession_batch pivot (accession + batch 1) ─────────────────────
    $acc = Accession::withoutGlobalScopes()->where('code', 'Test Accession One')->firstOrFail();
    expect($acc->batches()->where('batches.id', $batch1->id)->exists())->toBeTrue();

    // ── document_identifier_history (DOC-3 prev attributed) ─────────────
    $doc3 = ibl_doc('DOC-3');
    $hist = DocumentIdentifierHistory::withoutGlobalScopes()
        ->where('document_id', $doc3->getKey())->where('previous_identifier', 'OLD-3')->first();
    expect($hist)->not->toBeNull()
        ->and($hist->previous_volume)->toBe('V2');

    // ── torre true (filled) vs false (blank) ────────────────────────────
    expect((bool) $doc1->torre)->toBeTrue()
        ->and((bool) ibl_doc('DOC-2')->torre)->toBeFalse();

    // ── reserved batch 34 → DEGRADE (null batch_id + note), not failed ──
    $doc4 = ibl_doc('DOC-4');
    expect($doc4->batch_id)->toBeNull()
        ->and($doc4->ras_batch_1)->toBe('34')
        ->and($doc4->extra['batch_import_note'] ?? null)->toBe('reserved batch 34 — not linked');

    // ── 'Unknown' series row imports ────────────────────────────────────
    $doc6 = ibl_doc('DOC-6');
    expect($doc6->series_id)->toBe($unknown->id);

    // ── In-Situ movement chain ('NRA 3') repoints DOC-2 onto the In-Situ box ─
    $doc2 = ibl_doc('DOC-2');
    $inSitu = Box::withoutGlobalScopes()->find($doc2->current_box_id);
    expect($inSitu)->not->toBeNull()
        ->and($inSitu->box_type)->toBe('NRA')
        ->and((string) $inSitu->box_number)->toBe('3')
        ->and($doc2->batch_id)->toBe($batch1->id); // archival batch stays

    // ── Seal applied to the box (only when it had none) ─────────────────
    $box6 = Box::withoutGlobalScopes()->where('batch_id', $batch1->id)->where('box_number', '6')->firstOrFail();
    expect($box6->seal_number)->toBe('SEAL-6');

    // ── Float-artefact normalisation ('9.0'/'1.0') resolved the box ─────
    expect(Box::withoutGlobalScopes()->where('batch_id', $batch1->id)->where('box_number', '9')->exists())->toBeTrue();
});

test('re-running the command on the unchanged file is idempotent', function () {
    [$repo, $admin] = ibl_seed();

    ibl_run($admin);

    $docs = Document::withoutGlobalScopes()->count();
    $moves = BoxMovement::withoutGlobalScopes()->count();
    $history = BoxBarcodeHistory::withoutGlobalScopes()->count();

    // Two blank-identifier rows (distinct content) must stay distinct, never merge.
    expect(Document::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(12);

    ibl_run($admin);

    expect(Document::withoutGlobalScopes()->count())->toBe($docs)
        ->and(BoxMovement::withoutGlobalScopes()->count())->toBe($moves)
        ->and(BoxBarcodeHistory::withoutGlobalScopes()->count())->toBe($history);
});

test('--truncate-data via --force never truncates authorities or series (master data)', function () {
    [$repo, $admin] = ibl_seed();

    // A pre-existing authority + series that the import path never re-creates.
    $keepAuth = Authority::create(['identifier' => 'R-KEEP', 'surname' => 'Keep', 'entity_type' => 'PERSON']);
    $keepSeries = Series::firstOrCreate(['code' => 'KEEP'], ['title' => 'Keep', 'is_active' => true, 'repository_id' => $repo->id]);

    ibl_run($admin); // runs WITH --truncate-data --force

    expect(Authority::where('identifier', 'R-KEEP')->exists())->toBeTrue()
        ->and(Series::where('code', 'KEEP')->exists())->toBeTrue()
        // R100 (seeded) survives too — authorities table untouched by truncate.
        ->and(Authority::where('identifier', 'R100')->exists())->toBeTrue();
});
