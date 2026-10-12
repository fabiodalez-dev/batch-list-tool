<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-12: two NRA boxes, one in batch "Unknown" and one in batch
 * "NULL"; both landed in batch "NULL". The batch column cast "Unknown" to the
 * integer 0, and MySQL compares a string column with 0 numerically, so it
 * matched every non-numeric batch and took the first. Batch labels are now
 * kept as text and compared as text, in the box, document and accession
 * imports.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    // Same order as production: "NULL" (id 50) was created before "Unknown".
    $this->null = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 'NULL', 'repository_id' => $this->repo->id]);
    $this->unknown = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 'Unknown', 'repository_id' => $this->repo->id]);
    $this->b19 = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '19', 'repository_id' => $this->repo->id]);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

/** @param array<string,string|null> $row */
function bli_import(string $importer, array $row, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new $importer($import, ImportWizard::guessColumnMap($importer, array_keys($row)), []))($row);
}

function bli_box(string $number): Box
{
    return Box::withoutGlobalScopes()->where('box_type', 'NRA')->where('box_number', $number)->firstOrFail();
}

it('puts the box of batch "Unknown" in batch "Unknown", and the box of batch "NULL" in batch "NULL"', function (): void {
    foreach (['Unknown', 'NULL'] as $label) {
        bli_import(BoxImporter::class, [
            'Box type' => 'NRA', 'Box number' => $label, 'Batch number' => $label,
            'Is legacy' => 'Yes', 'Provenance Unknown' => 'Yes',
        ], $this->user->id);
    }

    expect(bli_box('Unknown')->batch_id)->toBe($this->unknown->id)
        ->and(bli_box('NULL')->batch_id)->toBe($this->null->id);
});

it('still reads a numeric batch cell as the number', function (): void {
    bli_import(BoxImporter::class, [
        'Box type' => 'NRA', 'Box number' => '7', 'Batch number' => '19.0',
        'Is legacy' => 'Yes', 'Provenance Unknown' => 'Yes',
    ], $this->user->id);

    expect(bli_box('7')->batch_id)->toBe($this->b19->id);
});

it('puts a document of RAS Batch 1 "Unknown" in batch "Unknown"', function (): void {
    bli_import(DocumentImporter::class, ['Document Identifier' => 'BLI-1', 'Subseries' => 'REG', 'RAS Batch 1' => 'Unknown'], $this->user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'BLI-1')->firstOrFail();
    expect($doc->batch_id)->toBe($this->unknown->id)
        ->and($doc->ras_batch_1)->toBe('Unknown');
});

it('resolves a batch label as text, and a number with its reserved-number rule', function (): void {
    expect(EntityResolver::resolveBatch('Unknown', $this->repo->id)['batch_id'])->toBe($this->unknown->id)
        ->and(EntityResolver::resolveBatch('NULL', $this->repo->id)['batch_id'])->toBe($this->null->id)
        ->and(EntityResolver::resolveBatch(0, $this->repo->id))->toBeNull()
        ->and(EntityResolver::resolveBatch('19', $this->repo->id)['batch_id'])->toBe($this->b19->id)
        ->and(EntityResolver::resolveBatch('34', $this->repo->id))->toBe(['forbidden' => 34])
        ->and(SpreadsheetParsers::parseBatchNumber('Unknown'))->toBe('Unknown')
        ->and(SpreadsheetParsers::parseBatchNumber(19.0))->toBe('19')
        ->and(SpreadsheetParsers::parseBatchNumber('  '))->toBeNull();
});
