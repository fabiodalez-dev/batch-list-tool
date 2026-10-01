<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * RFQ App.2 §iii "Box History": the documents sheet carries a Destroyed column
 * for each historical box of the row — "RAS 1 Box Destroyed", "RAS 2 Box
 * Destroyed", "In Situ Box 1/2/3 Destroyed". The client's own sheet
 * (2026-09-29) spells two of them "Box 2 Destroyed" and "In Situ Box 3
 * Destroyed". Until now the import dropped them without a word.
 *
 * Driven through the real entry point, with the client's full header set so
 * every field matches its own header (see BoxMovementLegacyTimelineTest).
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
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    foreach (['1', '2'] as $n) {
        Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => $n, 'repository_id' => $this->repo->id]);
    }
});

/**
 * The client's 2026-09-29 header row, values overridden per test.
 *
 * @param array<string, string> $over
 * @return array<string, string>
 */
function hbd_row(array $over): array
{
    return array_merge([
        'Document Identifier' => '', 'Series' => 'REG',
        'RAS Batch 1' => '', 'RAS Box 1' => '', 'RAS Batch 2' => '', 'RAS Box 2' => '',
        'In Situ Box 1' => '', 'In Situ Box 2' => '', 'In Situ Box 3' => '',
        'Box 2 Destroyed' => '', 'In Situ Box 3 Destroyed' => '',
    ], $over);
}

function hbd_import(array $row, int $userId): void
{
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($row));
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($import, $map, []))($row);
}

function hbd_box(string $type, string $number): ?Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_type', $type)->where('box_number', $number)->first();
}

it('recognises the client\'s two Destroyed headers instead of dropping them', function (): void {
    $headers = array_keys(hbd_row([]));
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    expect($map['ras_box_2_destroyed'])->toBe('Box 2 Destroyed')
        ->and($map['in_situ_box_3_destroyed'])->toBe('In Situ Box 3 Destroyed')
        ->and(ImportWizard::unrecognisedHeaders($headers, $map))->not->toContain('Box 2 Destroyed', 'In Situ Box 3 Destroyed');
});

it('marks RAS Box 2 destroyed from "Box 2 Destroyed", and leaves RAS Box 1 alone', function (): void {
    hbd_import(hbd_row([
        'Document Identifier' => 'HBD-1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '101',
        'RAS Batch 2' => '2', 'RAS Box 2' => '202',
        'Box 2 Destroyed' => 'Yes',
    ]), $this->user->id);

    $ras2 = hbd_box('RAS', '202');
    expect($ras2)->not->toBeNull()
        ->and($ras2->destroyed_at)->not->toBeNull()
        ->and($ras2->destroyed_reason)->toContain('HBD-1')
        ->and($ras2->destroyed_by_user_id)->toBe($this->user->id)
        ->and(hbd_box('RAS', '101')?->destroyed_at)->toBeNull();
});

it('takes a date in an In Situ Destroyed column as the date of destruction', function (): void {
    hbd_import(hbd_row([
        'Document Identifier' => 'HBD-2',
        'RAS Batch 1' => '1', 'RAS Box 1' => '103',
        'In Situ Box 3' => 'NRA 33',
        'In Situ Box 3 Destroyed' => '2024-03-06',
    ]), $this->user->id);

    expect(hbd_box('NRA', '33')?->destroyed_at?->toDateString())->toBe('2024-03-06');
});

it('marks a lone RAS Box 1 destroyed even though the row builds no box chain', function (): void {
    hbd_import(array_merge(hbd_row([
        'Document Identifier' => 'HBD-3',
        'RAS Batch 1' => '1', 'RAS Box 1' => '104',
    ]), ['RAS 1 Box Destroyed' => 'Yes']), $this->user->id);

    expect(hbd_box('RAS', '104')?->destroyed_at)->not->toBeNull();
});

it('does nothing for a No, and keeps the first date when a box is declared destroyed twice', function (): void {
    hbd_import(hbd_row([
        'Document Identifier' => 'HBD-4',
        'RAS Batch 1' => '1', 'RAS Box 1' => '105', 'RAS Batch 2' => '2', 'RAS Box 2' => '205',
        'Box 2 Destroyed' => 'No',
    ]), $this->user->id);
    expect(hbd_box('RAS', '205')?->destroyed_at)->toBeNull();

    hbd_import(hbd_row([
        'Document Identifier' => 'HBD-5',
        'RAS Batch 1' => '1', 'RAS Box 1' => '106', 'RAS Batch 2' => '2', 'RAS Box 2' => '205',
        'Box 2 Destroyed' => '2023-01-15',
    ]), $this->user->id);
    hbd_import(hbd_row([
        'Document Identifier' => 'HBD-6',
        'RAS Batch 1' => '1', 'RAS Box 1' => '107', 'RAS Batch 2' => '2', 'RAS Box 2' => '205',
        'Box 2 Destroyed' => '2025-12-31',
    ]), $this->user->id);

    expect(hbd_box('RAS', '205')?->destroyed_at?->toDateString())->toBe('2023-01-15')
        ->and(Document::withoutGlobalScopes()->whereIn('identifier', ['HBD-4', 'HBD-5', 'HBD-6'])->count())->toBe(3);
});
