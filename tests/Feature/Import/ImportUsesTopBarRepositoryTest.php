<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
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
 * Client, 2026-10-13: "Isn't the repository selected from the top right
 * corner?" — all 26,867 rows of her documents sheet failed with "Select a
 * repository before importing documents" while "Notarial Registers Archive"
 * was selected at the top. The wizard passes the top-bar repository to the
 * import, but only the box import read it; the document import looked at the
 * user's default repository (none: "All repositories") through auth(), which
 * is empty in the queued job anyway.
 *
 * Run as the queue does: no signed-in user.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'Notarial Registers Archive']);
    // Like the client: no default repository.
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => null]);
    $this->user->assignRole('super_admin');
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

/** @param array<string, mixed> $options */
function itr_import(array $row, int $userId, array $options): void
{
    auth()->logout();
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($import, ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($row)), $options))($row);
}

it('imports into the repository selected in the top bar, and the document carries it', function (): void {
    itr_import(['Document Identifier' => 'ITR-1', 'Subseries' => 'REG'], $this->user->id, ['repository_id' => $this->repo->id]);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'ITR-1')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->repository_id)->toBe($this->repo->id);
});

it('falls back to the importing user\'s default repository, read from the import, not the session', function (): void {
    $this->user->update(['default_repository_id' => $this->repo->id]);

    itr_import(['Document Identifier' => 'ITR-2', 'Subseries' => 'REG'], $this->user->id, []);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'ITR-2')->value('repository_id'))->toBe($this->repo->id);
});

it('still refuses the row when neither says which repository', function (): void {
    expect(fn () => itr_import(['Document Identifier' => 'ITR-3', 'Subseries' => 'REG'], $this->user->id, []))
        ->toThrow(ValidationException::class, 'Select a repository before importing documents');
});

it('keeps the dates of all three Disinfestation Date columns', function (): void {
    // Client sheet 2026-10-13: the 2nd and 3rd columns (23 and 249 dates)
    // were dropped. Headers as the reader names the repeats.
    itr_import([
        'Document Identifier' => 'ITR-4', 'Subseries' => 'REG',
        'Disinfestation Date' => '05/08/2023', 'Disinfestation Date (2)' => '24/10/2024', 'Disinfestation Date (3)' => '27/07/2026',
    ], $this->user->id, ['repository_id' => $this->repo->id]);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'ITR-4')->firstOrFail();
    expect($doc->disinfestation_date?->format('Y-m-d'))->toBe('2023-08-05')
        ->and($doc->disinfestation_date_1?->format('Y-m-d'))->toBe('2023-08-05')
        ->and($doc->disinfestation_date_2?->format('Y-m-d'))->toBe('2024-10-24')
        ->and($doc->disinfestation_date_3?->format('Y-m-d'))->toBe('2026-07-27');
});
