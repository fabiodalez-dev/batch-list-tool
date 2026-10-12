<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-12: "Those documents which are not in a box, how should I
 * handle them? So no batch and box or NRA boxes."
 *
 * Such a row leaves every box column blank and gives the document's own NRA
 * Location. It used to import as "In box" (the column default); it now
 * imports as "Not in box", and an optional Custody Status column takes
 * "In box", "Not in box" or "Mounted; no box".
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
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

/** @param array<string,string|null> $data */
function dcs_import(array $data, int $userId): void
{
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($imp, $map, []))($data);
}

function dcs_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

it('imports a document in no box as "Not in box", at its own NRA Location', function (): void {
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'REPO-1-45', 'name' => 'Repository 1 — Shelf 45', 'type' => 'repository',
        'is_active' => true, 'repository_id' => $this->repo->id,
    ]);

    dcs_import([
        'Document Identifier' => 'DCS-1', 'Subseries' => 'REG',
        'RAS Batch 1' => '', 'RAS Box 1' => '', 'In Situ Box 1' => '',
        'NRA Location' => 'REPO-1-45',
    ], $this->user->id);

    $doc = dcs_doc('DCS-1');
    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->toBeNull()
        ->and($doc->custody_status)->toBe('not_in_box')
        ->and($doc->location_id)->toBe($loc->id);
});

it('imports a document in a RAS box as "In box"', function (): void {
    dcs_import(['Document Identifier' => 'DCS-2', 'Subseries' => 'REG', 'RAS Batch 1' => '19', 'RAS Box 1' => '4'], $this->user->id);

    $doc = dcs_doc('DCS-2');
    expect($doc->current_box_id)->not->toBeNull()
        ->and($doc->custody_status)->toBe('in_box');
});

it('imports a document only in an In Situ box as "In box"', function (): void {
    dcs_import(['Document Identifier' => 'DCS-3', 'Subseries' => 'REG', 'In Situ Box 1' => 'NRA512'], $this->user->id);

    $doc = dcs_doc('DCS-3');
    expect($doc->current_box_id)->not->toBeNull()
        ->and($doc->custody_status)->toBe('in_box');
});

it('takes the Custody Status column when it is filled', function (): void {
    expect(TemplateGenerator::DOCUMENT_HEADERS)->toContain('Custody Status');

    dcs_import(['Document Identifier' => 'DCS-4', 'Subseries' => 'REG', 'Custody Status' => 'Mounted; no box'], $this->user->id);
    dcs_import(['Document Identifier' => 'DCS-5', 'Subseries' => 'REG', 'Custody Status' => 'not in box'], $this->user->id);

    expect(dcs_doc('DCS-4')->custody_status)->toBe('mounted_no_box')
        ->and(dcs_doc('DCS-5')->custody_status)->toBe('not_in_box');
});

it('keeps a mounted document mounted when it is imported again without the column', function (): void {
    dcs_import(['Document Identifier' => 'DCS-6', 'Subseries' => 'REG', 'Custody Status' => 'Mounted'], $this->user->id);
    dcs_import(['Document Identifier' => 'DCS-6', 'Subseries' => 'REG', 'Note' => 'second pass'], $this->user->id);

    expect(dcs_doc('DCS-6')->custody_status)->toBe('mounted_no_box');
});

it('refuses a Custody Status it does not know, naming the value', function (): void {
    expect(fn () => dcs_import(['Document Identifier' => 'DCS-7', 'Subseries' => 'REG', 'Custody Status' => 'On loan'], $this->user->id))
        ->toThrow(ValidationException::class, "Custody Status 'On loan'");
});
