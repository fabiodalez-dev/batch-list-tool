<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Document;
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
 * Client, 2026-10-12: "If I put the authority identifier do I need to write
 * the creator? Isn't it already inputted in the authorities?" — it is, but an
 * Authority Identifier that matched no authority was skipped in silence: the
 * row read as imported and the document had no notary. It now fails the row
 * and names the code.
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
    $this->authority = Authority::create(['identifier' => 'R123', 'surname' => 'Abela', 'entity_type' => 'PERSON']);
});

/** @param array<string,string|null> $row */
function uai_import(array $row, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($import, ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($row)), []))($row);
}

function uai_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

it('links the document to the authority its identifier names', function (): void {
    uai_import(['Document Identifier' => 'UAI-1', 'Subseries' => 'REG', 'Authority Identifier' => 'R123'], $this->user->id);

    expect(uai_doc('UAI-1')->authorities()->pluck('authorities.id')->all())->toBe([$this->authority->id]);
});

it('fails the row when the Authority Identifier matches no authority, naming it', function (): void {
    expect(fn () => uai_import(['Document Identifier' => 'UAI-2', 'Subseries' => 'REG', 'Authority Identifier' => 'R999'], $this->user->id))
        ->toThrow(ValidationException::class, "Authority Identifier 'R999' matches no authority");

    expect(uai_doc('UAI-2'))->toBeNull();
});

it('names every missing code of a ";" list, even when one of them matches', function (): void {
    expect(fn () => uai_import(['Document Identifier' => 'UAI-3', 'Subseries' => 'REG', 'Authority Identifier' => 'R123; R998; R999'], $this->user->id))
        ->toThrow(ValidationException::class, "Authority Identifiers 'R998', 'R999' match no authority");
});

it('still imports a document with the Authority Identifier left empty', function (): void {
    uai_import(['Document Identifier' => 'UAI-4', 'Subseries' => 'REG', 'Authority Identifier' => ''], $this->user->id);

    expect(uai_doc('UAI-4'))->not->toBeNull();
});

it('treats "Unknown" as no notary, not as a missing code', function (): void {
    uai_import(['Document Identifier' => 'UAI-5', 'Subseries' => 'REG', 'Authority Identifier' => 'Unknown'], $this->user->id);
    uai_import(['Document Identifier' => 'UAI-6', 'Subseries' => 'REG', 'Authority Identifier' => 'R123; unknown'], $this->user->id);

    expect(uai_doc('UAI-5')->authorities()->count())->toBe(0)
        ->and(uai_doc('UAI-6')->authorities()->pluck('authorities.id')->all())->toBe([$this->authority->id]);
});
