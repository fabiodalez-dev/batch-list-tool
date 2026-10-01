<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetHeaders;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Wave F2 — number_of_acts + pages_folios: schema, model, importer, export.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (mirror wc_* pattern from WaveCImporterTest)
// ---------------------------------------------------------------------------

function wf2_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'WF2-' . strtoupper(substr((string) uniqid(), -6)),
    ]);
}

function wf2_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

function wf2_sa(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'wf2-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function wf2_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'WF2-' . substr((string) uniqid(), -6),
        'repository_id' => $repoId,
        'series_id' => $seriesId,
    ], $attrs));
}

/**
 * Drive AccessionRowImporter on a single row (same pattern as wc_run).
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap
 */
function wf2_run(array $data, int $userId, ?array $columnMap = null): Importer
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

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new AccessionRowImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

// ===========================================================================
// Schema
// ===========================================================================

it('F2-Schema.1: documents.number_of_acts column exists', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('documents', 'number_of_acts'))->toBeTrue();
});

it('F2-Schema.2: documents.pages_folios column exists', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('documents', 'pages_folios'))->toBeTrue();
});

// ===========================================================================
// Model round-trips
// ===========================================================================

it('F2-Model.1: number_of_acts persists and retrieves correctly', function (): void {
    $repo = wf2_repo();
    $series = wf2_series();
    $doc = wf2_doc($repo->id, $series->id, ['number_of_acts' => '42']);

    $doc->refresh();
    expect($doc->number_of_acts)->toBe('42');
});

it('F2-Model.2: pages_folios persists and retrieves correctly (dirty string)', function (): void {
    $repo = wf2_repo();
    $series = wf2_series();
    $doc = wf2_doc($repo->id, $series->id, ['pages_folios' => 'approx. 120 ff.']);

    $doc->refresh();
    expect($doc->pages_folios)->toBe('approx. 120 ff.');
});

it('F2-Model.3: both fields are nullable', function (): void {
    $repo = wf2_repo();
    $series = wf2_series();
    $doc = wf2_doc($repo->id, $series->id);

    $doc->refresh();
    expect($doc->number_of_acts)->toBeNull();
    expect($doc->pages_folios)->toBeNull();
});

// ===========================================================================
// End-to-end importer
// ===========================================================================

it('F2-Import.1: importer row populates number_of_acts and pages_folios', function (): void {
    $repo = wf2_repo();
    $u = wf2_sa($repo->id);
    $this->actingAs($u);
    wf2_series('REG');

    wf2_run([
        'identifier' => 'DOC-F2-001',
        'accession_number' => 'ACC-F2-001',
        'batch_number' => 81,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'number_of_acts' => '55',
        'pages_folios' => 'ff. 1-120',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-F2-001')
        ->first();

    expect($doc)->not->toBeNull();
    expect($doc->number_of_acts)->toBe('55');
    expect($doc->pages_folios)->toBe('ff. 1-120');
});

// ===========================================================================
// Export: both fields travel in the CSV, under the headers the importer reads
// ===========================================================================

it('F2-Export: the documents export carries No of Acts and Pages/Folios, with their values', function (): void {
    $repo = wf2_repo();
    $series = wf2_series();
    $this->actingAs(wf2_sa($repo->id));
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'F2-EXP-1', 'repository_id' => $repo->id, 'series_id' => $series->id,
        'document_type' => 'Original', 'number_of_acts' => '55', 'pages_folios' => 'ff. 1-120',
    ]);

    $component = Livewire::test(ListDocuments::class)->callAction('export_csv');
    $csv = preg_replace('/^\x{FEFF}/u', '', (string) base64_decode((string) $component->effects['download']['content'], true)) ?? '';
    $lines = array_values(array_filter(explode("\n", $csv)));
    $header = str_getcsv($lines[0], escape: '\\');
    $row = str_getcsv($lines[1], escape: '\\');

    expect($row[array_search('No of Acts', $header, true)])->toBe('55')
        ->and($row[array_search('Pages/Folios', $header, true)])->toBe('ff. 1-120');

    // ...and the importer maps those headers straight back onto the two fields.
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, SpreadsheetHeaders::dedupe($header));
    expect($map['number_of_acts'])->toBe('No of Acts')
        ->and($map['pages_folios'])->toBe('Pages/Folios');
});

it('F2-Export: the selected-rows export carries them too', function (): void {
    $repo = wf2_repo();
    $series = wf2_series();
    $this->actingAs(wf2_sa($repo->id));
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'F2-EXP-2', 'repository_id' => $repo->id, 'series_id' => $series->id,
        'document_type' => 'Original', 'number_of_acts' => '7', 'pages_folios' => 'pp. 1-30',
    ]);

    // The table's own bulk action, on the selected row.
    $component = Livewire::test(ListDocuments::class)->callTableBulkAction('bulkExportSelected', [$doc]);
    $csv = preg_replace('/^\x{FEFF}/u', '', (string) base64_decode((string) $component->effects['download']['content'], true)) ?? '';
    $lines = array_values(array_filter(explode("\n", $csv)));
    $header = str_getcsv($lines[0], escape: '\\');
    $row = str_getcsv($lines[1], escape: '\\');

    expect($row[array_search('No of Acts', $header, true)])->toBe('7')
        ->and($row[array_search('Pages/Folios', $header, true)])->toBe('pp. 1-30');
});
