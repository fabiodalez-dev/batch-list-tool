<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.3 — Bulk import (CSV/Excel) of new accessions.
 *
 * The five Importer classes form the bulk-import surface. These ten tests
 * pin the column declaration contracts, model bindings, and template
 * generation symmetry — the bits an integration test in BulkImportV2Test.php
 * does not.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('§ 3.1.3 #1: AuthorityImporter is bound to App\\Models\\Authority', function () {
    expect(AuthorityImporter::getModel())->toBe(Authority::class);
});

it('§ 3.1.3 #2: SeriesImporter is bound to App\\Models\\Series', function () {
    expect(SeriesImporter::getModel())->toBe(Series::class);
});

it('§ 3.1.3 #3: BatchImporter is bound to App\\Models\\Batch', function () {
    expect(BatchImporter::getModel())->toBe(Batch::class);
});

it('§ 3.1.3 #4: BoxImporter is bound to App\\Models\\Box', function () {
    expect(BoxImporter::getModel())->toBe(Box::class);
});

it('§ 3.1.3 #5: DocumentImporter is bound to App\\Models\\Document', function () {
    expect(DocumentImporter::getModel())->toBe(Document::class);
});

it('§ 3.1.3 #6: BatchImporter columns include batch_number, type, description, is_active, repository_code', function () {
    $cols = collect(BatchImporter::getColumns())->map(fn ($c) => $c->getName());
    expect($cols->all())->toContain('batch_number')
        ->and($cols->all())->toContain('type')
        ->and($cols->all())->toContain('description')
        ->and($cols->all())->toContain('is_active')
        ->and($cols->all())->toContain('repository_code');
});

it('§ 3.1.3 #7: BoxImporter columns include box_type, barcode_status, parent_box (FK by name)', function () {
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    expect($cols)->toContain('box_type')
        ->and($cols)->toContain('barcode_status');
});

it('§ 3.1.3 #8: SeriesImporter columns include code, title, is_wills_series', function () {
    $cols = collect(SeriesImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    expect($cols)->toContain('code')
        ->and($cols)->toContain('title')
        ->and($cols)->toContain('is_wills_series');
});

it('§ 3.1.3 #9: TemplateGenerator::TEMPLATES has all 5 entities (auth/series/batch/box/document)', function () {
    expect(TemplateGenerator::TEMPLATES)->toHaveKey('authority')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('series')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('batch')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('box')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('document');
});

it('§ 3.1.3 #10: TemplateGenerator::GENERATOR_VERSION is a semver-like string', function () {
    expect(TemplateGenerator::GENERATOR_VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});

/* ─────────── §3.1.3 Pre-commit validation behaviour (RFQ-3.1.3-B) ───────────── */

// Helper: seed a super_admin and attach a default repository.
function s313_sa(): array
{
    $repo = Repository::factory()->create(['code' => '313-' . substr(uniqid(), -4)]);
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => '313-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    Series::firstOrCreate(
        ['code' => 'REG'],
        ['title' => 'Registers Private Practice', 'is_active' => true, 'is_wills_series' => false],
    );

    return [$repo, $u];
}

// Helper: drive AccessionRowImporter on a single row.
function s313_run(array $data, int $userId, ?array $columnMap = null): Importer
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

it('§ 3.1.3-B #1: a row with a forbidden batch number fails and commits no records', function () {
    [, $u] = s313_sa();
    actingAs($u);

    $row = [
        'accession_number' => 'ACC-B-FAIL',
        'batch_number' => 34,   // FORBIDDEN
        'box_number' => '1',
        'identifier' => 'DOC-B-FAIL',
        'document_type' => 'DEED',
        'series' => 'REG',
    ];

    expect(fn () => s313_run($row, $u->id))->toThrow(ValidationException::class);

    // No records committed.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-B-FAIL')->exists())->toBeFalse();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-B-FAIL')->exists())->toBeFalse();
});

it('§ 3.1.3-B #2: row 1 committed, row 2 forbidden — row 1 intact, row 2 produces no orphans', function () {
    [, $u] = s313_sa();
    actingAs($u);

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

    // Row 1 — valid.
    $row1 = [
        'accession_number' => 'ACC-2ROW-OK',
        'batch_number' => 70,
        'box_number' => '1',
        'document_identifier' => 'DOC-2ROW-OK',
        'document_type' => 'DEED',
        'series' => 'REG',
    ];
    $c1 = array_combine(array_keys($row1), array_keys($row1));
    (new AccessionRowImporter($imp, $c1, []))($row1);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-2ROW-OK')->exists())->toBeTrue();

    // Row 2 — forbidden.
    EntityResolver::flushMemo();
    $row2 = [
        'accession_number' => 'ACC-2ROW-FAIL',
        'batch_number' => 36,   // FORBIDDEN
        'box_number' => '1',
        'document_identifier' => 'DOC-2ROW-FAIL',
        'document_type' => 'DEED',
        'series' => 'REG',
    ];
    $c2 = array_combine(array_keys($row2), array_keys($row2));

    expect(fn () => (new AccessionRowImporter($imp, $c2, []))($row2))->toThrow(ValidationException::class);

    // Row 2 document and accession must not exist.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-2ROW-FAIL')->exists())->toBeFalse();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-2ROW-FAIL')->exists())->toBeFalse();
    // Row 1 must still be intact.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-2ROW-OK')->exists())->toBeTrue();
});
