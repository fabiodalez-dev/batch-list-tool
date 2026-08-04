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
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Slice 2 — Location import on the document (client feedback 2026-08-04). The
 * document's own location (documents.location_id, the two-level override) is now
 * importable, code-resolved exactly like BoxImporter. Charlene chose the code
 * (not free text) for consistency with the deduplicated location lookup. These
 * tests drive the real DocumentImporter entry point.
 */
uses(RefreshDatabase::class);

function dli_setup(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);

    return [$repo, $u];
}

/** @param array<string,string|null> $data */
function dli_import(array $data, int $userId): void
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

it('resolves a known Location code onto documents.location_id', function () {
    [$repo, $u] = dli_setup();
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'SHELF-A3', 'name' => 'Shelf A3', 'type' => 'repository',
        'is_active' => true, 'repository_id' => $repo->id,
    ]);

    dli_import([
        'Identifier' => 'DOC-LOC-1',
        'Series' => 'REG',
        'Location' => 'SHELF-A3',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-LOC-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->location_id)->toBe($loc->id);
});

it('fails the row on an unknown Location code (per-row, like the box import)', function () {
    [$repo, $u] = dli_setup();

    expect(fn () => dli_import([
        'Identifier' => 'DOC-LOC-2',
        'Series' => 'REG',
        'Location' => 'DOES-NOT-EXIST',
    ], $u->id))->toThrow(ValidationException::class);

    // Nothing persisted for the failed row.
    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-LOC-2')->exists()
    )->toBeFalse();
});

it('leaves location_id null when the Location cell is blank (inherits the box)', function () {
    [$repo, $u] = dli_setup();

    dli_import([
        'Identifier' => 'DOC-LOC-3',
        'Series' => 'REG',
        'Location' => '',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-LOC-3')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->location_id)->toBeNull();
});

it('case-insensitively resolves the Location code', function () {
    [$repo, $u] = dli_setup();
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'SHELF-B7', 'name' => 'Shelf B7', 'type' => 'repository',
        'is_active' => true, 'repository_id' => $repo->id,
    ]);

    dli_import([
        'Identifier' => 'DOC-LOC-4',
        'Series' => 'REG',
        'Location' => 'shelf-b7',
    ], $u->id);

    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-LOC-4')->value('location_id')
    )->toBe($loc->id);
});
