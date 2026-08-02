<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F3 — when an imported document resolves a barcode status but has NO current
 * box, the status must NOT be silently dropped: it is written directly to the
 * document column as a fallback, while A1.2 (PERM_OUT needs a disinfestation
 * date) is still enforced.
 *
 * Drives the real importer end-to-end on one row, like AccessionIntegrityTest.
 * $data is keyed by importer FIELD name (identity column map). No batch / box
 * columns are supplied → the document has no current box.
 */
function sfb_makeAdmin(int $repoId): User
{
    $u = User::factory()->create(['default_repository_id' => $repoId]);
    $u->assignRole('admin');

    return $u;
}

function sfb_runRow(array $data, int $userId): void
{
    EntityResolver::flushMemo();

    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'status_fallback.xlsx',
        'file_path' => '/tmp/status_fallback.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    $importer = new DocumentImporter($row, $columnMap, []);
    $importer($data);
}

it('writes a resolved status to the document column when there is no box (OUT)', function (): void {
    $repo = Repository::factory()->create();
    $u = sfb_makeAdmin($repo->id);
    $this->actingAs($u);
    Series::factory()->create(['code' => 'R']);

    sfb_runRow([
        'identifier' => 'NOBOX-OUT-1',
        'series' => 'R',
        'status_1' => 'OUT',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'NOBOX-OUT-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->toBeNull()
        // Status landed on the document column, not dropped.
        ->and($doc->barcode_status)->toBe('OUT');
});

it('writes PERM_OUT to the document column with no box and no disinfestation date (loosened, client feedback 2026-08-01)', function (): void {
    $repo = Repository::factory()->create();
    $u = sfb_makeAdmin($repo->id);
    $this->actingAs($u);
    Series::factory()->create(['code' => 'R']);

    // RFQ App.1 #5 was loosened after client feedback (Charlene Ellul,
    // 2026-08-01): legacy NAF documents are frequently PERM_OUT with no
    // disinfestation date and must import as-is. Client feedback supersedes the
    // RFQ because it is later. The row is now accepted and persisted.
    sfb_runRow([
        'identifier' => 'NOBOX-PERM-1',
        'series' => 'R',
        'status_1' => 'PERM_OUT',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'NOBOX-PERM-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->toBeNull()
        ->and($doc->barcode_status)->toBe('PERM_OUT')
        ->and($doc->disinfestation_date)->toBeNull();
});

it('writes PERM_OUT to the document column when there is no box but a date is present (A1.2 satisfied)', function (): void {
    $repo = Repository::factory()->create();
    $u = sfb_makeAdmin($repo->id);
    $this->actingAs($u);
    Series::factory()->create(['code' => 'R']);

    sfb_runRow([
        'identifier' => 'NOBOX-PERM-2',
        'series' => 'R',
        'status_1' => 'PERM_OUT',
        'disinfestation_date' => '2026-01-10',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'NOBOX-PERM-2')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->toBeNull()
        ->and($doc->barcode_status)->toBe('PERM_OUT');
});
