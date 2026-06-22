<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * NAF Feedback-1 DECISION 5 — `part_number` must be importable through the
 * mass-import path (DocumentImporter), not only the bottom-up AccessionRowImporter.
 */
uses(RefreshDatabase::class);

function pn_runDocImporter(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'doc.xlsx',
        'file_path' => '/tmp/doc.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $columnMap = array_combine(array_keys($data), array_keys($data));
    (new DocumentImporter($row, $columnMap, []))($data);
}

it('imports part_number through the DocumentImporter', function () {
    foreach (['super_admin'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::create(['code' => 'PN', 'name' => 'Part Number Repo']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    Series::create(['code' => 'REG', 'title' => 'Registers']);

    pn_runDocImporter([
        'identifier' => 'DOC-PN-1',
        'series' => 'REG',
        'document_type' => 'Register Volume',
        'part_number' => '2A',
    ], $u->id);

    $doc = Document::query()->where('identifier', 'DOC-PN-1')->firstOrFail();
    expect($doc->part_number)->toBe('2A');
});
