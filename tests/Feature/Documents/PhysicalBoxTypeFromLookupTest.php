<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Document;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-13: "Can we add Conservation Box as an option? Am I able to
 * edit these?" She had added it on the Physical Box Type page, but a document
 * checked a fixed list of three types (RAS Box, Big Brown Box, Small Brown
 * Box), so it could not carry it. The page is now the list.
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
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
    CurrentBoxType::query()->updateOrCreate(['code' => 'Conservation Box'], ['label' => 'Conservation Box', 'is_active' => true, 'sort_order' => 4, 'counts_as' => 1]);
});

function pbt_doc(string $identifier, string $boxType): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier, 'series_id' => test()->series->id,
        'repository_id' => test()->repo->id, 'current_box_type' => $boxType,
    ]);
}

it('lets a document carry a type added on the Physical Box Type page', function (): void {
    expect(pbt_doc('PBT-1', 'conservation box')->current_box_type)->toBe('Conservation Box');
});

it('still refuses a type the page does not list', function (): void {
    expect(fn () => pbt_doc('PBT-2', 'Shoe Box'))->toThrow(DomainException::class, "Invalid current_box_type 'Shoe Box'");
});

it('imports a document with that type in its Current Box column', function (): void {
    $row = ['Document Identifier' => 'PBT-3', 'Subseries' => 'REG', 'Current Box' => 'Conservation Box'];
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $this->user->id,
    ]);
    (new DocumentImporter($import, ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($row)), []))($row);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'PBT-3')->value('current_box_type'))->toBe('Conservation Box');
});
