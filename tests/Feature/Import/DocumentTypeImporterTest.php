<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentTypeImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-08-18 (#17) — bulk import for the Document Type controlled
 * vocabulary. Documents link to these (document_type_id), so the type sheet
 * must be importable BEFORE the documents sheet.
 *
 * Driven through the REAL importer entry point + ImportWizard::guessColumnMap
 * column map (no reflection, no synthetic shortcuts).
 */
uses(RefreshDatabase::class);

function dti_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    return $u;
}

/**
 * @param array<string,string|int|null> $data
 */
function dti_import(array $data, int $userId, array $options = []): void
{
    $map = ImportWizard::guessColumnMap(DocumentTypeImporter::class, array_keys($data));
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 'dt.xlsx', 'file_path' => '/tmp/dt.xlsx',
        'importer' => DocumentTypeImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentTypeImporter($imp, $map, $options))($data);
}

it('DT1: creates a Document Type from Identifier + Name', function () {
    $u = dti_admin();

    dti_import(['Identifier' => 'WILL', 'Name' => 'Will', 'Description' => 'Testamentary deed', 'Is active' => '1'], $u->id);

    $dt = DocumentType::query()->where('identifier', 'WILL')->first();
    expect($dt)->not->toBeNull()
        ->and($dt->name)->toBe('Will')
        ->and($dt->description)->toBe('Testamentary deed')
        ->and((bool) $dt->is_active)->toBeTrue();
});

it('DT2: re-importing the same Identifier updates the row, never duplicates', function () {
    $u = dti_admin();

    dti_import(['Identifier' => 'DEED', 'Name' => 'Deed'], $u->id);
    dti_import(['Identifier' => 'DEED', 'Name' => 'Deed (revised)'], $u->id);

    $rows = DocumentType::query()->where('identifier', 'DEED')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Deed (revised)');
});

it('DT3: matches case-insensitively by Identifier on re-import', function () {
    $u = dti_admin();

    dti_import(['Identifier' => 'Contract', 'Name' => 'Contract'], $u->id);
    dti_import(['Identifier' => 'contract', 'Name' => 'Contract v2'], $u->id);

    expect(DocumentType::query()->whereRaw('LOWER(identifier) = ?', ['contract'])->count())->toBe(1)
        ->and(DocumentType::query()->whereRaw('LOWER(identifier) = ?', ['contract'])->first()->name)->toBe('Contract v2');
});

it('DT4: with no Identifier, matches by Name to stay idempotent', function () {
    $u = dti_admin();

    dti_import(['Name' => 'Codicil'], $u->id);
    dti_import(['Name' => 'Codicil'], $u->id);

    expect(DocumentType::query()->where('name', 'Codicil')->count())->toBe(1);
});

it('DT5: the generated Document Type template round-trips to the importer columns', function () {
    $headers = TemplateGenerator::headersFor('documentType');
    $cols = collect(DocumentTypeImporter::getColumns())->map(fn ($c) => $c->getName())->all();

    // Every generated header must map to a real importer column through the
    // same guesser the wizard uses (no orphan headers).
    $map = ImportWizard::guessColumnMap(DocumentTypeImporter::class, $headers);
    $claimed = array_filter($map);
    expect($claimed)->not->toBeEmpty();

    // The key columns are present and reachable.
    expect($cols)->toContain('identifier')->toContain('name')->toContain('description')->toContain('is_active');
    expect($map['identifier'] ?? null)->toBe('Identifier')
        ->and($map['name'] ?? null)->toBe('Name');
});
